<?php
/**
 * Forward-only migration runner.
 *
 *   php scripts/migrate.php            apply pending migrations
 *   php scripts/migrate.php --status   list applied / pending
 *   php scripts/migrate.php --fresh --i-know-this-is-dev   wipe and re-apply
 *
 * database/schema.sql is the reference DDL for a fresh install. It is
 * destructive (DROP TABLE) and must never be run against a live database.
 *
 * MySQL auto-commits DDL, so statements are not wrapped in a transaction.
 * Duplicate-object errors are treated as "already applied", which keeps the
 * migrations replayable after a partial failure.
 */
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Helpers/Logger.php';

const MIGRATION_BENIGN_ERRORS = [
    1050, // table already exists
    1051, // unknown table (on drop)
    1060, // duplicate column name
    1061, // duplicate key name
    1062, // duplicate unique key
    1826, // duplicate foreign key constraint name
];

function migration_statements(string $sql): array {
    // Strip comment lines, then split on semicolons that end a line.
    $sql = preg_replace('/^\s*(--|\/\*).*$/m', '', $sql);
    return array_values(array_filter(array_map(
        'trim',
        preg_split('/;\s*(?:\r\n|\n|$)/', (string)$sql)
    )));
}

$dir  = __DIR__ . '/../database/migrations';
$mode = $argv[1] ?? 'apply';
$pdo  = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$files = glob($dir . '/*.sql') ?: [];
sort($files);

$applied = [];
foreach ($pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN) as $f) {
    $applied[$f] = true;
}

if (in_array($mode, ['--fresh', 'fresh'], true)) {
    if (!in_array('--i-know-this-is-dev', $argv, true)) {
        fwrite(STDERR, "Refusing to run --fresh without --i-know-this-is-dev\n");
        exit(1);
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
        echo "dropped   $table\n";
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    $applied = [];
}

if (in_array($mode, ['--status', 'status'], true)) {
    $pending = 0;
    foreach ($files as $file) {
        $name = basename($file);
        $state = isset($applied[$name]) ? 'applied' : 'pending';
        $pending += $state === 'pending' ? 1 : 0;
        printf("%-46s %s\n", $name, $state);
    }
    echo $pending === 0 ? "up to date\n" : "$pending migration(s) pending\n";
    exit(0);
}

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) continue;

    $done = 0;
    $skipped = 0;
    foreach (migration_statements((string)file_get_contents($file)) as $stmt) {
        try {
            $pdo->exec($stmt);
            $done++;
        } catch (PDOException $e) {
            $code = (int)($e->errorInfo[1] ?? 0);
            if (in_array($code, MIGRATION_BENIGN_ERRORS, true)) {
                $skipped++;
                continue;
            }
            fwrite(STDERR, sprintf("FAILED   %s\n  %s\n", $name, $e->getMessage()));
            Logger::error('migration failed', ['file' => $name, 'error' => $e->getMessage()]);
            exit(1);
        }
    }

    $ins = $pdo->prepare("INSERT IGNORE INTO schema_migrations (filename) VALUES (?)");
    $ins->execute([$name]);
    printf("applied  %-42s %d statement(s)%s\n", $name, $done, $skipped ? ", $skipped already present" : '');
}

echo "up to date\n";
