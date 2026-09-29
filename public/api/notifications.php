<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/Notification.php';
define('BASE_URL', env_get('APP_URL','http://localhost/PHP_projects/social-hub'));

env_send_security_headers();
json_no_store();
Auth::start();
header('Content-Type: application/json');
if(!Auth::check()){ http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$action=$_GET['action']??'';
if($action==='' || $action==='list'){
    $platform = $_GET['platform'] ?? null;
    $type = $_GET['type'] ?? null;
    $notifications = NotificationModel::all($platform?:null,$type?:null,false);
    echo json_encode(['success'=>true,'notifications'=>$notifications,'unread'=>NotificationModel::unreadCount()]); exit;
}
$in=json_decode(file_get_contents('php://input'),true);
if($action==='read'){
    if(!Csrf::verify($in['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')){ echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    NotificationModel::markRead((int)($_GET['id']??0));
    echo json_encode(['success'=>true]); exit;
}
if($action==='read_all'){
    if(!Csrf::verify($in['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')){ echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    NotificationModel::markAllRead();
    echo json_encode(['success'=>true]); exit;
}
echo json_encode(['success'=>false,'message'=>'Unknown']);
