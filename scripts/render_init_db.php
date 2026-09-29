<?php
/**
 * One-shot database bootstrap for fresh installs (Render, CI, local pg).
 *
 *   php scripts/render_init_db.php
 *
 * Runs database/schema.sql then database/seed.sql through PDO, so no psql
 * client is needed inside the container. Safe to re-run: the schema drops
 * and recreates (fresh databases only — never point this at live data), and
 * the seed is idempotent.
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

$pdo = db();
echo 'driver: ' . db_driver() . "\n";

foreach (['database/schema.sql', 'database/seed.sql'] as $rel) {
    $path = dirname(__DIR__) . '/' . $rel;
    if (!is_file($path)) {
        fwrite(STDERR, "missing: $rel\n");
        exit(1);
    }
    $done = 0;
    foreach (init_statements((string)file_get_contents($path)) as $stmt) {
        try {
            $pdo->query($stmt);
            $done++;
        } catch (PDOException $e) {
            fwrite(STDERR, "FAILED in $rel:\n  " . substr($stmt, 0, 160) . "\n  " . $e->getMessage() . "\n");
            exit(1);
        }
    }
    echo "$rel: $done statement(s) ok\n";
}

$counts = [];
foreach (['users', 'social_platforms', 'social_accounts', 'posts'] as $t) {
    $counts[] = "$t=" . $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
echo 'counts: ' . implode(' ', $counts) . "\n";
echo "done\n";
