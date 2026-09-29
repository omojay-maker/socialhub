<?php
require_once __DIR__ . '/AbstractSocialProvider.php';

/**
 * Instagram Graph API (Business/Creator accounts linked to a Facebook Page).
 *
 * Instagram reuses the Meta app for authorization; the Page token obtained
 * during the exchange is what unlocks the IG user id.
 *
 * Required env:
 *   INSTAGRAM_CLIENT_ID, INSTAGRAM_CLIENT_SECRET, INSTAGRAM_REDIRECT_URI
 *   (falls back to the FACEBOOK_* values when left blank, which is the usual
 *    single-app setup)
 */
class InstagramProvider extends AbstractSocialProvider {
    const SCOPES = [
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_insights',
        'pages_show_list',
        'pages_read_engagement',
    ];

    public function __construct() { parent::__construct('instagram', 'Instagram'); }

    public function isConfigured(): bool {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    private function version(): string { return (string)env_get('INSTAGRAM_API_VERSION', 'v21.0'); }
    private function clientId(): string {
        return (string)(env_get('INSTAGRAM_CLIENT_ID') ?: env_get('FACEBOOK_CLIENT_ID'));
    }
    private function clientSecret(): string {
        return (string)(env_get('INSTAGRAM_CLIENT_SECRET') ?: env_get('FACEBOOK_CLIENT_SECRET'));
    }
    public function redirectUri(): string {
        $uri = (string)(env_get('INSTAGRAM_REDIRECT_URI') ?: env_get('FACEBOOK_REDIRECT_URI') ?: '');
        return $uri !== '' ? $uri : $this->redirectUriFor('INSTAGRAM_REDIRECT_URI');
    }

    public function authUrl(string $state, array $extra = []): string {
        $params = [
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $state,
            'response_type' => 'code',
            'scope'         => implode(',', array_unique(array_merge(self::SCOPES, $extra['scopes'] ?? []))),
        ];
        return 'https://www.facebook.com/' . $this->version() . '/dialog/oauth?' . http_build_query($params);
    }

    public function exchange(string $code, array $extra = []): array {
        $res = http_json('GET', 'https://graph.facebook.com/' . $this->version() . '/oauth/access_token?' . http_build_query([
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri'  => $this->redirectUri(),
            'code'          => $code,
        ]));
        if (!$res['ok']) {
            throw new RuntimeException(describe_api_error($res['body'] ?? $res['error'] ?? 'Instagram authorization failed'));
        }
        $body = $res['body'];
        $short = (string)($body['access_token'] ?? '');
        if ($short === '') throw new RuntimeException('Instagram did not return an access token');

        $long = http_json('GET', 'https://graph.facebook.com/' . $this->version() . '/oauth/access_token?' . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->clientId(),
            'client_secret'     => $this->clientSecret(),
            'fb_exchange_token' => $short,
        ]));
        $token = ($long['ok'] && !empty($long['body']['access_token'])) ? (string)$long['body']['access_token'] : $short;

        return [
            'token'      => $token,
            'refresh'    => $token,       // reuse: the long-lived token can be re-minted
            'expires_in' => (int)($long['body']['expires_in'] ?? $body['expires_in'] ?? 0) ?: null,
            'scopes'     => implode(',', self::SCOPES),
            'raw'        => $body,
        ];
    }

    public function refresh(?string $refreshToken, ?string $accessToken = null): array {
        $seed = $refreshToken ?: $accessToken;
        if (!$seed) return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>[]];
        $res = http_json('GET', 'https://graph.facebook.com/' . $this->version() . '/oauth/access_token?' . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->clientId(),
            'client_secret'     => $this->clientSecret(),
            'fb_exchange_token' => $seed,
        ]));
        if (!$res['ok'] || empty($res['body']['access_token'])) {
            return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>$res['body'] ?? []];
        }
        $token = (string)$res['body']['access_token'];
        return ['token'=>$token,'refresh'=>$token,'expires_in'=>(int)($res['body']['expires_in'] ?? 0) ?: null,'raw'=>$res['body']];
    }

    public function revokeToken(string $token): void {
        http_json('DELETE', 'https://graph.facebook.com/' . $this->version() . '/me/permissions?access_token=' . urlencode($token));
    }

    /** Resolves the Instagram user id for a Page token. */
    private function igUser(string $pageToken): array {
        $pages = http_json('GET', 'https://graph.facebook.com/' . $this->version() . '/me/accounts?' . http_build_query([
            'fields'       => 'id,name,access_token,instagram_business_account{id,username,name,followers_count,profile_picture_url}',
            'access_token' => $pageToken,
        ]));
        if (!$pages['ok']) throw new RuntimeException(describe_api_error($pages['body'] ?? $pages['error'] ?? 'Cannot list pages'));
        foreach ($pages['body']['data'] ?? [] as $page) {
            if (!empty($page['instagram_business_account'])) {
                return [$page, $page['instagram_business_account'], (string)($page['access_token'] ?? $pageToken)];
            }
        }
        throw new RuntimeException('No Instagram Business or Creator account is linked to any of your Pages.');
    }

    public function profile(array $credentials, array $context = []): array {
        $token = (string)($credentials['token'] ?? '');
        if ($token === '') throw new RuntimeException('Missing Instagram token');

        [$page, $ig, $pageToken] = $this->igUser($token);

        $details = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$ig['id']}?" . http_build_query([
            'fields'       => 'id,username,name,biography,followers_count,media_count,profile_picture_url,website',
            'access_token' => $pageToken,
        ]));
        $d = $details['ok'] ? ($details['body'] ?? []) : $ig;

        return [
            'account_name'        => (string)($d['name'] ?? $d['username'] ?? 'Instagram'),
            'username'            => isset($d['username']) ? '@' . $d['username'] : null,
            'account_type'        => 'business',
            'external_account_id' => (string)$ig['id'],
            'external_user_id'    => isset($credentials['raw']['user_id']) ? (string)$credentials['raw']['user_id'] : null,
            'avatar_url'          => $d['profile_picture_url'] ?? null,
            'followers'           => (int)($d['followers_count'] ?? 0),
            'token'               => $pageToken,
            'refresh'             => (string)($credentials['refresh'] ?? $pageToken),
            'expires_in'          => $credentials['expires_in'] ?? null,
            'scopes'              => $credentials['scopes'] ?? null,
            'meta'                => [
                'ig_user_id' => (string)$ig['id'],
                'page_id'    => (string)$page['id'],
                'page_name'  => $page['name'] ?? null,
                'user_token' => $token,
            ],
        ];
    }

    /* --- publishing: container -> poll -> publish ---------------------- */

    public function publishPost(int $accountId, array $postData): array {
        if (!$this->budget('publish', 25, 300)) return $this->fail('Instagram rate budget exhausted, try again shortly');
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return $this->notConnected((string)$res['error']);
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $igId = (string)($meta['ig_user_id'] ?? '');
        if ($igId === '') return $this->fail('No Instagram account id stored. Reconnect the account.');

        $caption  = trim((string)($postData['content'] ?? ''));
        $mediaUrl = $this->mediaUrlForPost($postData);
        if ($mediaUrl === '') {
            return $this->fail('Instagram requires an image or video attachment');
        }

        $isVideo = (bool)preg_match('/\.(mp4|mov|avi|webm)$/i', parse_url($mediaUrl, PHP_URL_PATH) ?: '');
        $params = ['caption' => $caption, 'access_token' => $res['token']];
        if ($isVideo) {
            $params['media_type'] = 'REELS';
            $params['video_url']  = $mediaUrl;
        } elseif (str_contains($caption, "\n---\n")) {
            // Swipe carousels: "\n---\n" separates the slides.
            $params['media_type'] = 'CAROUSEL_ALBUM';
            $params['children']   = implode(',', array_map('urlencode', $this->carouselUrls($postData)));
        } else {
            $params['image_url'] = $mediaUrl;
        }
        if (!$isVideo && $params['media_type'] ?? null) {
            // children must be sent as repeated params; rebuild the query.
            $query = http_build_query(array_diff_key($params, ['children' => 1]));
            $query .= '&children=' . implode('&children=', array_map('urlencode', $this->carouselUrls($postData)));
            $create = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$igId}/media?{$query}");
        } else {
            $create = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$igId}/media?" . http_build_query($params));
        }

        if (!$create['ok']) return $this->fail(describe_api_error($create['body'] ?? $create['error'] ?? 'Could not create the media container'));
        $container = (string)($create['body']['id'] ?? '');
        if ($container === '') return $this->fail('Instagram did not return a media container id');

        // Instagram processes media asynchronously.
        $ready = $this->waitForContainer($igId, $container, $res['token']);
        if (!$ready['ok']) return $this->fail($ready['error']);

        $publish = http_json('POST', "https://graph.facebook.com/{$this->version()}/{$igId}/media_publish?" . http_build_query([
            'creation_id'  => $container,
            'access_token' => $res['token'],
        ]));
        if (!$publish['ok']) return $this->fail(describe_api_error($publish['body'] ?? $publish['error']));
        return $this->ok((string)($publish['body']['id'] ?? $container));
    }

    private function carouselUrls(array $postData): array {
        $ids = $postData['media_ids'] ?? [];
        if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)));
        $urls = [];
        foreach (array_map('intval', (array)$ids) as $id) {
            $media = MediaModel::find($id);
            if ($media) $urls[] = media_url($media['filename'], true);
        }
        return $urls ?: [];
    }

    /** @return array{ok:bool,error:?string} */
    private function waitForContainer(string $igId, string $container, string $token, int $maxWait = 180): array {
        $deadline = time() + $maxWait;
        while (time() < $deadline) {
            $r = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$container}?" . http_build_query([
                'fields'       => 'status_code,status',
                'access_token' => $token,
            ]));
            $status = (string)($r['body']['status_code'] ?? '');
            if ($status === 'FINISHED') return ['ok'=>true,'error'=>null];
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                return ['ok'=>false,'error'=>'Instagram rejected the media: ' . (string)($r['body']['status'] ?? $status)];
            }
            if (!$r['ok'] && $r['status'] === 0) {
                return ['ok'=>false,'error'=>'Could not check media processing status'];
            }
            sleep(5);
        }
        return ['ok'=>false,'error'=>'Instagram took too long to process the media'];
    }

    public function getAccountInformation(int $accountId): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $igId = (string)($meta['ig_user_id'] ?? '');
        if ($igId === '') return ['error' => 'No Instagram account id stored'];

        $r = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$igId}?" . http_build_query([
            'fields'       => 'username,name,followers_count,media_count,profile_picture_url',
            'access_token' => $res['token'],
        ]));
        if (!$r['ok']) return ['error' => describe_api_error($r['body'] ?? $r['error'])];
        $followers = (int)($r['body']['followers_count'] ?? 0);
        SocialAccountModel::updateStats($accountId, $followers, [
            'ig_user_id' => $igId,
            'media_count' => (int)($r['body']['media_count'] ?? 0),
        ]);
        return [
            'followers' => $followers,
            'following' => null,
            'posts'     => (int)($r['body']['media_count'] ?? 0),
            'engagement'=> null,
            'demo'      => false,
        ];
    }

    public function getPosts(int $accountId, int $limit = 10): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return [];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $igId = (string)($meta['ig_user_id'] ?? '');
        if ($igId === '') return [];

        $r = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$igId}/media?" . http_build_query([
            'fields'       => 'id,caption,media_type,media_product_type,permalink,timestamp,like_count,comments_count,media_url,thumbnail_url',
            'limit'        => max(1, min(50, $limit)),
            'access_token' => $res['token'],
        ]));
        if (!$r['ok']) return [];
        $out = [];
        foreach ($r['body']['data'] ?? [] as $m) {
            $out[] = [
                'external_id'  => (string)$m['id'],
                'content'      => $m['caption'] ?? '',
                'published_at' => $m['timestamp'] ?? null,
                'permalink'    => $m['permalink'] ?? null,
                'image'        => $m['media_type'] === 'VIDEO' ? ($m['thumbnail_url'] ?? null) : ($m['media_url'] ?? null),
                'likes'        => (int)($m['like_count'] ?? 0),
                'comments'     => (int)($m['comments_count'] ?? 0),
                'shares'       => 0,
                'type'         => $m['media_product_type'] ?? $m['media_type'] ?? null,
            ];
        }
        return $out;
    }

    public function getAnalytics(int $accountId, string $from, string $to): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $igId = (string)($meta['ig_user_id'] ?? '');
        if ($igId === '') return ['error' => 'No Instagram account id stored'];

        $metrics = 'reach,total_interactions,likes,comments,shares,views,saved,profile_visits';
        $r = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$igId}/insights?" . http_build_query([
            'metric'       => $metrics,
            'period'       => 'day',
            'since'        => date('Y-m-d', strtotime($from)),
            'until'        => date('Y-m-d', strtotime($to)),
            'access_token' => $res['token'],
        ]));
        if (!$r['ok']) return ['error' => describe_api_error($r['body'] ?? $r['error'])];

        $series = [];
        $totals = ['reach'=>0,'impressions'=>0,'likes'=>0,'comments'=>0,'shares'=>0,'views'=>0,'saved'=>0,'profile_visits'=>0,'followers'=>(int)($res['account']['followers'] ?? 0)];
        foreach ($r['body']['data'] ?? [] as $row) {
            $name = (string)($row['name'] ?? '');
            $points = [];
            foreach ($row['values'] ?? [] as $v) {
                $end = (string)($v['end_time'] ?? '');
                $value = $v['value'] ?? 0;
                if (is_array($value)) $value = reset($value) ?: 0;
                $points[$end] = (int)$value;
                $totals[$name] = ($totals[$name] ?? 0) + (int)$value;
            }
            ksort($points);
            $series[$name] = $points;
        }
        $totals['impressions'] = $totals['reach'];
        return ['series'=>$series,'totals'=>$totals,'demo'=>false];
    }
}
