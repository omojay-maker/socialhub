<?php
/**
 * Environment loader + production runtime hardening.
 *
 * Precedence (first hit wins per key):
 *   1. real environment variable (systemd / Docker / php-fpm pool)
 *   2. file named by the SOCIAL_HUB_ENV_FILE environment variable
 *   3. config/secrets.php  (PHP file — never served as plain text)
 *   4. .env in the project root (development convenience only)
 */
function env_load(?string $path = null): void {
    if ($path !== null) {
        env_ingest_file($path);
        return;
    }
    $file = getenv('SOCIAL_HUB_ENV_FILE');
    if (is_string($file) && $file !== '' && is_readable($file)) {
        env_ingest_file($file);
    }
    $secrets = __DIR__ . '/secrets.php';
    if (is_readable($secrets)) {
        env_ingest_secrets($secrets);
    }
    $dotenv = dirname(__DIR__) . '/.env';
    if (is_readable($dotenv)) {
        env_ingest_file($dotenv);
    }
}

function env_ingest_file(string $path): void {
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        env_put(trim($k), trim(trim($v), "\"'"));
    }
}

function env_ingest_secrets(string $path): void {
    $data = require $path;
    if (!is_array($data)) return;
    foreach ($data as $k => $v) {
        if ($v === null || $v === '') continue;
        env_put((string)$k, (string)$v);
    }
}

function env_put(string $key, string $value): void {
    if (getenv($key) !== false) return;          // real env vars always win
    if (isset($_ENV[$key])) return;
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("$key=$value");
}

function env_get(string $key, $default = null) {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

function env_bool(string $key, bool $default = false): bool {
    $v = env_get($key);
    if ($v === null) return $default;
    return in_array(strtolower((string)$v), ['1', 'true', 'yes', 'on'], true);
}

function env_is_production(): bool {
    return strtolower((string)env_get('APP_ENV', 'production')) === 'production';
}

function env_app_debug(): bool {
    return env_bool('APP_DEBUG', !env_is_production());
}

/**
 * Non-fatal configuration problems collected during boot and reported by
 * scripts/production_check.php and the Accounts screen.
 */
function env_warnings(): array {
    global $__env_warnings;
    return is_array($__env_warnings ?? null) ? $__env_warnings : [];
}
function env_add_warning(string $message): void {
    global $__env_warnings;
    if (!is_array($__env_warnings ?? null)) $__env_warnings = [];
    if (!in_array($message, $__env_warnings, true)) $__env_warnings[] = $message;
}

/**
 * Pure validator for an APP_URL candidate. Kept separate from the env-reading
 * wrapper below so it can be unit tested.
 *
 * Throws for anything structurally broken; records a warning for a plain-http
 * origin, which is tolerated while the box is still being set up but blocks
 * live OAuth at ?action=start.
 */
function validate_app_url(string $url, ?bool $production = null): void {
    $production ??= env_is_production();
    $url = trim($url);

    if ($url === '') {
        if ($production) throw new RuntimeException('APP_URL is required in production');
        return;
    }
    if (str_contains($url, '\\')) {
        throw new RuntimeException(
            'APP_URL contains backslashes, which usually means a JSON-escaped value '
            . 'was pasted in. Use a plain URL: https://host/path'
        );
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        throw new RuntimeException('APP_URL must be an absolute http(s) URL, got: ' . $url);
    }
    if (rtrim($url, '/') !== $url) {
        throw new RuntimeException('APP_URL must not end with a trailing slash: ' . $url);
    }
    if (str_starts_with(strtolower($url), 'https://')) return;

    env_add_warning($production
        ? 'APP_URL is not https. Every platform OAuth provider rejects plain-http redirect '
          . 'URIs, and the session cookie will not be marked Secure. Serving over http also '
          . 'makes signed media URLs unreachable from the platform fetchers.'
        : 'APP_URL is not https; fine for local mock mode, but live OAuth requires TLS.');
}

/** Boot-time check against the configured APP_URL. */
function env_validate_app_url(): void {
    validate_app_url((string) env_get('APP_URL', ''));
}

function request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    $trusted = array_filter(array_map('trim', explode(',', (string)env_get('TRUSTED_PROXIES', ''))));
    $remote  = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($trusted && in_array($remote, $trusted, true)) {
        $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($proto === 'https') return true;
    }
    return false;
}

function request_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/**
 * Some session settings can only be changed in php.ini. Rather than writing
 * them at runtime (deprecated on PHP 8.4), report the drift once per request.
 */
function env_warn_php_ini_only(string $key, string $expected): void {
    $current = (string)ini_get($key);
    if ($current === $expected || $current === '') return;
    error_log(sprintf(
        '[config] %s is %s but %s is recommended; set it in php.ini. '
        . 'Editing it from PHP is deprecated as of PHP 8.4.',
        $key, $current, $expected
    ));
}

/** Runtime hardening: never leak internals through the response body. */
function env_runtime_hardening(): void {
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    // Guarded with a PHP 404 header so the file cannot be read over HTTP.
    ini_set('error_log', (function () use ($logDir): string {
        $file = $logDir . '/php-error.php';
        if (!is_file($file)) @file_put_contents($file, '<?php http_response_code(404); exit; ?>' . "\n", LOCK_EX);
        return $file;
    })());
    error_reporting(E_ALL);

    // Session hardening for every entry point (web, CLI and cron). These are
    // rejected once the session is active or output has started, so they are
    // only applied to a fresh session.
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
    }
    // session.sid_length / session.sid_bits_per_character are PHP_INI_ALL but
    // emit a deprecation when changed after a session has started, and cannot
    // be set at all on PHP >= 8.4. They are only php.ini settings, so warn
    // instead of writing them at runtime.
    env_warn_php_ini_only('session.sid_length', '48');
    env_warn_php_ini_only('session.sid_bits_per_character', '5');

    if (env_is_production()) {
        ini_set('zend.exception_ignore_args', '1');
        ini_set('expose_php', '0');
    }

    $isApi = str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    set_exception_handler(function (Throwable $e) use ($isApi, $logDir): void {
        error_log(sprintf('[uncaught] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        $debug = env_app_debug();
        echo json_encode([
            'success' => false,
            'message' => $debug ? $e->getMessage() : 'Internal server error',
            'error'   => $debug ? ['type' => get_class($e), 'at' => $e->getFile() . ':' . $e->getLine()] : null,
        ]);
    });
    register_shutdown_function(function () use ($isApi): void {
        $err = error_get_last();
        if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
        error_log('[fatal] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        if ($isApi) echo json_encode(['success' => false, 'message' => 'Internal server error']);
    });
}

/** Security headers for every HTML/JSON response. */
function env_send_security_headers(bool $withCsp = false): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
    header_remove('X-Powered-By');
    if (request_is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    if ($withCsp) {
        header('Content-Security-Policy: ' . env_csp());
    }
}

function env_csp(): string {
    $fonts = "https://fonts.googleapis.com";
    $fontFiles = "https://fonts.gstatic.com";
    $img = "'self' data: blob:";
    if (($media = env_get('PUBLIC_MEDIA_BASE', '')) !== '') {
        $img .= ' ' . $media;
    }
    $connect = "'self'";
    if (($origins = env_get('PLATFORM_API_ORIGINS', '')) !== '') {
        $connect .= ' ' . $origins;
    }
    return implode('; ', [
        "default-src 'self'",
        // index.html carries an inline theme-boot script, hence 'unsafe-inline'.
        "script-src 'self' 'unsafe-inline'",
        "style-src 'self' 'unsafe-inline' $fonts",
        "font-src 'self' $fontFiles data:",
        "img-src $img",
        "connect-src $connect",
        "form-action 'self'",
        "frame-ancestors 'none'",
        "base-uri 'self'",
        "object-src 'none'",
    ]);
}

env_load();
env_validate_app_url();
env_runtime_hardening();
