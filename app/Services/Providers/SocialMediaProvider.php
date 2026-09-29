<?php
/**
 * Contract every social provider must satisfy.
 *
 * OAuth half (authUrl/exchange/refresh/profile) is what powers "connect a
 * real account"; the publishing/analytics half is used by the Publisher and
 * the dashboard.
 */
interface SocialMediaProvider {
    /* --- OAuth ------------------------------------------------------- */

    /** True when credentials are configured for this platform. */
    public function isConfigured(): bool;

    /**
     * Provider authorization URL the browser is sent to.
     * $extra carries things like the Facebook page selector.
     */
    public function authUrl(string $state, array $extra = []): string;

    /**
     * Values that must survive until the callback (a separate request) and are
     * stored alongside the OAuth state. PKCE verifiers use this.
     * @return array<string,string>
     */
    public function prepareStateContext(): array;

    /**
     * Exchanges the callback code for credentials.
     * @return array{token:?string,refresh:?string,expires_in:?int,scopes:?string,raw:array}
     */
    public function exchange(string $code, array $extra = []): array;

    /**
     * Exchanges a refresh token for new credentials (null when unsupported).
     * @return array{token:?string,refresh:?string,expires_in:?int,raw:array}
     */
    public function refresh(?string $refreshToken, ?string $accessToken = null): array;

    /**
     * Loads the connected profile and the credential used to publish.
     * @return array{account_name:string,username:?string,account_type:string,
     *               external_account_id:string,external_user_id:?string,avatar_url:?string,
     *               followers:int,token:?string,refresh:?string,expires_in:?int,
     *               scopes:?string,meta:array}
     */
    public function profile(array $credentials, array $context = []): array;

    /** Which platform the authorization code should be exchanged against. */
    public function getPlatformSlug(): string;

    /* --- publishing / analytics -------------------------------------- */

    public function connect(array $credentials): array;
    public function disconnect(int $accountId): bool;
    public function publishPost(int $accountId, array $postData): array; // ['success'=>bool,'external_id'=>?,'error'=>?]
    public function getAccountInformation(int $accountId): array;
    public function getNotifications(int $accountId): array;
    public function getAnalytics(int $accountId, string $from, string $to): array;
    public function getPosts(int $accountId, int $limit = 10): array;
}
