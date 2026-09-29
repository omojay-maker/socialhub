<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../app/Helpers/Logger.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/Post.php';
require_once __DIR__.'/../../app/Models/SocialAccount.php';
require_once __DIR__.'/../../app/Services/Publisher.php';
define('BASE_URL', env_get('APP_URL','http://localhost/PHP_projects/social-hub'));

env_send_security_headers();
json_no_store();
Auth::start();
header('Content-Type: application/json');
if(!Auth::check()){ http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$input=json_decode(file_get_contents('php://input'),true);
if(!Csrf::verify($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')){ echo json_encode(['success'=>false,'message'=>'CSRF invalid']); exit; }
$postId=(int)($input['post_id']??0);
$platform=$input['platform']??null;
if($platform){
    $res=Publisher::retryFailed($postId,$platform);
    echo json_encode($res); exit;
}
$res=Publisher::publishNow($postId);
echo json_encode($res);
