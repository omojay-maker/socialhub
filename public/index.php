<?php
/**
 * SPA entry point.
 *
 * Serves the built Vite bundle. The backend is API-only (public/api/*).
 * The project root is not web-served, so this file is the only HTML route.
 */
require_once __DIR__ . '/../config/environment.php';

env_send_security_headers(withCsp: true);
header('Content-Type: text/html; charset=utf-8');

$dist = __DIR__ . '/dist/index.html';

if (!is_file($dist)) {
    http_response_code(503);
    header('Retry-After: 60');
    $msg = env_app_debug()
        ? 'Frontend bundle missing. Run: cd frontend && npm install && npm run build'
        : 'Service temporarily unavailable';
    echo '<!doctype html><meta charset="utf-8"><title>Unavailable</title>'
       . '<body style="font:15px system-ui;padding:40px"><h1>Frontend not built</h1><p>'
       . htmlspecialchars($msg, ENT_QUOTES) . '</p>';
    exit;
}

$html = (string)file_get_contents($dist);
$etag = '"' . md5($html) . '"';

if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('ETag: ' . $etag);
header('Cache-Control: no-cache, must-revalidate');
echo $html;
