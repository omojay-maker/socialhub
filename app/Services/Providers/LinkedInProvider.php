<?php
require_once __DIR__ . '/AbstractSocialProvider.php';

/**
 * LinkedIn (OpenID Connect + Posts API).
 *
 * Required env:
 *   LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET, LINKEDIN_REDIRECT_URI
 * Optional: LINKEDIN_ORGANIZATION_URN to post as an organization
 *           (requires the r_organization_social scope and admin approval).
 */
class LinkedInProvider extends AbstractSocialProvider {
    const SCOPES_MEMBER = ['openid', 'profile', 'email', 'w_member_social'];
    const SCOPES_ORG    = ['openid', 'profile', 'email', 'w_organization_social'];

    public function __construct() { parent::__construct('linkedin', 'LinkedIn'); }

    public function isConfigured(): bool {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    private function clientId(): string { return (string)env_get('LINKEDIN_CLIENT_ID'); }
    private function clientSecret(): string { return (string)env_get('LINKEDIN_CLIENT_SECRET'); }
    public function redirectUri(): string { return $this->redirectUriFor('LINKEDIN_REDIRECT_URI'); }
    private function scopes(): array {
        return $this->organizationUrn() !== '' ? self::SCOPES_ORG : self::SCOPES_MEMBER;
    }
    private function organizationUrn(): string {
        return (string)env_get('LINKEDIN_ORGANIZATION_URN', '');
    }
    private function apiVersion(): string {
        return (string)env_get('LINKEDIN_API_VERSION', date('Ym', strtotime('-2 months')));
    }

    public function authUrl(string $state, array $extra = []): string {
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'state'         => $state,
            'scope'         => implode(' ', array_unique(array_merge($this->scopes(), $extra['scopes'] ?? []))),
        ];
        return 'https://www.linkedin.com/oauth/v2/authorization?' . http_build_query($params);
    }

    public function exchange(string $code, array $extra = []): array {
        $res = http_json('POST', 'https://www.linkedin.com/oauth/v2/accessToken', [
            'form'    => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri(),
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ],
            'headers' => ['X-Restli-Protocol-Version: 2.0.0'],
        ]);
        if (!$res['ok']) {
            throw new RuntimeException(describe_api_error($res['body'] ?? $res['error'] ?? 'LinkedIn authorization failed'));
        }
        $b = $res['body'] ?? [];
        if (empty($b['access_token'])) throw new RuntimeException('LinkedIn did not return an access token');

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
        $res = http_json('POST', 'https://www.linkedin.com/oauth/v2/accessToken', [
            'form' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $this->clientId(),
                'client_secret' => $this->clientSecret(),
            ],
            'headers' => ['X-Restli-Protocol-Version: 2.0.0'],
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

    public function profile(array $credentials, array $context = []): array {
        $token = (string)($credentials['token'] ?? '');
        if ($token === '') throw new RuntimeException('Missing LinkedIn access token');

        $r = http_json('GET', 'https://api.linkedin.com/v2/userinfo', ['access_token' => $token]);
        if (!$r['ok']) {
            throw new RuntimeException(describe_api_error($r['body'] ?? $r['error'] ?? 'Could not read the LinkedIn profile'));
        }
        $me = $r['body'] ?? [];
        $sub = (string)($me['sub'] ?? '');
        if ($sub === '') throw new RuntimeException('LinkedIn did not return a profile id (is the openid scope granted?)');

        $orgUrn = $this->organizationUrn();
        $author = $orgUrn !== '' ? $orgUrn : 'urn:li:person:' . $sub;

        return [
            'account_name'        => (string)($me['name'] ?? 'LinkedIn member'),
            'username'            => null,
            'account_type'        => $orgUrn !== '' ? 'organization' : 'member',
            'external_account_id' => $orgUrn !== '' ? $orgUrn : $sub,
            'external_user_id'    => $sub,
            'avatar_url'          => $me['picture'] ?? null,
            'followers'           => 0,
            'token'               => $token,
            'refresh'             => isset($credentials['refresh']) ? (string)$credentials['refresh'] : null,
            'expires_in'          => $credentials['expires_in'] ?? null,
            'scopes'              => $credentials['scopes'] ?? null,
            'meta'                => [
                'author'       => $author,
                'is_org'       => $orgUrn !== '',
                'given_name'   => $me['given_name'] ?? null,
                'family_name'  => $me['family_name'] ?? null,
                'email'        => $me['email'] ?? null,
            ],
        ];
    }

    /* --- publishing ---------------------------------------------------- */

    public function publishPost(int $accountId, array $postData): array {
        if (!$this->budget('publish', 50, 300)) return $this->fail('LinkedIn rate budget exhausted, try again shortly');
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return $this->notConnected((string)$res['error']);
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $author = (string)($meta['author'] ?? '');
        if ($author === '') return $this->fail('No LinkedIn author URN stored. Reconnect the account.');

        $text = trim((string)($postData['content'] ?? ''));
        if ($text === '') $text = trim((string)($postData['link'] ?? ''));
        if ($text === '') return $this->fail('LinkedIn posts need some text');

        $payload = [
            'author'    => $author,
            'commentary'=> $text,
            'visibility'=> $meta['is_org'] ?? false ? 'ORGANIZATION' : 'PUBLIC',
            'distribution' => [
                'feedDistribution'           => 'MAIN_FEED',
                'targetEntities'             => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState'          => 'PUBLISHED',
            'isReshareDisabledByAuthor'=> false,
            'commentsSetting'         => 'COMMENTS_ENABLED',
        ];

        $r = http_json('POST', 'https://api.linkedin.com/rest/posts', [
            'access_token' => $res['token'],
            'headers'      => [
                'X-Restli-Protocol-Version: 2.0.0',
                'LinkedIn-Version: ' . $this->apiVersion(),
            ],
            'json' => $payload,
        ]);
        if (!$r['ok']) return $this->fail(describe_api_error($r['body'] ?? $r['error'] ?? 'LinkedIn publish failed'));

        // LinkedIn returns the share urn in the x-restli-id response header.
        $id = (string)($r['headers']['x-restli-id'] ?? ($r['body']['id'] ?? ''));
        if ($id === '') $id = is_string($r['body'] ?? null) ? $r['body'] : '';
        if ($id === '') $id = 'urn:li:share:' . bin2hex(random_bytes(4));
        return $this->ok($id);
    }

    public function getAccountInformation(int $accountId): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];
        $author = (string)($meta['author'] ?? '');

        // The member/organization API exposes no follower count; analytics
        // requires the Marketing/Analytics scopes, so report what is known.
        return [
            'followers'  => (int)($res['account']['followers'] ?? 0),
            'following'  => null,
            'posts'      => null,
            'engagement' => null,
            'author'     => $author,
            'note'       => 'LinkedIn follower counts are not available through the standard scopes',
            'demo'       => false,
        ];
    }

    public function getPosts(int $accountId, int $limit = 10): array {
        // Requires r_organization_social + analytics scopes; not requested by default.
        return [];
    }

    public function getAnalytics(int $accountId, string $from, string $to): array {
        $res = $this->resolveToken($accountId);
        if (!$res['ok']) return ['error' => $res['error']];
        $meta = json_decode((string)($res['account']['meta'] ?? '{}'), true) ?: [];

        if (!($meta['is_org'] ?? false) || (string)env_get('LINKEDIN_ORGANIZATION_URN') === '') {
            return [
                'error' => 'LinkedIn analytics need the r_organization_social scope and a LinkedIn organization',
                'demo'  => false,
            ];
        }
        $urn = (string)env_get('LINKEDIN_ORGANIZATION_URN');
        $start = (string)(strtotime($from) * 1000);
        $r = http_json('GET', 'https://api.linkedin.com/rest/analytics?' . http_build_query([
            'q'      => 'analytics',
            'pivot'  => 'ORGANIZATION',
            'timeGranularity' => 'DAY',
            'startTimestamp'  => $start,
            'endTimestamp'    => (string)(strtotime($to) * 1000),
            'organizationalEntity' => $urn,
            'accounts' => 'List(urn%3Ali%3AsponsoredAccount%3A' . $urn . ')',
            'fields'   => 'impressions,clicks,engagement,follows',
        ]), [
            'access_token' => $res['token'],
            'headers'      => ['X-Restli-Protocol-Version: 2.0.0', 'LinkedIn-Version: ' . $this->apiVersion()],
        ]);
        if (!$r['ok']) return ['error' => describe_api_error($r['body'] ?? $r['error']), 'demo' => false];

        $series = ['impressions'=>[], 'clicks'=>[], 'engagement'=>[], 'follows'=>[]];
        foreach ($r['body']['elements'] ?? [] as $el) {
            $day = date('Y-m-d', (int)(((int)($el['timeRange']['start'] ?? 0)) / 1000));
            foreach ($series as $k => $_) $series[$k][$day] = (int)($el[$k] ?? 0);
        }
        $totals = array_map(static fn(array $p) => array_sum($p), $series);
        $totals['reach'] = $totals['impressions'] ?? 0;
        $totals['likes'] = $totals['engagement'] ?? 0;
        $totals['comments'] = 0;
        $totals['shares'] = 0;
        $totals['followers'] = (int)($res['account']['followers'] ?? 0);
        return ['series'=>$series,'totals'=>$totals,'demo'=>false];
    }
}
