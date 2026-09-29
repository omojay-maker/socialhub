<?php
/**
 * Entry point for the test suite.
 *
 *   php tests/run.php
 *
 * Exits 0 when everything passes, 1 otherwise, so CI can gate on it.
 * Requires config/secrets.php to point at a reachable database for the
 * database-backed groups; those are skipped with a notice otherwise.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__);
chdir($root);

require $root . '/tests/TestCase.php';
require $root . '/config/environment.php';
require $root . '/app/Helpers/helpers.php';

$dbAvailable = false;
try {
    require $root . '/config/database.php';
    $dbAvailable = (bool) db()->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Database unavailable: " . $e->getMessage() . "\n");
}

if (!$dbAvailable) {
    require $root . '/app/Models/SocialAccount.php';
    require $root . '/app/Services/ProviderFactory.php';
}
if ($dbAvailable) {
    // ProviderFactory reads social_accounts for the connected counts.
    foreach (['SocialAccount', 'Media'] as $model) {
        require_once $root . "/app/Models/$model.php";
    }
    require $root . '/app/Services/ProviderFactory.php';
}

$runner = new TestRunner();

$suites = ['oauth1_signature', 'pkce', 'http', 'environment', 'platforms', 'media'];
foreach ($suites as $suite) {
    $path = $root . "/tests/suite/$suite.php";
    if (!is_file($path)) {
        fwrite(STDERR, "Missing suite: $path\n");
        exit(1);
    }
    if (in_array($suite, ['platforms', 'media'], true) && !$dbAvailable) {
        echo "\n\033[33mSKIPPED\033[0m $suite (no database)\n";
        continue;
    }
    require $path;
}

exit($runner->summary());
