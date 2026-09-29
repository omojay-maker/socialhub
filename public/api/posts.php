<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../app/Helpers/Validator.php';
require_once __DIR__.'/../../app/Helpers/Logger.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/Post.php';
require_once __DIR__.'/../../app/Models/Media.php';
require_once __DIR__.'/../../app/Models/SocialAccount.php';
define('BASE_URL', env_get('APP_URL','http://localhost/PHP_projects/social-hub'));

env_send_security_headers();
json_no_store();
Auth::start();
header('Content-Type: application/json');
if(!Auth::check()){ http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$action=$_GET['action']??'';
if($action==='list' || ($action==='' && $_SERVER['REQUEST_METHOD']==='GET' && empty($_GET['id']))){
    $status = $_GET['status'] ?? null;
    $search = $_GET['q'] ?? null;
    $platform = $_GET['platform'] ?? null;
    $posts = PostModel::all($status?:null,$search?:null,$platform?:null,50,0);
    echo json_encode(['success'=>true,'posts'=>$posts]); exit;
}
if($action==='list' && false){}
if($action==='create'){
    require_once __DIR__.'/../../app/Controllers/PostController.php';
    $token = $_POST['csrf_token'] ?? json_decode(file_get_contents('php://input'),true)['csrf_token'] ?? '';
    // For FormData with JSON body fallback, also check header
    if (empty($token)) $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if(!Csrf::verify($token)){ echo json_encode(['success'=>false,'message'=>'CSRF invalid']); exit; }
    $res=PostController::handleCreate();
    echo json_encode($res); exit;
}
if($action==='delete'){
    $input=json_decode(file_get_contents('php://input'),true);
    if(!Csrf::verify($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')){ echo json_encode(['success'=>false,'message'=>'CSRF invalid']); exit; }
    $id=(int)($_GET['id']??0);
    $post=PostModel::find($id);
    if(!$post){ echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    if(!Auth::isAdmin() && $post['user_id']!=Auth::user()['id']){ echo json_encode(['success'=>false,'message'=>'Forbidden']); exit; }
    PostModel::delete($id);
    activity_log(Auth::user()['id'],'post.deleted',"Deleted post #$id");
    echo json_encode(['success'=>true,'message'=>'Deleted']); exit;
}
echo json_encode(['success'=>false,'message'=>'Unknown action']);
