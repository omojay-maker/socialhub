<?php
require_once __DIR__ . '/AbstractSocialProvider.php';

/**
 * Facebook Pages + Instagram via the Meta Graph API.
 *
 * Flow: user authorises the app -> short-lived user token -> long-lived user
 * token -> Page access token. Publishing always uses the Page token.
 *
 * Required env:
 *   FACEBOOK_CLIENT_ID, FACEBOOK_CLIENT_SECRET, FACEBOOK_REDIRECT_URI
 * Optional: META_APP_SECRET (webhook signature verification)
 */
class FacebookProvider extends AbstractSocialProvider {
    const SCOPES = [
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'pages_manage_engagement',
        'read_insights',
    ];

    public function __construct() { parent::__construct('facebook', 'Facebook'); }

    public function isConfigured(): bool {
        return (string)env_get('FACEBOOK_CLIENT_ID') !== ''
            && (string)env_get('FACEBOOK_CLIENT_SECRET') !== '';
    }

    private function version(): string {
        return (string)env_get('FACEBOOK_GRAPH_VERSION', 'v21.0');
    }

    private function clientId(): string { return (string)env_get('FACEBOOK_CLIENT_ID'); }
    private function clientSecret(): string { return (string)env_get('FACEBOOK_CLIENT_SECRET'); }

    public function redirectUri(): string { return $this->redirectUriFor('FACEBOOK_REDIRECT_URI'); }

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
            throw new RuntimeException(is_string($res['error']) ? $res['error'] : describe_api_error($res['body'] ?? []));
        }
        $body = $res['body'];
        $short = (string)($body['access_token'] ?? '');
        if ($short === '') throw new RuntimeException('Facebook did not return an access token');

        // Exchange the short-lived token for a ~60 day long-lived token.
        $long = http_json('GET', 'https://graph.facebook.com/' . $this->version() . '/oauth/access_token?' . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->clientId(),
            'client_secret'     => $this->clientSecret(),
            'fb_exchange_token' => $short,
        ]));
        $token = $long['ok'] && is_array($long['body']) && !empty($long['body']['access_token'])
            ? (string)$long['body']['access_token']
            : $short;
        $expires = (int)($long['body']['expires_in'] ?? $body['expires_in'] ?? 0) ?: null;

        return [
            'token'      => $token,
            'refresh'    => null,          // Meta has no refresh token; we re-exchange
            'expires_in' => $expires,
            'scopes'     => implode(',', self::SCOPES),
            'raw'        => $body,
        ];
    }

    /** Facebook has no refresh token; a long-lived token is re-minted instead. */
    public function refresh(?string $refreshToken, ?string $accessToken = null): array {
        if (!$accessToken) return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>[]];
        $res = http_json('GET', 'https://graph.facebook.com/' . $this->version() . '/oauth/access_token?' . http_build_query([
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->clientId(),
            'client_secret'     => $this->clientSecret(),
            'fb_exchange_token' => $accessToken,
        ]));
        if (!$res['ok'] || empty($res['body']['access_token'])) {
            return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>$res['body'] ?? []];
        }
        return [
            'token'      => (string)$res['body']['access_token'],
            'refresh'    => null,
            'expires_in' => (int)($res['body']['expires_in'] ?? 0) ?: null,
            'raw'        => $res['body'],
        ];
    }

    public function revokeToken(string $token): void {
        http_json('DELETE', 'https://graph.facebook.com/' . $this->version() . '/me/permissions?access_token=' . urlencode($token));
    }

    /**
     * Reads the user's managed Pages and returns the first one, together with
     * its Page token (the credential used for publishing) and any linked
     * Instagram business account.
     */
    public function profile(array $credentials, array $context = []): array {
        $userToken = (string)($credentials['token'] ?? '');
        if ($userToken === '') throw new RuntimeException('Missing Facebook user token');

        $res = http_json('GET', 'https://graph.facebook.com/' . $this->version() . '/me/accounts?' . http_build_query([
            'fields'       => 'id,name,username,access_token,followers_count,picture{url},tasks,instagram_business_account{id,username,followers_count,profile_picture_url}',
            'limit'        => 100,
            'access_token' => $userToken,
        ]));
        if (!$res['ok']) throw new RuntimeException(describe_api_error($res['body'] ?? $res['error'] ?? 'Failed to list pages'));
        $pages = $res['body']['data'] ?? [];
        if (!$pages) throw new RuntimeException('No Facebook Page found. Create a Page and grant this app access to it.');

        // Prefer the page the user picked, otherwise the first non-task page.
        $wanted = (string)($context['external_account_id'] ?? '');
        $page = null;
        foreach ($pages as $p) {
            if ($wanted !== '' && (string)$p['id'] === $wanted) { $page = $p; break; }
        }
        if (!$page) {
            foreach ($pages as $p) { $page = $p; break; }
        }
        if (!empty($page['tasks'])) {
            throw new RuntimeException('That Page only has partial access. Reconnect and grant full control of the Page.');
        }

        $ig = $page['instagram_business_account'] ?? null;

        return [
            'account_name'        => (string)$page['name'],
            'username'            => $page['username'] ?? null,
            'account_type'        => 'page',
            'external_account_id' => (string)$page['id'],
            'external_user_id'    => isset($credentials['raw']['user_id']) ? (string)$credentials['raw']['user_id'] : null,
            'avatar_url'          => $page['picture']['data']['url'] ?? null,
            'followers'           => (int)($page['followers_count'] ?? 0),
            'token'               => (string)($page['access_token'] ?? $userToken),
            'refresh'             => (string)($credentials['refresh'] ?? $userToken),
            'expires_in'          => $credentials['expires_in'] ?? null,
            'scopes'              => $credentials['scopes'] ?? null,
            'meta'                => [
                'page_id' => (string)$page['id'],
                'page_token' => (string)($page['access_token'] ?? ''),
                'user_token' => $userToken,
                'instagram_id' => $ig ? (string)$ig['id'] : null,
                'instagram_username' => $ig['username'] ?? null,
            ],
        ];
    }

    /* --- publishing ---------------------------------------------------- */

    public function publishPost(int $accountId, array $postData): array {
        if (!$this->budget('publish', 50, 300)) return $this->fail('Facebook rate budget exhausted, try again shortly');
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return $this->notConnected((string)$res['error']);
        $account = $res['account'];
        $pageId  = (string)(json_decode((string)($account['meta'] ?? '{}'), true)['page_id'] ?? '');
        if ($pageId === '') return $this->fail('No Facebook Page id stored for this account');

        $message = trim((string)($postData['content'] ?? ''));
        $link    = trim((string)($postData['link'] ?? ''));
        $mediaId = $this->mediaUrlForPost($postData);

        try {
            if ($mediaId) {
                $r = http_json('POST', "https://graph.facebook.com/{$this->version()}/{$pageId}/photos", [
                    'access_token' => $res['token'],
                    'json'         => ['url' => $mediaId, 'caption' => $message, 'published' => true],
                ]);
                if (!$r['ok']) return $this->fail(describe_api_error($r['body'] ?? $r['error']));
                $id = $r['body']['post_id'] ?? ($r['body']['id'] ?? null);
                return $this->ok((string)$id);
            }

            $payload = ['message' => $message !== '' ? $message : ($link ?: ' '), 'published' => true];
            if ($link !== '' && $message !== '') $payload['link'] = $link;
            $r = http_json('POST', "https://graph.facebook.com/{$this->version()}/{$pageId}/feed", [
                'access_token' => $res['token'],
                'json'         => $payload,
            ]);
            if (!$r['ok']) return $this->fail(describe_api_error($r['body'] ?? $r['error']));
            return $this->ok((string)($r['body']['id'] ?? ''));
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    public function getAccountInformation(int $accountId): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $pageId = (string)($meta['page_id'] ?? '');
        if ($pageId === '') return ['error' => 'No Page id stored'];

        $r = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$pageId}?" . http_build_query([
            'fields'       => 'id,name,username,followers_count,fan_count,tasks',
            'access_token' => $res['token'],
        ]));
        if (!$r['ok']) return ['error' => describe_api_error($r['body'] ?? $r['error'])];
        $d = $r['body'] ?? [];
        $followers = (int)($d['followers_count'] ?? $d['fan_count'] ?? 0);
        SocialAccountModel::updateStats($accountId, $followers);
        return ['followers'=>$followers,'following'=>null,'posts'=>null,'engagement'=>null,'page_name'=>$d['name'] ?? null,'demo'=>false];
    }

    public function getPosts(int $accountId, int $limit = 10): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return [];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $pageId = (string)($meta['page_id'] ?? '');
        if ($pageId === '') return [];

        $r = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$pageId}/feed?" . http_build_query([
            'fields'       => 'id,message,created_time,permalink_url,full_picture,reactions.summary.total_count,comments.summary.total_count,shares',
            'limit'        => max(1, min(25, $limit)),
            'access_token' => $res['token'],
        ]));
        if (!$r['ok']) return [];
        $out = [];
        foreach ($r['body']['data'] ?? [] as $p) {
            $out[] = [
                'external_id' => (string)$p['id'],
                'content'     => $p['message'] ?? '',
                'published_at'=> $p['created_time'] ?? null,
                'permalink'   => $p['permalink_url'] ?? null,
                'image'       => $p['full_picture'] ?? null,
                'likes'       => (int)($p['reactions']['summary']['total_count'] ?? 0),
                'comments'    => (int)($p['comments']['summary']['total_count'] ?? 0),
                'shares'      => (int)($p['shares']['count'] ?? 0),
            ];
        }
        return $out;
    }

    public function getAnalytics(int $accountId, string $from, string $to): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $pageId = (string)($meta['page_id'] ?? '');
        if ($pageId === '') return ['error' => 'No Page id stored'];

        $r = http_json('GET', "https://graph.facebook.com/{$this->version()}/{$pageId}/insights?" . http_build_query([
            'metric'      => 'page_impressions,page_post_engagements,page_fan_adds_unique,page_views_total',
            'period'      => 'day',
            'since'       => date('Y-m-d', strtotime($from)),
            'until'       => date('Y-m-d', strtotime($to)),
            'access_token'=> $res['token'],
        ]));
        if (!$r['ok']) return ['error' => describe_api_error($r['body'] ?? $r['error'])];

        $series = ['page_impressions'=>[], 'page_post_engagements'=>[], 'page_fan_adds_unique'=>[], 'page_views_total'=>[]];
        foreach ($r['body']['data'] ?? [] as $row) {
            $name = (string)($row['name'] ?? '');
            $points = [];
            foreach ($row['values'] ?? [] as $v) {
                $end = (string)($v['end_time'] ?? '');
                $points[$end] = (int)($v['value'] ?? 0);
            }
            ksort($points);
            $series[$name] = $points;
        }
        $sum = static function (array $points): int { return array_sum($points); };
        return [
            'series' => $series,
            'totals' => [
                'reach'      => $sum($series['page_impressions']),
                'impressions'=> $sum($series['page_impressions']),
                'likes'      => $sum($series['page_post_engagements']),
                'comments'   => 0,
                'shares'     => 0,
                'followers'  => (int)($res['account']['followers'] ?? 0),
            ],
            'demo' => false,
        ];
    }
}
