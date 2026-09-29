<?php
/**
 * Connected channel listing, manual registration and stat refresh.
 * Real connections go through public/api/oauth.php.
 */
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../app/Helpers/Logger.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/SocialAccount.php';
require_once __DIR__.'/../../app/Services/ProviderFactory.php';

define('BASE_URL', env_get('APP_URL', base_url_public()));

env_send_security_headers();
json_no_store();

env_send_security_headers();
Auth::start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

/** CSRF for every state-changing request. */
function require_csrf(): void {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = (string)($in['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!Csrf::verify($token)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
        exit;
    }
}

if (($action === '' || $action === 'list') && $method === 'GET') {
    echo json_encode([
        'success'   => true,
        'accounts'  => SocialAccountModel::all(),
        'platforms' => SocialAccountModel::platforms(),
        'mode'      => ProviderFactory::liveMode() ? 'live' : 'mock',
    ]);
    exit;
}

/* Manual registration (no API available for the platform). */
if ($action === 'create' && $method === 'POST') {
    if (!Auth::isAdmin()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Admin only']); exit; }
    require_csrf();

    $post = $_POST;
    if (!$post) { $post = json_decode(file_get_contents('php://input'), true) ?: []; }

    $pid = (int)($post['platform_id'] ?? 0);
    $name = trim((string)($post['account_name'] ?? ''));
    $username = trim((string)($post['username'] ?? ''));
    if (!$pid || $name === '' || $username === '') {
        echo json_encode(['success'=>false,'message'=>'All fields are required']);
        exit;
    }
    $platform = db()->prepare("SELECT slug FROM social_platforms WHERE id=? AND is_active=1");
    $platform->execute([$pid]);
    $slug = (string)($platform->fetchColumn() ?: '');
    if ($slug === '') { echo json_encode(['success'=>false,'message'=>'Unknown platform']); exit; }

    $id = SocialAccountModel::create([
        'platform_id'   => $pid,
        'account_name'  => $name,
        'username'      => $username,
        'account_type'  => 'manual',
        'followers'     => 0,
    ]);
    activity_log(Auth::id(), 'account.connected', "Registered account #$id manually");
    echo json_encode(['success'=>true,'message'=>"{$name} added (manual, no API sync)",'id'=>$id]);
    exit;
}

/* Refresh audience numbers from the platform. */
if ($action === 'sync') {
    if ($method === 'POST') require_csrf();

    $id = (int)($_GET['id'] ?? 0);
    $account = SocialAccountModel::find($id);
    if (!$account) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Account not found']); exit; }
    if (!Auth::isAdmin() && (int)($account['user_id'] ?? 0) !== (int)Auth::id()) {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Not allowed']); exit;
    }

    $provider = ProviderFactory::make((string)$account['slug']);
    $info = $provider->getAccountInformation($id);

    if (!empty($info['error'])) {
        echo json_encode(['success'=>false,'message'=>'Sync failed: ' . $info['error']]);
        exit;
    }
    if (isset($info['followers'])) {
        SocialAccountModel::updateStats($id, (int)$info['followers'], $info['meta'] ?? null);
    }
    SocialAccountModel::updateSync($id);
    activity_log(Auth::id(), 'account.synced', "Synced account #$id");

    $notice = $info['note'] ?? null;
    echo json_encode([
        'success' => true,
        'message' => !empty($info['demo']) ? 'Synced (demo data)' : 'Synced from ' . $account['platform_name'] . ($notice ? ' — ' . $notice : ''),
        'data'    => $info,
    ]);
    exit;
}

if ($action === 'delete' && $method === 'POST') {
    if (!Auth::isAdmin()) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Admin only']); exit; }
    require_csrf();
    $id = (int)($_GET['id'] ?? 0);
    SocialAccountModel::delete($id);
    activity_log(Auth::id(), 'account.disconnected', "Removed account #$id");
    echo json_encode(['success'=>true,'message'=>'Disconnected']);
    exit;
}

http_response_code(404);
echo json_encode(['success'=>false,'message'=>'Unknown accounts action']);
