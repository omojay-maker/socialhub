<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
define('BASE_URL', env_get('APP_URL','http://localhost/PHP_projects/social-hub'));

env_send_security_headers();
json_no_store();
Auth::start();
header('Content-Type: application/json');
if (!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$logs = db()->query("SELECT l.*, u.name as user_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 100")->fetchAll();
echo json_encode(['success'=>true,'logs'=>$logs]);
