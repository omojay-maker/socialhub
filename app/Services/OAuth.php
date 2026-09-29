<?php
/**
 * OAuth 2.0 orchestration helpers: CSRF-protected `state`, redirect URIs and
 * post-callback flash messages.
 *
 * Platforms differ (Instagram reuses the Meta app, LinkedIn uses
 * /v2/oauth/authorization), so each provider builds its own URLs; this class
 * only owns the security-critical parts.
 */
class OAuth {
    const STATE_TTL = 600;      // 10 minutes
    const FLASH_KEY = '_oauth_flash';

    /**
     * Creates and stores a single-use state token for this browser session.
     */
    public static function issueState(string $platform, array $context = []): string {
        $state = bin2hex(random_bytes(24));
        $bucket = $_SESSION['oauth_states'] ?? [];
        // Keep the map small: drop everything that has expired.
        $now = time();
        foreach ($bucket as $k => $v) {
            if (!is_array($v) || ($v['ts'] ?? 0) + self::STATE_TTL < $now) unset($bucket[$k]);
        }
        $bucket[$state] = ['platform' => $platform, 'ts' => $now, 'ctx' => $context];
        $_SESSION['oauth_states'] = $bucket;
        return $state;
    }

    /**
     * Consumes a state token. Returns the stored context, or null if the
     * token is unknown, already used, expired, or bound to another platform.
     */
    public static function consumeState(string $state, string $platform): ?array {
        $bucket = $_SESSION['oauth_states'] ?? [];
        if (!isset($bucket[$state])) {
            Logger::warn('oauth state mismatch', ['platform' => $platform]);
            return null;
        }
        $entry = $bucket[$state];
        unset($bucket[$state]);           // single use
        $_SESSION['oauth_states'] = $bucket;

        if (($entry['platform'] ?? '') !== $platform) return null;
        if (time() - (int)($entry['ts'] ?? 0) > self::STATE_TTL) return null;
        return is_array($entry['ctx'] ?? null) ? $entry['ctx'] : [];
    }

    /** Where the SPA lives (not the API). */
    public static function appUrl(string $path = ''): string {
        $base = rtrim((string)env_get('APP_URL', base_url_public()), '/');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }

    public static function flash(string $type, string $message): void {
        $_SESSION[self::FLASH_KEY] = ['type' => $type, 'message' => $message];
    }

    public static function takeFlash(): ?array {
        $flash = $_SESSION[self::FLASH_KEY] ?? null;
        unset($_SESSION[self::FLASH_KEY]);
        return is_array($flash) ? $flash : null;
    }

    /** Redirect helper used by the start/callback endpoints. */
    public static function redirect(string $url): void {
        if (!headers_sent()) header('Location: ' . $url, true, 302);
        exit;
    }

    public static function backWithFlash(string $type, string $message, string $path = 'accounts'): void {
        self::flash($type, $message);
        self::redirect(self::appUrl('public/#/' . ltrim($path, '/')));
    }
}
