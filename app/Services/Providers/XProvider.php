<?php
require_once __DIR__ . '/AbstractSocialProvider.php';

/**
 * X (formerly Twitter) — OAuth 2.0 Authorization Code + PKCE, X API v2.
 *
 * Required env:
 *   TWITTER_CLIENT_ID, TWITTER_CLIENT_SECRET, TWITTER_REDIRECT_URI
 * Optional:
 *   TWITTER_ACCESS_TOKEN, TWITTER_ACCESS_TOKEN_SECRET, TWITTER_CONSUMER_KEY,
 *   TWITTER_CONSUMER_SECRET — OAuth 1.0a *user context*. X's pay-per-use write
 *   model still requires OAuth 1.0a for POST /2/tweets and media upload, so
 *   set these to publish. Without them the account still connects, reads and
 *   reports analytics, but publishing returns a clear "not configured" error.
 *
 * PKCE: X requires a code_challenge on the authorization request. The verifier
 * is minted in prepareStateContext() and travels through the OAuth state
 * bucket, so the callback (a separate request) can still verify it.
 */
class XProvider extends AbstractSocialProvider {
    const API  = 'https://api.x.com/2';
    const AUTH = 'https://x.com/i/oauth2/authorize';

    /** offline.access is what makes X issue a refresh token. */
    const SCOPES = ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'];

    /** RFC 7636 minimum: 43 chars of unreserved alphabet. */
    private const VERIFIER_BYTES = 48;
    private const CHALLENGE_METHOD = 'S256';

    private ?string $pkceVerifier = null;

    public function __construct() { parent::__construct('twitter', 'X (Twitter)'); }

    public function isConfigured(): bool {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    private function clientId(): string     { return (string)env_get('TWITTER_CLIENT_ID'); }
    private function clientSecret(): string { return (string)env_get('TWITTER_CLIENT_SECRET'); }

    /**
     * X requires HTTP Basic client authentication on the token endpoints.
     * http_json() writes the value straight into the Authorization header, so
     * the "Basic " scheme prefix has to be part of it.
     */
    private function clientAuthHeader(): string {
        return 'Basic ' . base64_encode($this->clientId() . ':' . $this->clientSecret());
    }
    public function redirectUri(): string { return $this->redirectUriFor('TWITTER_REDIRECT_URI'); }
    private function scopes(): array {
        // TWITTER_EXTRA_SCOPES is a comma or space separated string. Casting it
        // straight to array would produce one scope containing the separator,
        // which X rejects, so it is tokenised here.
        $extra = array_filter(preg_split('/[\s,]+/', (string)env_get('TWITTER_EXTRA_SCOPES', '')) ?: []);
        return array_values(array_unique(array_merge(self::SCOPES, $extra)));
    }
    /** OAuth 1.0a user context, needed by X write endpoints. */
    private function oauth1Configured(): bool {
        return (string)env_get('TWITTER_ACCESS_TOKEN') !== ''
            && (string)env_get('TWITTER_ACCESS_TOKEN_SECRET') !== ''
            && (string)env_get('TWITTER_CONSUMER_KEY') !== ''
            && (string)env_get('TWITTER_CONSUMER_SECRET') !== '';
    }

    /* ---------------------------------------------------------------- PKCE */

    /** Mints the verifier before the state is stored. Called by oauth.php. */
    public function prepareStateContext(): array {
        $this->pkceVerifier = b64u(random_bytes(self::VERIFIER_BYTES));
        return ['code_verifier' => $this->pkceVerifier];
    }

    private function codeChallenge(string $verifier): string {
        return self::CHALLENGE_METHOD === 'plain'
            ? $verifier
            : b64u(hash('sha256', $verifier, true));
    }

    /* --------------------------------------------------------------- OAuth */

    public function authUrl(string $state, array $extra = []): string {
        $verifier = (string)($extra['code_verifier'] ?? $this->pkceVerifier ?? '');
        if ($verifier === '') {
            // Should not happen — prepareStateContext() always runs first — but
            // X rejects an authorize URL without a challenge, so fail loudly.
            $ctx = $this->prepareStateContext();
            $verifier = (string)$ctx['code_verifier'];
        }

        $params = [
            'response_type'         => 'code',
            'client_id'             => $this->clientId(),
            'redirect_uri'          => $this->redirectUri(),
            'scope'                 => implode(' ', $this->scopes()),
            'state'                 => $state,
            'code_challenge'        => $this->codeChallenge($verifier),
            'code_challenge_method' => self::CHALLENGE_METHOD,
        ];
        return self::AUTH . '?' . http_build_query($params);
    }

    public function exchange(string $code, array $extra = []): array {
        $verifier = (string)($extra['code_verifier'] ?? $this->pkceVerifier ?? '');
        if ($verifier === '') {
            throw new RuntimeException('Missing PKCE verifier. Restart the connection from the Accounts screen.');
        }

        $res = http_json('POST', self::API . '/oauth2/token', [
            'auth'  => $this->clientAuthHeader(),
            'form'  => [
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $this->redirectUri(),
                'code_verifier' => $verifier,
                'client_id'     => $this->clientId(),
            ],
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $b = $this->tokenBody($res, 'X authorization failed');
        if (empty($b['access_token'])) throw new RuntimeException('X did not return an access token');

        return [
            'token'      => (string)$b['access_token'],
            'refresh'    => isset($b['refresh_token']) ? (string)$b['refresh_token'] : null,
            'expires_in' => (int)($b['expires_in'] ?? 0) ?: null,
            'scopes'     => $b['scope'] ?? implode(' ', $this->scopes()),
            'raw'        => $b,
        ];
    }

    public function refresh(?string $refreshToken, ?string $accessToken = null): array {
        if (!$refreshToken) return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>[]];
        $res = http_json('POST', self::API . '/oauth2/token', [
            'auth'  => $this->clientAuthHeader(),
            'form'  => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $this->clientId(),
            ],
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        if (!$res['ok'] || empty($res['body']['access_token'])) {
            return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>$res['body'] ?? []];
        }
        return [
            'token'      => (string)$res['body']['access_token'],
            'refresh'    => $res['body']['refresh_token'] ?? $refreshToken,
            'expires_in' => (int)($res['body']['expires_in'] ?? 0) ?: null,
            'raw'        => $res['body'],
        ];
    }

    public function revokeToken(string $token): void {
        http_json('POST', self::API . '/oauth2/revoke', [
            'auth'  => $this->clientAuthHeader(),
            'form'  => ['token' => $token, 'client_id' => $this->clientId()],
            'headers' => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
    }

    private function tokenBody(array $res, string $fallback): array {
        if ($res['ok']) return is_array($res['body'] ?? null) ? $res['body'] : [];
        $b = $res['body'] ?? null;
        if (is_array($b) && ($b['error_description'] ?? $b['error'] ?? $b['title'] ?? null)) {
            throw new RuntimeException(trim((string)($b['error_description'] ?? $b['error'] ?? $b['title'])));
        }
        throw new RuntimeException($res['error'] ?? $fallback);
    }

    /* ------------------------------------------------------------- profile */

    public function profile(array $credentials, array $context = []): array {
        $token = (string)($credentials['token'] ?? '');
        if ($token === '') throw new RuntimeException('Missing X access token');

        $r = http_json('GET', self::API . '/users/me?' . http_build_query([
            'user.fields' => 'id,name,username,profile_image_url,public_metrics,created_at,verified,description',
        ]), ['access_token' => $token]);
        $u = $r['body']['data'] ?? [];
        $id = (string)($u['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException(describe_api_error($r['body'] ?? ($r['error'] ?? 'X did not identify the account')));
        }

        $metrics = $u['public_metrics'] ?? [];
        return [
            'account_name'        => (string)($u['name'] ?? $u['username'] ?? 'X account'),
            'username'            => isset($u['username']) ? '@' . $u['username'] : null,
            'account_type'        => 'user',
            'external_account_id' => $id,
            'external_user_id'    => $id,
            'avatar_url'          => $u['profile_image_url'] ?? null,
            'followers'           => (int)($metrics['followers_count'] ?? 0),
            'token'               => $token,
            'refresh'             => isset($credentials['refresh']) ? (string)$credentials['refresh'] : null,
            'expires_in'          => $credentials['expires_in'] ?? null,
            'scopes'              => $credentials['scopes'] ?? null,
            'meta'                => [
                'x_user_id'     => $id,
                'username'      => $u['username'] ?? null,
                'following'     => (int)($metrics['following_count'] ?? 0),
                'tweet_count'   => (int)($metrics['tweet_count'] ?? 0),
                'listed_count'  => (int)($metrics['listed_count'] ?? 0),
                'verified'      => (bool)($u['verified'] ?? false),
                'oauth1_ready'  => $this->oauth1Configured(),
            ],
        ];
    }

    /* ----------------------------------------------------------- publishing */

    public function publishPost(int $accountId, array $postData): array {
        if (!$this->budget('publish', 50, 300)) return $this->fail('X rate budget exhausted, try again shortly');
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return $this->notConnected((string)$res['error']);
        if (!$this->oauth1Configured()) {
            return $this->fail('Publishing to X needs an OAuth 1.0a user token. Set TWITTER_ACCESS_TOKEN, '
                . 'TWITTER_ACCESS_TOKEN_SECRET, TWITTER_CONSUMER_KEY and TWITTER_CONSUMER_SECRET.');
        }

        $text = trim((string)($postData['content'] ?? ''));
        if ($text === '') return $this->fail('X posts need some text');

        $mediaIds = $this->uploadMedia($res, $postData);
        if (isset($mediaIds['error'])) return $this->fail($mediaIds['error']);

        $payload = ['text' => $text];
        if ($mediaIds['ids']) $payload['media'] = ['media_ids' => $mediaIds['ids']];

        $r = $this->signed('POST', self::API . '/tweets', $res['token'], [
            'json' => $payload,
        ]);
        if (!$r['ok']) return $this->fail($this->apiMessage($r, 'X rejected the post'));

        $id = (string)($r['body']['data']['id'] ?? '');
        if ($id === '') return $this->fail('X accepted the post but returned no id');

        return $this->ok($id, [
            'permalink' => 'https://x.com/' . $this->usernameOf($res) . '/status/' . $id,
        ]);
    }

    private function usernameOf(array $res): string {
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        return (string)($meta['username'] ?? 'i');
    }

    /**
     * Uploads every attachment. Images use the simple endpoint; videos need the
     * chunked initialize/append/finalize handshake.
     *
     * @return array{ids:string[],error:?string}
     */
    private function uploadMedia(array $res, array $postData): array {
        $ids = $postData['media_ids'] ?? [];
        if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)));
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) return ['ids' => [], 'error' => null];

        $uploaded = [];
        foreach ($ids as $mediaId) {
            $media = MediaModel::find($mediaId);
            if (!$media) continue;
            $path = media_storage_path((string)$media['filename']);
            if (!is_readable($path)) {
                return ['ids' => [], 'error' => 'Attachment ' . $media['filename'] . ' is missing from disk'];
            }
            $isVideo = str_starts_with((string)$media['file_type'], 'video/')
                || (bool)preg_match('/\.(mp4|mov|m4v)$/i', (string)$media['filename']);

            $out = $isVideo
                ? $this->uploadVideo($res, $path, (string)$media['filename'], (int)$media['file_size'])
                : $this->uploadImage($res, $path, (string)$media['filename'], (string)$media['file_type']);
            if (!empty($out['error'])) return ['ids' => [], 'error' => $out['error']];
            $uploaded[] = (string)$out['id'];
        }

        // X allows at most 4 photos, or a single video/GIF.
        return ['ids' => array_slice($uploaded, 0, 4), 'error' => null];
    }

    private function uploadImage(array $res, string $path, string $filename, string $type): array {
        $r = $this->signed('POST', self::API . '/media/upload', $res['token'], [
            'multipart' => [
                ['name' => 'media_category', 'contents' => 'tweet_image'],
                ['name' => 'media', 'contents' => file_get_contents($path), 'filename' => $filename,
                 'type' => $type ?: 'application/octet-stream'],
            ],
            'timeout' => 60,
        ]);
        if (!$r['ok']) return ['id' => null, 'error' => 'X media upload failed: ' . $this->apiMessage($r, 'unknown error')];
        $id = (string)($r['body']['data']['id'] ?? '');
        return $id === ''
            ? ['id' => null, 'error' => 'X media upload returned no media id']
            : ['id' => $id, 'error' => null];
    }

    /** Chunked upload: initialize -> append (4 MiB segments) -> finalize. */
    private function uploadVideo(array $res, string $path, string $filename, int $size): array {
        $segmentBytes = 4 * 1024 * 1024;

        $init = $this->signed('POST', self::API . '/media/upload/initialize', $res['token'], [
            'json' => [
                'media_category' => 'tweet_video',
                'total_bytes'    => $size > 0 ? $size : (int)filesize($path),
            ],
        ]);
        if (!$init['ok']) return ['id' => null, 'error' => 'X video upload could not start: ' . $this->apiMessage($init, 'unknown error')];
        $mediaId = (string)($init['body']['data']['id'] ?? '');
        if ($mediaId === '') return ['id' => null, 'error' => 'X did not return a video media id'];

        $fh = fopen($path, 'rb');
        if ($fh === false) return ['id' => null, 'error' => 'Could not read ' . $filename];
        try {
            $index = 0;
            while (!feof($fh)) {
                $chunk = fread($fh, $segmentBytes);
                if ($chunk === false || $chunk === '') break;
                $r = $this->signed('POST', self::API . '/media/upload/' . $mediaId . '/append', $res['token'], [
                    'body'         => $chunk,
                    'content_type' => 'application/octet-stream',
                    'timeout'      => 120,
                    'query'        => ['segment_index' => (string)$index, 'media_category' => 'tweet_video'],
                ]);
                if (!$r['ok']) {
                    return ['id' => null, 'error' => 'X video segment ' . $index . ' failed: ' . $this->apiMessage($r, 'unknown error')];
                }
                $index++;
            }
        } finally {
            fclose($fh);
        }

        $fin = $this->signed('POST', self::API . '/media/upload/' . $mediaId . '/finalize', $res['token'], []);
        if (!$fin['ok']) return ['id' => null, 'error' => 'X could not finalize the video: ' . $this->apiMessage($fin, 'unknown error')];
        return ['id' => $mediaId, 'error' => null];
    }

    /** Issues a request with OAuth 1.0a user context when available. */
    private function signed(string $method, string $url, string $bearer, array $opts = []): array {
        // Query parameters are part of the OAuth 1.0a signature base string and
        // must not be folded into the URL before signing, otherwise X computes
        // a different signature and rejects the write (this bites the chunked
        // media upload, which signs every /append call with a segment_index).
        $query = (string)($opts['query'] ?? '');
        unset($opts['query']);

        $oauth1Params = [];
        if ($query !== '') $oauth1Params = http_query_params($query);

        if ($this->oauth1Configured()) {
            $opts['auth'] = oauth1_header(
                $method,
                $url,
                $oauth1Params,
                (string)env_get('TWITTER_CONSUMER_KEY'),
                (string)env_get('TWITTER_CONSUMER_SECRET'),
                (string)env_get('TWITTER_ACCESS_TOKEN'),
                (string)env_get('TWITTER_ACCESS_TOKEN_SECRET')
            );
        } else {
            $opts['access_token'] = $bearer;
        }
        return http_json($method, $url, $opts + ['query' => $query]);
    }

    /* ----------------------------------------------------------- analytics */

    public function getAccountInformation(int $accountId): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $id = (string)($meta['x_user_id'] ?? '');
        if ($id === '') return ['error' => 'No X user id stored. Reconnect the account.'];

        $r = http_json('GET', self::API . '/users/' . rawurlencode($id) . '?' . http_build_query([
            'user.fields' => 'public_metrics',
        ]), ['access_token' => $res['token']]);
        if (!$r['ok']) return ['error' => describe_api_error($r['body'] ?? $r['error']), 'demo' => false];

        $m = $r['body']['data']['public_metrics'] ?? [];
        $followers = (int)($m['followers_count'] ?? 0);
        SocialAccountModel::updateStats($accountId, $followers, [
            'x_user_id'   => $id,
            'tweet_count' => (int)($m['tweet_count'] ?? 0),
        ]);

        return [
            'followers'  => $followers,
            'following'  => (int)($m['following_count'] ?? 0),
            'posts'      => (int)($m['tweet_count'] ?? 0),
            'engagement' => null,
            'demo'       => false,
        ];
    }

    public function getPosts(int $accountId, int $limit = 10): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return [];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $id = (string)($meta['x_user_id'] ?? '');
        if ($id === '') return [];

        $r = http_json('GET', self::API . '/users/' . rawurlencode($id) . '/tweets?' . http_build_query([
            'max_results'        => max(5, min(100, $limit)),
            'tweet.fields'       => 'created_at,public_metrics,entities,attachments',
            'exclude'            => 'retweets,replies',
        ]), ['access_token' => $res['token']]);
        if (!$r['ok']) return [];

        $out = [];
        foreach ($r['body']['data'] ?? [] as $t) {
            $m = $t['public_metrics'] ?? [];
            $username = (string)($meta['username'] ?? 'i');
            $out[] = [
                'external_id'  => (string)($t['id'] ?? ''),
                'content'      => (string)($t['text'] ?? ''),
                'published_at' => $t['created_at'] ?? null,
                'permalink'    => 'https://x.com/' . $username . '/status/' . (string)($t['id'] ?? ''),
                'image'        => null,
                'likes'        => (int)($m['like_count'] ?? 0),
                'comments'     => (int)($m['reply_count'] ?? 0),
                'shares'       => (int)($m['retweet_count'] ?? 0) + (int)($m['quote_count'] ?? 0),
                'views'        => (int)($m['impression_count'] ?? 0),
                'type'         => 'tweet',
            ];
        }
        return $out;
    }

    public function getAnalytics(int $accountId, string $from, string $to): array {
        $info = $this->getAccountInformation($accountId);
        if (isset($info['error'])) return ['error' => $info['error'], 'demo' => false];

        // Per-post metrics are the only public signal on the Basic tier, so the
        // timeline is aggregated instead of requesting the paid insights API.
        $posts = $this->getPosts($accountId, 100);
        $byDay = [];
        $totals = ['reach' => 0, 'impressions' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'saves' => 0];
        foreach ($posts as $p) {
            $day = substr((string)($p['published_at'] ?? ''), 0, 10);
            if ($day === '') continue;
            $d = $byDay[$day] ?? ['likes' => 0, 'comments' => 0, 'shares' => 0, 'reach' => 0, 'impressions' => 0, 'saves' => 0];
            $d['likes']     += (int)($p['likes'] ?? 0);
            $d['comments']  += (int)($p['comments'] ?? 0);
            $d['shares']    += (int)($p['shares'] ?? 0);
            $d['impressions'] += (int)($p['views'] ?? 0);
            $byDay[$day] = $d;
        }
        foreach ($byDay as $d) foreach ($totals as $k => $_) $totals[$k] += (int)($d[$k] ?? 0);
        $totals['followers'] = (int)($info['followers'] ?? 0);
        ksort($byDay);

        return [
            'series' => $byDay,
            'totals' => $totals,
            'demo'   => false,
            'note'   => 'X public metrics are cumulative per post. The timeline shows per-post engagement on its publish day.',
        ];
    }

    /* ------------------------------------------------------------- helpers */

    private function apiMessage(array $res, string $fallback): string {
        $b = $res['body'] ?? null;
        if (is_array($b)) {
            $detail = $b['detail'] ?? $b['title'] ?? null;
            $msg = is_string($detail) ? $detail : ($b['error_description'] ?? $b['error'] ?? null);
            if (is_array($msg)) $msg = describe_api_error($msg);
            if ($msg) return (string)$msg;
        }
        return $res['error'] ?? $fallback;
    }
}
