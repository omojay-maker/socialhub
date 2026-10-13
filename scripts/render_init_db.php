<?php
/**
 * Database bootstrap for fresh installs (Render, CI, local pg).
 *
 *   php scripts/render_init_db.php
 *
 * Runs database/schema.sql then database/seed.sql through PDO, so no psql
 * client is needed inside the container.
 *
 * Also called automatically by public/api/csrf.php the first time the app is
 * hit with an empty database, so a fresh deploy self-initializes. Afterwards
 * it is a no-op: when the users table exists, only the idempotent seed runs.
 */
require_once __DIR__ . '/../config/environment.php';
env_load();
require_once __DIR__ . '/../config/database.php';

function init_statements(string $sql): array {
    $sql = (string)preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map(
        'trim',
        preg_split('/;\s*(?:\r\n|\n|$)/', $sql)
    )));
}

/**
 * Bring an empty database to a working seeded state.
 * Returns ['initialized' => bool, 'ran' => string[]]. Never drops tables on
 * a database that already has users.
 */
function render_init_db(): array {
    $pdo = db();
    // Initialized = users table exists and holds rows. Anything less gets
    // the full schema + seed; afterwards the idempotent seed alone keeps
    // data converging without ever dropping tables.
    $hasUsers = false;
    try {
        $hasUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (Throwable $e) {
        $hasUsers = false; // fresh/empty database
    }

    $files = $hasUsers ? ['database/seed.sql'] : ['database/schema.sql', 'database/seed.sql'];
    $ran = [];
    foreach ($files as $rel) {
        $path = dirname(__DIR__) . '/' . $rel;
        if (!is_file($path)) {
            throw new RuntimeException("missing: $rel");
        }
        $done = 0;
        foreach (init_statements((string)file_get_contents($path)) as $stmt) {
            $pdo->query($stmt);
            $done++;
        }
        $ran[] = "$rel: $done statement(s) ok";
    }
    return ['initialized' => $hasUsers, 'ran' => $ran];
}

// Direct CLI invocation: php scripts/render_init_db.php
if ((realpath($_SERVER['SCRIPT_FILENAME'] ?? '') ?: '') === __FILE__) {
    echo 'driver: ' . db_driver() . "\n";
    try {
        $res = render_init_db();
        if ($res['initialized']) echo "database already initialized — seed top-up only\n";
        foreach ($res['ran'] as $line) echo "$line\n";
        $counts = [];
        foreach (['users', 'social_platforms', 'social_accounts', 'posts'] as $t) {
            $counts[] = "$t=" . db()->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        }
        echo 'counts: ' . implode(' ', $counts) . "\n";
        echo "done\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
