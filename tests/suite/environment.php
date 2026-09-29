<?php
/**
 * Environment loading, APP_URL validation and runtime hardening.
 */

$runner->group('Environment / APP_URL');

$runner->run('loads secrets.php and exposes the keys the providers need', function () {
    foreach (['APP_URL', 'APP_KEY', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'SOCIAL_MODE'] as $key) {
        assert_true(env_get($key) !== null, "missing config key $key");
    }
});

$runner->run('APP_URL has no trailing slash and no stray backslashes', function () {
    $url = (string) env_get('APP_URL', '');
    assert_not_contains('\\', $url, 'APP_URL contains literal backslashes (JSON-escaped value)');
    assert_true(!str_ends_with($url, '/'), 'APP_URL must not end with a slash: ' . $url);
    assert_true((bool) preg_match('#^https?://#i', $url), 'APP_URL is not absolute: ' . $url);
});

$runner->run('a backslash-escaped APP_URL is rejected at boot', function () {
    $e = assert_throws(RuntimeException::class, function () {
        validate_app_url('http:\\/\\/example.com');
    });
    assert_contains('backslash', $e->getMessage());
});

$runner->run('a non-absolute APP_URL is rejected at boot', function () {
    $e = assert_throws(RuntimeException::class, function () {
        validate_app_url('example.com/social-hub');
    });
    assert_contains('absolute', $e->getMessage());
});

$runner->run('a trailing-slash APP_URL is rejected at boot', function () {
    $e = assert_throws(RuntimeException::class, function () {
        validate_app_url('https://example.com/social-hub/');
    });
    assert_contains('trailing slash', $e->getMessage());
});

$runner->run('a valid https APP_URL passes', function () {
    validate_app_url('https://hub.example.com');
    assert_true(true);
});

$runner->run('plain http is allowed by the boot check but warned about', function () {
    // Not fatal: the box is still being set up, so the app must stay bootable.
    validate_app_url('http://hub.example.com');
    $httpsWarnings = array_filter(env_warnings(), fn($w) => str_contains(strtolower($w), 'https'));
    assert_true($httpsWarnings !== [], 'expected an https warning for a plain-http APP_URL');
});

$runner->group('Environment / helpers');

$runner->run('env_bool coerces the usual truthy and falsy spellings', function () {
    foreach (['1', 'true', 'TRUE', 'yes', 'on'] as $v) {
        putenv("__T=$v");
        assert_true(env_bool('__T', false), "expected $v to be true");
    }
    foreach (['0', 'false', 'no', 'off'] as $v) {
        putenv("__T=$v");
        assert_false(env_bool('__T', true), "expected '$v' to be false");
    }
    // An empty value reads as unset, so the default wins.
    putenv('__T=');
    assert_true(env_bool('__T', true), 'empty value should fall back to the default');
    assert_false(env_bool('__T', false), 'empty value should fall back to the default');
    putenv('__T');
});

$runner->run('env_get falls back to the supplied default', function () {
    assert_same('fallback', env_get('__DEFINITELY_NOT_SET_12345', 'fallback'));
});

$runner->run('session hardening is applied to a fresh session', function () {
    assert_same('1', ini_get('session.use_strict_mode'));
    assert_same('1', ini_get('session.use_only_cookies'));
    assert_same('1', ini_get('session.cookie_httponly'));
});

$runner->run('errors are logged to a guarded file, not to the response', function () {
    $logFile = ini_get('error_log');
    assert_contains('storage/logs', (string) $logFile);
    assert_true(is_file($logFile), 'error log missing');
    $head = (string) file_get_contents($logFile, false, null, 0, 80);
    assert_contains('<?php', $head, 'error log is not PHP-guarded and could be served as source');
});

$runner->group('Environment / URL building');

$runner->run('oauth_callback_url is derived from APP_URL for every platform', function () {
    foreach (ProviderFactory::SUPPORTED as $slug) {
        $url = oauth_callback_url($slug);
        assert_contains((string) env_get('APP_URL'), $url, "callback for $slug ignores APP_URL");
        assert_contains('action=callback', $url);
        assert_contains('platform=' . $slug, $url);
    }
});

$runner->run('base_url falls back to APP_URL without a double slash', function () {
    assert_not_contains('//media', (string) base_url('/media'), 'base_url produced a double slash');
    assert_not_contains('\\', (string) base_url('/media'));
});
