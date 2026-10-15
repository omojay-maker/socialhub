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
 * a database that already has users. Also applies any pending migrations so
 * deploys converge without a separate migrate step.
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

    $ran = [];
    $files = $hasUsers ? ['database/seed.sql'] : ['database/schema.sql', 'database/seed.sql'];
    foreach ($files as $rel) {
        $ran[] = init_db_file($pdo, $rel);
    }

    // Pending migrations (best-effort; duplicate objects are already applied).
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
)");
    $applied = [];
    foreach ($pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $applied[$f] = true;
    }
    $migrations = glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [];
    sort($migrations);
    foreach ($migrations as $file) {
        $name = basename($file);
        if (isset($applied[$name])) continue;
        $done = 0;
        foreach (init_statements((string)file_get_contents($file)) as $stmt) {
            try {
                $pdo->query($stmt);
                $done++;
            } catch (PDOException $e) {
                $msg = strtolower($e->getMessage());
                $state = (string)($e->errorInfo[0] ?? '');
                if (str_contains($msg, 'already exists') || in_array($state, ['42P07', '42701', '42710'], true)) {
                    continue;
                }
                throw $e;
            }
        }
        if (db_is_pgsql()) {
            $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (?) ON CONFLICT (filename) DO NOTHING")->execute([$name]);
        } else {
            $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename) VALUES (?)")->execute([$name]);
        }
        $ran[] = "$name: $done statement(s) ok";
    }
    return ['initialized' => $hasUsers, 'ran' => $ran];
}

function init_db_file(PDO $pdo, string $rel): string {
    $path = dirname(__DIR__) . '/' . $rel;
    if (!is_file($path)) {
        throw new RuntimeException("missing: $rel");
    }
    $done = 0;
    foreach (init_statements((string)file_get_contents($path)) as $stmt) {
        $pdo->query($stmt);
        $done++;
    }
    return "$rel: $done statement(s) ok";
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
