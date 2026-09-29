<?php
require_once __DIR__ . '/AbstractSocialProvider.php';

/**
 * TikTok for Developers — Login Kit + Content Posting API.
 *
 * The platform is called "Content Posting API" and splits into two modes:
 *   DIRECT_POST  the app publishes straight to the creator's feed
 *   MEDIA_UPLOAD  the creator finishes the post in the TikTok app (inbox)
 * Set TIKTOK_POST_MODE to pick one; DIRECT_POST is the default.
 *
 * Required env:
 *   TIKTOK_CLIENT_ID, TIKTOK_CLIENT_SECRET, TIKTOK_REDIRECT_URI
 * Optional:
 *   TIKTOK_POST_MODE         DIRECT_POST (default) | MEDIA_UPLOAD
 *   TIKTOK_PRIVACY_LEVEL     one of the privacy_level_options returned by
 *                            /v2/creator_info/query/ (default PUBLIC_TO_EVERYONE)
 *
 * Notes:
 *  - The access token lives 24h, the refresh token 365 days, so the background
 *    refresher in cron/ is what keeps long-lived connections alive.
 *  - Unaudited apps are forced into private visibility; that is a TikTok-side
 *    review requirement, not something this integration can work around.
 */
class TikTokProvider extends AbstractSocialProvider {
    const API  = 'https://open.tiktokapis.com/v2';
    const AUTH = 'https://www.tiktok.com/v2/auth/authorize/';

    /** Login Kit identity + Content Posting write access. */
    const SCOPES = ['user.info.basic', 'video.publish', 'video.upload'];

    /** Direct posting only accepts these; MEDIA_UPLOAD ignores them. */
    const PRIVACY_LEVELS = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];

    public function __construct() { parent::__construct('tiktok', 'TikTok'); }

    public function isConfigured(): bool {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    private function clientId(): string     { return (string)env_get('TIKTOK_CLIENT_ID'); }
    private function clientSecret(): string { return (string)env_get('TIKTOK_CLIENT_SECRET'); }
    public function redirectUri(): string { return $this->redirectUriFor('TIKTOK_REDIRECT_URI'); }
    private function postMode(): string {
        $mode = strtoupper((string)env_get('TIKTOK_POST_MODE', 'DIRECT_POST'));
        return in_array($mode, ['DIRECT_POST', 'MEDIA_UPLOAD'], true) ? $mode : 'DIRECT_POST';
    }
    /** Title/caption ceiling in UTF-16 runes — TikTok rejects anything longer. */
    private static function runeLen(string $s): int { return (int)mb_strlen($s, 'UTF-16'); }
    private static function runeCut(string $s, int $max): string {
        return self::runeLen($s) <= $max ? $s : (string)mb_substr($s, 0, $max, 'UTF-16');
    }

    /* ----------------------------------------------------------- OAuth --- */

    public function authUrl(string $state, array $extra = []): string {
        $params = [
            'client_key'     => $this->clientId(),
            'redirect_uri'   => $this->redirectUri(),
            'response_type'  => 'code',
            'scope'          => implode(',', array_unique(array_merge(self::SCOPES, $extra['scopes'] ?? []))),
            'state'          => $state,
            'force_verify'   => 'true',
        ];
        return self::AUTH . '?' . http_build_query($params);
    }

    public function exchange(string $code, array $extra = []): array {
        $res = http_json('POST', self::API . '/oauth/token/', [
            'form'    => [
                'client_key'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'code'          => $code,
                'grant_type'    => 'authorization_code',
            ],
            'headers' => ['Cache-Control: no-cache'],
        ]);
        $b = $this->tokenBody($res, 'TikTok authorization failed');
        if (empty($b['access_token'])) throw new RuntimeException('TikTok did not return an access token');

        return [
            'token'      => (string)$b['access_token'],
            'refresh'    => isset($b['refresh_token']) ? (string)$b['refresh_token'] : null,
            'expires_in' => (int)($b['expires_in'] ?? 0) ?: null,
            'scopes'     => $b['scope'] ?? implode(',', self::SCOPES),
            'raw'        => $b,
        ];
    }

    public function refresh(?string $refreshToken, ?string $accessToken = null): array {
        if (!$refreshToken) return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>[]];
        $res = http_json('POST', self::API . '/oauth/token/', [
            'form' => [
                'client_key'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token'  => $refreshToken,
                'grant_type'     => 'refresh_token',
            ],
            'headers' => ['Cache-Control: no-cache'],
        ]);
        if (!$res['ok'] || empty($res['body']['access_token'])) {
            return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>$res['body'] ?? []];
        }
        $b = $res['body'];
        return [
            'token'      => (string)$b['access_token'],
            // TikTok rotates the refresh token; fall back to the old one if absent.
            'refresh'    => $b['refresh_token'] ?? $refreshToken,
            'expires_in' => (int)($b['expires_in'] ?? 0) ?: null,
            'raw'        => $b,
        ];
    }

    public function revokeToken(string $token): void {
        http_json('POST', self::API . '/oauth/revoke/', [
            'form' => [
                'client_key'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'token'          => $token,
            ],
            'headers' => ['Cache-Control: no-cache'],
        ]);
    }

    private function tokenBody(array $res, string $fallback): array {
        if ($res['ok']) return is_array($res['body'] ?? null) ? $res['body'] : [];
        $b = $res['body'] ?? null;
        if (is_array($b) && ($b['error_description'] ?? $b['error'] ?? $b['message'] ?? null)) {
            throw new RuntimeException(trim((string)($b['error_description'] ?? $b['error'] ?? $b['message'])));
        }
        throw new RuntimeException($res['error'] ?? $fallback);
    }

    /* --------------------------------------------------------- profile --- */

    public function profile(array $credentials, array $context = []): array {
        $token = (string)($credentials['token'] ?? '');
        if ($token === '') throw new RuntimeException('Missing TikTok access token');

        $r = http_json('GET', self::API . '/user/info/?' . http_build_query([
            'fields' => 'open_id,union_id,avatar_url,display_name,username,follower_count,following_count,video_count,signature',
        ]), ['access_token' => $token]);
        $u = $r['body'] ?? [];
        $openId = (string)($u['open_id'] ?? ($credentials['raw']['open_id'] ?? ''));
        if ($openId === '') {
            throw new RuntimeException(describe_api_error($u ?: ($r['error'] ?? 'TikTok did not return an open_id')));
        }

        // The privacy levels this creator is allowed to publish with. Cached so
        // publish does not have to ask again on every post.
        $levels = $this->creatorPrivacyLevels($token);

        return [
            'account_name'        => (string)($u['display_name'] ?? $u['username'] ?? 'TikTok creator'),
            'username'            => isset($u['username']) ? '@' . $u['username'] : null,
            'account_type'        => 'creator',
            'external_account_id' => $openId,
            'external_user_id'    => isset($u['union_id']) ? (string)$u['union_id'] : null,
            'avatar_url'          => $u['avatar_url'] ?? null,
            'followers'           => (int)($u['follower_count'] ?? 0),
            'token'               => $token,
            'refresh'             => isset($credentials['refresh']) ? (string)$credentials['refresh'] : null,
            'expires_in'          => $credentials['expires_in'] ?? null,
            'scopes'              => $credentials['scopes'] ?? null,
            'meta'                => [
                'open_id'          => $openId,
                'unique_id'        => $u['username'] ?? null,
                'video_count'      => (int)($u['video_count'] ?? 0),
                'following_count'  => (int)($u['following_count'] ?? 0),
                'signature'        => $u['signature'] ?? null,
                'privacy_levels'   => $levels,
                'post_mode'        => $this->postMode(),
                'refresh_expires_in' => isset($credentials['raw']['refresh_expires_in'])
                    ? (int)$credentials['raw']['refresh_expires_in'] : null,
            ],
        ];
    }

    /** @return string[] privacy levels the creator is allowed to post with. */
    private function creatorPrivacyLevels(string $token): array {
        $r = http_json('GET', self::API . '/creator_info/query/?' . http_build_query([
            'fields' => 'privacy_level_options',
        ]), ['access_token' => $token]);
        $options = $r['body']['data']['privacy_level_options'] ?? null;
        if (!is_array($options) || !$options) return self::PRIVACY_LEVELS;
        $out = [];
        foreach ($options as $o) {
            $name = (string)($o['privacy_level'] ?? $o ?? '');
            if ($name !== '' && in_array($name, self::PRIVACY_LEVELS, true)) $out[] = $name;
        }
        return $out ?: self::PRIVACY_LEVELS;
    }

    private function privacyLevel(array $meta): string {
        $allowed = $meta['privacy_levels'] ?? self::PRIVACY_LEVELS;
        $allowed = is_array($allowed) && $allowed ? $allowed : self::PRIVACY_LEVELS;
        $want = (string)env_get('TIKTOK_PRIVACY_LEVEL', 'PUBLIC_TO_EVERYONE');
        return in_array($want, $allowed, true) ? $want : (string)($allowed[0] ?? 'SELF_ONLY');
    }

    /* -------------------------------------------------------- publishing */

    public function publishPost(int $accountId, array $postData): array {
        if (!$this->budget('publish', 5, 300)) return $this->fail('TikTok rate budget exhausted, try again shortly');
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return $this->notConnected((string)$res['error']);

        $meta    = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $caption = trim((string)($postData['content'] ?? ''));
        $ids     = $this->mediaIds($postData);
        if (!$ids) return $this->fail('TikTok only publishes media — attach a video or photo set first');

        $isVideo = $this->isVideo((int)$ids[0]);
        return $isVideo
            ? $this->publishVideo($res, $meta, $caption, (int)$ids[0])
            : $this->publishPhoto($res, $meta, $caption, $ids);
    }

    /** Video: title up to 2200 runes. */
    private function publishVideo(array $res, array $meta, string $caption, int $mediaId): array {
        $url = $this->mediaUrlForId($mediaId);
        if ($url === null) return $this->fail('The attached video could not be resolved to a public URL');

        $init = http_json('POST', self::API . '/post/publish/video/init/', [
            'access_token' => $res['token'],
            'json' => [
                'post_info' => [
                    'title'            => self::runeCut($caption, 2200),
                    'privacy_level'    => $this->privacyLevel($meta),
                    'disable_comment'  => false,
                    'disable_duet'     => false,
                    'disable_stitch'   => false,
                ],
                'source_info' => [
                    'source'    => 'PULL_FROM_URL',
                    'video_url' => $url,
                ],
            ],
        ]);
        $publishId = (string)($init['body']['data']['publish_id'] ?? '');
        if (!$init['ok'] || $publishId === '') {
            return $this->fail($this->apiMessage($init, 'TikTok rejected the video'));
        }

        // TikTok returns a publish_id, not the post id; record it and confirm.
        $status = $this->publishStatus($res['token'], $publishId);
        if (in_array($status, ['PUBLISH_COMPLETE'], true)) {
            return $this->ok($publishId, ['permalink' => $this->publishUrl($publishId)]);
        }
        return $this->ok($publishId, [
            'permalink'  => $this->publishUrl($publishId),
            'processing' => true,
            'note'       => 'TikTok is still processing the video (status: ' . ($status ?: 'PENDING') . ')',
        ]);
    }

    /** Photo post: a carousel, title up to 90 runes and description up to 4000. */
    private function publishPhoto(array $res, array $meta, string $caption, array $mediaIds): array {
        $urls = [];
        foreach ($mediaIds as $id) {
            $u = $this->mediaUrlForId((int)$id);
            if ($u !== null) $urls[] = $u;
        }
        if (!$urls) return $this->fail('The attached photos could not be resolved to public URLs');

        $init = http_json('POST', self::API . '/post/publish/content/init/', [
            'access_token' => $res['token'],
            'json' => [
                'media_type' => 'PHOTO',
                'post_mode'  => $this->postMode(),
                'post_info'  => [
                    'title'            => self::runeCut(self::firstLine($caption), 90),
                    'description'      => self::runeCut($caption, 4000),
                    'privacy_level'    => $this->privacyLevel($meta),
                    'disable_comment'  => false,
                    'auto_add_music'   => false,
                ],
                'source_info' => [
                    'source'       => 'PULL_FROM_URL',
                    'photo_images' => array_map(static fn(string $u): array => ['uri' => $u], $urls),
                ],
            ],
        ]);
        $publishId = (string)($init['body']['data']['publish_id'] ?? '');
        if (!$init['ok'] || $publishId === '') {
            return $this->fail($this->apiMessage($init, 'TikTok rejected the photo post'));
        }
        return $this->ok($publishId, [
            'permalink'  => $this->publishUrl($publishId),
            'processing' => true,
            'note'       => $this->postMode() === 'MEDIA_UPLOAD'
                ? 'Sent to the TikTok app — the creator finishes the post there'
                : 'TikTok is still processing the post',
        ]);
    }

    private function publishStatus(string $token, string $publishId): ?string {
        $r = http_json('GET', self::API . '/post/publish/status/fetch/?' . http_build_query([
            'publish_id' => $publishId,
        ]), ['access_token' => $token]);
        return $r['ok'] ? (string)($r['body']['data']['status'] ?? null) : null;
    }

    /**
     * A publish_id identifies the upload/publish job, not a video, so there is
     * no public permalink to hand back until the video shows up in the user's
     * feed. Returning null keeps the UI from rendering a dead link; callers
     * fall back to the creator's profile.
     */
    private function publishUrl(string $publishId): ?string {
        return null;
    }

    /* -------------------------------------------------------- analytics -- */

    public function getAccountInformation(int $accountId): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];

        $r = http_json('GET', self::API . '/user/info/?' . http_build_query([
            'fields' => 'open_id,display_name,username,avatar_url,follower_count,following_count,video_count',
        ]), ['access_token' => $res['token']]);
        if (!$r['ok']) return ['error' => describe_api_error($r['body'] ?? $r['error']), 'demo' => false];

        $u = $r['body'] ?? [];
        $followers = (int)($u['follower_count'] ?? 0);
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $meta['video_count'] = (int)($u['video_count'] ?? 0);
        SocialAccountModel::updateStats($accountId, $followers, $meta);

        return [
            'followers'  => $followers,
            'following'  => (int)($u['following_count'] ?? 0),
            'posts'      => (int)($u['video_count'] ?? 0),
            'engagement' => null,
            'demo'       => false,
        ];
    }

    public function getPosts(int $accountId, int $limit = 10): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return [];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $openId = (string)($meta['open_id'] ?? '');
        if ($openId === '') return [];

        $r = http_json('GET', self::API . '/video/list/?' . http_build_query([
            'fields' => 'id,create_time,cover,share_url,title,video_description,duration,like_count,comment_count,share_count,view_count',
            'max_count' => max(1, min(50, $limit)),
        ]), ['access_token' => $res['token']]);
        if (!$r['ok']) return [];

        $out = [];
        foreach ($r['body']['data']['videos'] ?? [] as $v) {
            $out[] = [
                'external_id'  => (string)($v['id'] ?? ''),
                'content'      => (string)($v['video_description'] ?? $v['title'] ?? ''),
                'published_at' => isset($v['create_time']) ? date('Y-m-d H:i:s', (int)$v['create_time']) : null,
                'permalink'    => $v['share_url'] ?? null,
                'image'        => $v['cover'] ?? null,
                'likes'        => (int)($v['like_count'] ?? 0),
                'comments'     => (int)($v['comment_count'] ?? 0),
                'shares'       => (int)($v['share_count'] ?? 0),
                'views'        => (int)($v['view_count'] ?? 0),
                'type'         => 'video',
            ];
        }
        return $out;
    }

    /**
     * TikTok has no public account-analytics endpoint on the Content Posting
     * API, so only follower/engagement counters from /user/info are reported.
     */
    public function getAnalytics(int $accountId, string $from, string $to): array {
        $info = $this->getAccountInformation($accountId);
        if (isset($info['error'])) return ['error' => $info['error'], 'demo' => false];

        $followers = (int)($info['followers'] ?? 0);
        $impressions = 0;
        $totals = [
            'reach' => 0, 'impressions' => $impressions, 'likes' => 0, 'comments' => 0,
            'shares' => 0, 'saves' => 0, 'views' => 0, 'followers' => $followers,
        ];
        $series = ['followers' => [date('Y-m-d') => $followers]];
        return [
            'series' => $series,
            'totals' => $totals,
            'demo'   => false,
            'note'   => 'TikTok exposes no account-insights endpoint. Only follower/video counters are available.',
        ];
    }

    /* ----------------------------------------------------------- helpers - */

    /** @return int[] */
    private function mediaIds(array $postData): array {
        $ids = $postData['media_ids'] ?? [];
        if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)));
        return array_values(array_filter(array_map('intval', (array)$ids)));
    }

    private function isVideo(int $mediaId): bool {
        $media = MediaModel::find($mediaId);
        if (!$media) return false;
        if (str_starts_with((string)$media['file_type'], 'video/')) return true;
        return (bool)preg_match('/\.(mp4|mov|avi|webm|m4v)$/i', (string)$media['filename']);
    }

    /** Signed public URL so TikTok can pull the bytes itself. */
    private function mediaUrlForId(int $mediaId): ?string {
        $media = MediaModel::find($mediaId);
        if (!$media) return null;
        $path = (string)$media['file_path'];
        if (str_starts_with($path, '/assets/')) {
            return rtrim((string)env_get('PUBLIC_MEDIA_BASE', base_url_public()), '/') . $path;
        }
        return media_url((string)$media['filename'], true);
    }

    private static function firstLine(string $text): string {
        $t = trim($text);
        if ($t === '') return '';
        return trim((string)(preg_split('/\R/', $t)[0] ?? $t));
    }

    /** TikTok errors live in {error:{code,message}} or {error_description}. */
    private function apiMessage(array $res, string $fallback): string {
        $b = $res['body'] ?? null;
        if (is_array($b)) {
            $msg = $b['error']['message'] ?? $b['error_description'] ?? $b['message'] ?? null;
            if ($msg) {
                $code = $b['error']['code'] ?? null;
                return $code ? "$code — $msg" : (string)$msg;
            }
        }
        return $res['error'] ?? $fallback;
    }
}
