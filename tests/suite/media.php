<?php
/**
 * Media delivery. The platform fetchers pull the images and videos referenced
 * by a post, so a broken or predictable media URL means a silent publish
 * failure that only shows up on the platform's side.
 */

$runner->group('Media delivery');

$runner->run('uploaded filenames are unguessable', function () {
    // media.php only serves names matching this pattern.
    $name = bin2hex(random_bytes(16)) . '.jpg';
    assert_true((bool) preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $name), $name);
    // 128 bits of entropy, so stored uploads cannot be walked or guessed.
    assert_same(32, strlen(bin2hex(random_bytes(16))));
});

$runner->run('media_url is signed and carries the filename and signature', function () {
    $name = '64e32f7b317ff9e8b3d36c7f50cb8d9d.jpg';
    $url  = media_url($name);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    assert_same($name, $q['f'] ?? null, 'filename missing from signed URL');
    assert_true(!empty($q['sig']), 'unsigned media URL');
});

$runner->run('a media signature is rejected when the filename is tampered with', function () {
    $a = media_url('64e32f7b317ff9e8b3d36c7f50cb8d9d.jpg');
    $b = media_url('5e65d5bc420cf292ca7772cb4b728123.jpg');
    parse_str((string) parse_url($a, PHP_URL_QUERY), $qa);
    parse_str((string) parse_url($b, PHP_URL_QUERY), $qb);
    assert_true(($qa['sig'] ?? '') !== ($qb['sig'] ?? ''),
        'the signature is not bound to the filename, so any stored file could be swapped in');
});

$runner->run('media_url produces an absolute https URL in production', function () {
    $url = media_url('64e32f7b317ff9e8b3d36c7f50cb8d9d.jpg');
    assert_true((bool) preg_match('#^https?://#i', $url), 'relative media URL: ' . $url);
    // The platform fetchers run outside the app, so they need the public host.
    assert_contains((string) env_get('APP_URL'), $url, 'media URL is not built from APP_URL');
});

$runner->group('Media / database rows');

$runner->run('seeded media filenames are servable by media.php', function () {
    $rows = db()->query('SELECT filename FROM media')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $filename) {
        assert_true(
            (bool) preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', (string) $filename),
            "media.php will refuse to serve '$filename' (needs 32 hex chars + extension)"
        );
    }
});

$runner->run('every seeded media file exists on disk', function () {
    foreach (db()->query('SELECT filename FROM media')->fetchAll(PDO::FETCH_COLUMN) as $filename) {
        assert_true(is_file(media_storage_path((string) $filename)), "missing file on disk: $filename");
    }
});

$runner->run('media file_path is relative, matching what the uploader writes', function () {
    // MediaModel::create() stores 'uploads/<name>', so the seed must agree.
    foreach (db()->query('SELECT filename, file_path FROM media')->fetchAll() as $r) {
        assert_same('uploads/' . $r['filename'], $r['file_path'], "file_path disagrees for {$r['filename']}");
    }
});
