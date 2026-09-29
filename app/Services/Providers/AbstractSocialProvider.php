<?php
require_once __DIR__ . '/SocialMediaProvider.php';

/**
 * Shared plumbing for the real providers: credential loading, transparent
 * token refresh, outbound call budgeting and common result shaping.
 */
abstract class AbstractSocialProvider implements SocialMediaProvider {
    protected string $slug;
    protected string $displayName;

    public function __construct(string $slug, string $displayName) {
        $this->slug = $slug;
        $this->displayName = $displayName;
    }

    public function getPlatformSlug(): string { return $this->slug; }
    public function getDisplayName(): string { return $this->displayName; }

    /* --- credentials -------------------------------------------------- */

    /** Nothing to carry by default; PKCE providers override this. */
    public function prepareStateContext(): array { return []; }

    /**
     * Redirect URI to send. An explicit *_REDIRECT_URI wins; otherwise it is
     * derived from APP_URL so it always matches oauth_callback_url($slug),
     * which is the value to register in the platform's portal.
     */
    protected function redirectUriFor(string $envKey): string {
        $uri = (string)env_get($envKey, '');
        return $uri !== '' ? $uri : oauth_callback_url($this->slug);
    }

    protected function account(int $accountId): ?array {
        return SocialAccountModel::findRaw($accountId);
    }

    /**
     * Returns a usable access token for an account, refreshing it when it is
     * about to expire. Persists any new credentials.
     *
     * @return array{ok:bool,token:?string,error:?string,account:?array}
     */
    protected function resolveToken(int $accountId): array {
        $account = $this->account($accountId);
        if (!$account) return ['ok'=>false,'token'=>null,'error'=>'Account not found','account'=>null];
        if (($account['connection_status'] ?? '') !== 'connected') {
            return ['ok'=>false,'token'=>null,'error'=>'Account is not connected','account'=>$account];
        }

        $access  = SocialAccountModel::accessToken($accountId);
        $refresh = SocialAccountModel::refreshTokenValue($accountId);

        if ($access !== null && !SocialAccountModel::tokenExpired($accountId)) {
            return ['ok'=>true,'token'=>$access,'error'=>null,'account'=>$account];
        }

        if ($refresh === null) {
            $expired = $access !== null;
            if ($expired) SocialAccountModel::setStatus($accountId, 'expired', 'Token expired and cannot be refreshed');
            return [
                'ok' => false,
                'token' => null,
                'error' => $expired ? 'Access token expired. Reconnect the account.' : 'No credentials stored. Reconnect the account.',
                'account' => $account,
            ];
        }

        $new = $this->refresh($refresh, $access);
        if (empty($new['token'])) {
            SocialAccountModel::setStatus($accountId, 'expired', 'Token refresh failed');
            return ['ok'=>false,'token'=>null,'error'=>'Could not refresh the access token. Reconnect the account.','account'=>$account];
        }

        SocialAccountModel::storeToken($accountId, $new['token'], $new['refresh'] ?? null, $new['expires_in'] ?? null);
        $fresh = $this->account($accountId);
        return ['ok'=>true,'token'=>$new['token'],'error'=>null,'account'=>$fresh];
    }

    /**
     * Per-platform call budget so a failing loop cannot hit API rate limits
     * or get the app throttled by the provider.
     */
    protected function budget(string $op, int $limit = 60, int $window = 60): bool {
        return Throttle::attempt('api_' . $this->slug . '_' . $op, $limit, $window);
    }

    protected function ok(string $externalId, array $extra = []): array {
        return ['success'=>true,'external_id'=>$externalId,'error'=>null,'demo'=>false] + $extra;
    }

    protected function fail(string $error, array $extra = []): array {
        Logger::warn('provider call failed', ['platform'=>$this->slug,'error'=>$error]);
        return ['success'=>false,'external_id'=>null,'error'=>$error,'demo'=>false] + $extra;
    }

    protected function notConnected(string $error): array {
        return $this->fail($error, ['needs_reauth' => true]);
    }

    /* --- default no-ops (overridden where supported) ------------------- */

    public function connect(array $credentials): array {
        return ['success'=>true,'message'=>"{$this->displayName} connected",'demo'=>false];
    }

    public function disconnect(int $accountId): bool {
        // Best effort: revoke upstream so the token cannot be replayed.
        $res = $this->resolveToken($accountId);
        if (!empty($res['token']) && method_exists($this, 'revokeToken')) {
            $this->revokeToken($res['token']);
        }
        return true;
    }

    public function getNotifications(int $accountId): array { return []; }

    public function getPosts(int $accountId, int $limit = 10): array { return []; }

    /** Public, signed URL of the first media attachment of a post. */
    protected function mediaUrlForPost(array $postData): ?string {
        $ids = $postData['media_ids'] ?? [];
        if (is_string($ids)) $ids = array_filter(array_map('trim', explode(',', $ids)));
        $ids = array_values(array_filter(array_map('intval', (array)$ids)));
        if (!$ids) return null;
        $media = MediaModel::find($ids[0]);
        if (!$media) return null;
        $path = (string)$media['file_path'];
        if (str_starts_with($path, '/assets/')) {
            return rtrim((string)env_get('PUBLIC_MEDIA_BASE', base_url_public()), '/') . $path;
        }
        return media_url($media['filename'], true);
    }
}
