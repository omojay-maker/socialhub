<?php
/**
 * Installs the demo images that database/seed.sql references.
 *
 * The files are tracked in database/demo-media/ but storage/uploads/ is
 * gitignored, so a fresh clone would otherwise be seeded with media rows
 * pointing at files that do not exist and the media grid would render broken
 * thumbnails. Safe to re-run: existing files are left alone.
 *
 *   php scripts/seed_demo_media.php
 */

require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../app/Helpers/helpers.php';

$source = dirname(__DIR__) . '/database/demo-media';
$files  = glob($source . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE) ?: [];

if (!$files) {
    fwrite(STDERR, "No demo media found in $source\n");
    exit(1);
}

$installed = $skipped = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $name)) {
        // media.php only serves names like this, so refuse anything else
        // rather than installing a file nothing can fetch.
        fwrite(STDERR, "skipping $name: not a servable filename\n");
        $skipped++;
        continue;
    }
    $dest = media_storage_path($name);
    if (is_file($dest)) {
        $skipped++;
        continue;
    }
    if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0775, true);
    if (!copy($file, $dest)) {
        fwrite(STDERR, "failed to install $name\n");
        exit(1);
    }
    chmod($dest, 0644);
    printf("installed %s (%d bytes)\n", $name, filesize($dest));
    $installed++;
}

printf("done: %d installed, %d already present or skipped\n", $installed, $skipped);
