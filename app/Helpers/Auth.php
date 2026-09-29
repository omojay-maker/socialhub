<?php
class Auth {
    public static function start(): void {
        if (session_status() === PHP_SESSION_NONE) {
            $secure = strtolower((string)env_get('SESSION_SECURE', 'auto')) === '1'
                || (strtolower((string)env_get('SESSION_SECURE', 'auto')) === 'auto' && request_is_https());

            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            ini_set('session.cookie_httponly', '1');
            ini_set('session.gc_maxlifetime', (string)max(300, (int)env_get('SESSION_TIMEOUT', 1800)));
            // sid_length / sid_bits_per_character are php.ini-only; the
            // deprecation on PHP 8.4 comes from writing them at runtime.
            if (function_exists('env_warn_php_ini_only')) {
                env_warn_php_ini_only('session.sid_length', '48');
                env_warn_php_ini_only('session.sid_bits_per_character', '5');
            }

            session_name('SHHUBSSID');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();

            // Bind the session to the client fingerprint to blunt hijacking.
            $fingerprint = hash('sha256', implode('|', [
                request_ip(),
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]));
            if (!isset($_SESSION['__fp'])) {
                $_SESSION['__fp'] = $fingerprint;
            } elseif (!hash_equals($_SESSION['__fp'], $fingerprint)) {
                self::destroy();
                session_start();
                $_SESSION['__fp'] = $fingerprint;
            }
        }
        self::touch();
    }

    public static function user(): ?array { return $_SESSION['user'] ?? null; }
    public static function check(): bool { return isset($_SESSION['user']['id']); }
    public static function isAdmin(): bool { return ($_SESSION['user']['role'] ?? '') === 'admin'; }
    public static function id(): ?int { return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null; }

    /** Sliding idle timeout. Returns false when the session was expired. */
    public static function touch(): bool {
        $timeout = (int)env_get('SESSION_TIMEOUT', 1800);
        if (isset($_SESSION['last_activity']) && time() - (int)$_SESSION['last_activity'] > $timeout) {
            self::destroy();
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function login(array $user): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        $_SESSION['login_at'] = time();
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => (bool)$p['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    public static function destroy(): void {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
