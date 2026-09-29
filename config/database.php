<?php
require_once __DIR__.'/environment.php';

/**
 * Database driver selection.
 *
 *   DB_DRIVER=mysql|pgsql   explicit choice (default: mysql)
 *   DATABASE_URL            when present (Render Postgres injects this as
 *                           postgres://user:pass@host:port/dbname) it wins and
 *                           implies the driver from its scheme.
 */
function db_driver(): string {
    $d = strtolower((string)env_get('DB_DRIVER', ''));
    if ($d === 'pgsql' || $d === 'postgres' || $d === 'postgresql') return 'pgsql';
    if ($d === 'mysql' || $d === 'mariadb') return 'mysql';
    $url = (string)env_get('DATABASE_URL', '');
    if (preg_match('#^postgres(ql)?://#i', $url)) return 'pgsql';
    if (preg_match('#^mysql://#i', $url)) return 'mysql';
    return 'mysql';
}

function db_is_pgsql(): bool {
    return db_driver() === 'pgsql';
}

/** Case-insensitive match operator for the active driver. */
function sql_like(): string {
    return db_is_pgsql() ? 'ILIKE' : 'LIKE';
}

/** GROUP_CONCAT (MySQL) / STRING_AGG (PostgreSQL) aggregate helper. */
function sql_group_concat(string $expr, string $sep = ','): string {
    $sep = str_replace("'", "''", $sep);
    if (db_is_pgsql()) return "STRING_AGG($expr, '$sep')";
    return "GROUP_CONCAT($expr SEPARATOR '$sep')";
}

/** Current-timestamp SQL function, valid on both drivers. */
function sql_now(): string {
    return 'NOW()';
}

/**
 * Normalise a BOOLEAN column to 0/1 for JSON output and PHP truthiness.
 * MySQL returns 0/1, PostgreSQL may return true/false (or 't'/'f'),
 * and the two must behave identically downstream.
 */
function db_bool($v): int {
    if ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'T'
        || $v === 'true' || $v === 'TRUE' || $v === 'y' || $v === 'Y' || $v === 'on') return 1;
    return 0;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $url = (string)env_get('DATABASE_URL', '');
    if ($url !== '' && ($parts = parse_url($url)) !== false && isset($parts['host'])) {
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $driver = (str_starts_with($scheme, 'postgres')) ? 'pgsql' : 'mysql';
        $host = $parts['host'];
        $port = (string)($parts['port'] ?? ($driver === 'pgsql' ? '5432' : '3306'));
        $db   = ltrim((string)($parts['path'] ?? ''), '/');
        $user = isset($parts['user']) ? rawurldecode($parts['user']) : '';
        $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
    } else {
        $driver = db_driver();
        $host = env_get('DB_HOST', '127.0.0.1');
        $port = (string)env_get('DB_PORT', $driver === 'pgsql' ? '5432' : '3306');
        $db   = env_get('DB_DATABASE', 'social_hub');
        $user = env_get('DB_USERNAME', 'root');
        $pass = env_get('DB_PASSWORD', 'root');
    }

    // Managed Postgres (Render, Aiven) usually requires TLS; local dev may
    // not offer it. `prefer` negotiates up when available. Override with
    // DB_SSLMODE=require|disable when you need to force it.
    if ($driver === 'pgsql') {
        $sslmode = (string)env_get('DB_SSLMODE', 'prefer');
        $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=$sslmode";
    } else {
        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    }
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opts);
    return $pdo;
}
