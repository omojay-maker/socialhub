<?php
/**
 * Media delivery endpoint.
 *
 * The project root is not web-served, so uploads are streamed from here
 * instead of being exposed as static files.
 *
 *   /media.php?f=<name>            -> requires a logged-in session
 *   /media.php?f=<name>&sig=<hmac> -> unlisted link for platform APIs
 *                                       (Instagram/Facebook fetch media
 *                                        over the public internet)
 */
require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Helpers/Auth.php';
require_once __DIR__ . '/../app/Helpers/helpers.php';
require_once __DIR__ . '/../app/Helpers/Logger.php';

const MEDIA_DIR = __DIR__ . '/../storage/uploads';

$name = (string)($_GET['f'] ?? '');
$sig  = (string)($_GET['sig'] ?? '');

if (!preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $name)) {
    http_response_code(400);
    exit('Bad request');
}

$file = MEDIA_DIR . '/' . $name;
if (!is_file($file)) {
    http_response_code(404);
    exit('Not found');
}

$signed = $sig !== '' && media_signature_valid($name, $sig);
if (!$signed) {
    Auth::start();
    if (!Auth::check()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

$mime = media_mime($name);
$size = filesize($file);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
if ($signed) {
    header('Cache-Control: public, max-age=604800, immutable');
}
header('Accept-Ranges: none');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD') {
    exit;
}

readfile($file);
