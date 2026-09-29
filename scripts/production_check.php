<?php
/**
 * Production readiness check.
 *
 *   php scripts/production_check.php            audit this host
 *   php scripts/production_check.php --url URL  audit a deployed origin
 *
 * Exit code 0 = ready, 1 = blocking problems found.
 */
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Helpers/Logger.php';
require_once __DIR__ . '/../app/Helpers/helpers.php';
require_once __DIR__ . '/../app/Services/ProviderFactory.php';
require_once __DIR__ . '/../app/Models/SocialAccount.php';

$root = dirname(__DIR__);
$base = 'http://localhost/PHP_projects/social-hub';
$blocks = 0; $warns = 0; $passes = 0;

function head(string $t): void { echo "\n\033[1m$t\033[0m\n"; }
function ok(string $m): void   { global $passes; $passes++; echo "  \033[32mPASS\033[0m  $m\n"; }
function warn(string $m): void { global $warns;  $warns++;  echo "  \033[33mWARN\033[0m  $m\n"; }
function bad(string $m): void  { global $blocks; $blocks++; echo "  \033[31mFAIL\033[0m  $m\n"; }
function check(bool $cond, string $pass, string $fail): void { $cond ? ok($pass) : bad($fail); }

/* ------------------------------------------------------------- secrets --- */

head('Secrets and configuration');
$appKey = (string)env_get('APP_KEY', '');
check($appKey !== '' && strlen($appKey) >= 32, 'APP_KEY is set', 'APP_KEY is missing or too short - token encryption is unsafe');
check(!app_key_is_configured() === false, 'APP_KEY is loaded by this process', 'APP_KEY not visible to this process');
check(is_readable($root . '/config/secrets.php'), 'config/secrets.php is readable', 'config/secrets.php is not readable by the web user');
$secretsPerm = fileperms($root . '/config/secrets.php') & 0777;
check(($secretsPerm & 0022) === 0, 'config/secrets.php is not writable by group/other',
    'config/secrets.php is group/world writable (' . decoct($secretsPerm) . ') - run: chmod 640 config/secrets.php');
if (($secretsPerm & 0044) !== 0) {
    warn('config/secrets.php is readable by every local user (' . decoct($secretsPerm) . ') - use 640 plus a shared group for the web user');
}
$dbUser = (string)env_get('DB_USERNAME', '');
check($dbUser !== 'root', "database user is not root ($dbUser)", "database user is root - use a least-privilege account");
check((string)env_get('DB_PASSWORD', '') !== '', 'database password is set', 'database password is empty');
check(env_is_production(), 'APP_ENV is production', 'APP_ENV is ' . env_get('APP_ENV') . ' - fine for development only');
check(!env_app_debug(), 'APP_DEBUG is off', 'APP_DEBUG is on - stack traces can leak');

/* ---------------------------------------------------------------- http --- */

head('Web exposure (must be blocked outside public/)');
$targets = [
    '.env'                      => 404,
    '.git/config'               => 404,
    'config/database.php'       => 403,
    'config/secrets.php'        => 403,
    'app/Helpers/Auth.php'      => 403,
    'database/schema.sql'       => 403,
    'storage/logs/app-log.php'  => 403,
    'frontend/package.json'     => 403,
    'README.md'                 => 403,
    'composer.json'             => 403,
];
$url = null;
foreach ($argv as $i => $a) { if ($a === '--url' && isset($argv[$i + 1])) $url = rtrim($argv[$i + 1], '/'); }
if ($url) {
    foreach ($targets as $path => $_) {
        $ch = curl_init($url . '/' . $path);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 8, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code === 404 || $code === 403) ok(sprintf('%-30s %d', $path, $code));
        else bad(sprintf('%-30s %d  (downloadable!)', $path, $code));
    }
} else {
    warn('skipped (pass --url https://your-host to test a deployed origin)');
}

/* ------------------------------------------------------------- runtime --- */

head('Runtime');
check(PHP_SAPI !== 'cli' || PHP_VERSION_ID >= 80100, 'PHP ' . PHP_VERSION, 'PHP ' . PHP_VERSION . ' is older than 8.1');
foreach (['curl', 'openssl', 'fileinfo', 'pdo_mysql', 'mbstring'] as $ext) {
    extension_loaded($ext) ? ok("extension $ext") : bad("extension $ext is missing");
}
check(ini_get('display_errors') == '0', 'display_errors is off', 'display_errors is on');
check((int)ini_get('session.use_strict_mode') === 1, 'strict session mode', 'session.use_strict_mode is off');
check((int)ini_get('session.use_only_cookies') === 1, 'cookie-only sessions', 'session.use_only_cookies is off');
check((int)ini_get('session.cookie_httponly') === 1, 'HttpOnly session cookie', 'session.cookie_httponly is off');

/* ------------------------------------------------------------ platforms -- */

head('Platform connections');
foreach (ProviderFactory::statusForUi() as $p) {
    $label = str_pad($p['name'], 10);
    if ($p['is_configured']) ok("$label configured, {$p['connected']} connected");
    else warn("$label not configured - add its client id, secret and redirect URI");
}
if (!ProviderFactory::liveMode()) {
    warn('SOCIAL_MODE is mock - publishing is simulated, set it to live for real posting');
} else {
    ok('SOCIAL_MODE is live');
}
$publicMedia = (string)env_get('PUBLIC_MEDIA_BASE', '');
if (ProviderFactory::liveMode()) {
    check($publicMedia !== '', 'PUBLIC_MEDIA_BASE is set (platforms fetch media over HTTP)',
        'PUBLIC_MEDIA_BASE is empty - Instagram/Facebook cannot download your media');
    if ($publicMedia !== '') {
        check(str_starts_with($publicMedia, 'https://'), 'PUBLIC_MEDIA_BASE uses HTTPS',
            "PUBLIC_MEDIA_BASE is $publicMedia - platforms require HTTPS");
    }
}

/* ----------------------------------------------------------------- db ---- */

head('Database');
try {
    $pdo = db();
    ok('connected as ' . $pdo->query('SELECT CURRENT_USER()')->fetchColumn());
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    ok(count($tables) . ' tables present');
    $migrated = $pdo->query("SELECT COUNT(*) FROM schema_migrations")->fetchColumn();
    $files = glob($root . '/database/migrations/*.sql') ?: [];
    $applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_diff(array_map('basename', $files), $applied);
    check($missing === [], 'all migrations applied (' . count($applied) . ')', 'pending migrations: ' . implode(', ', $missing));

    $plain = (int)$pdo->query("SELECT COUNT(*) FROM social_accounts WHERE access_token IS NOT NULL AND access_token NOT LIKE 'v1:%'")->fetchColumn();
    check($plain === 0, 'no plaintext tokens in social_accounts', "$plain token(s) stored in plaintext - re-save the account to encrypt");
} catch (Throwable $e) {
    bad('database error: ' . $e->getMessage());
}

/* ------------------------------------------------------------- summary --- */

echo "\n" . str_repeat('-', 58) . "\n";
printf("  %d passed, %d warnings, %d blocking problems\n", $passes, $warns, $blocks);
echo $blocks === 0
    ? "  \033[32mREADY\033[0m - safe to go live once the warnings are reviewed.\n\n"
    : "  \033[31mNOT READY\033[0m - fix the FAIL items above.\n\n";
exit($blocks === 0 ? 0 : 1);
