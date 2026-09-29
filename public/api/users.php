<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../app/Helpers/Validator.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/User.php';
define('BASE_URL', env_get('APP_URL','http://localhost/PHP_projects/social-hub'));

env_send_security_headers();
json_no_store();
Auth::start();
header('Content-Type: application/json');
if(!Auth::check() || !Auth::isAdmin()){ http_response_code(401); echo json_encode(['success'=>false,'message'=>'Admin only']); exit; }
if(($_GET['action']??'')==='list' || ($_SERVER['REQUEST_METHOD']==='GET' && empty($_GET['action']))){
    echo json_encode(['success'=>true,'users'=>UserModel::all()]); exit;
}
if(($_GET['action']??'')==='delete'){
    $in=json_decode(file_get_contents('php://input'),true);
    if(!Csrf::verify($in['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')){ echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    $id=(int)($_GET['id']??0);
    if($id===Auth::user()['id']){ echo json_encode(['success'=>false,'message'=>'Cannot delete self']); exit; }
    UserModel::delete($id);
    echo json_encode(['success'=>true,'message'=>'Deleted']); exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
    $in=json_decode(file_get_contents('php://input'),true);
    if(!Csrf::verify($in['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')){ echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    $name=trim($in['name']??''); $email=trim($in['email']??''); $pass=$in['password']??''; $role=$in['role']??'content_manager';
    if(!$name||!$email||!$pass){ echo json_encode(['success'=>false,'message'=>'All fields required']); exit; }
    if(!Validator::email($email)){ echo json_encode(['success'=>false,'message'=>'Invalid email']); exit; }
    if(!in_array($role,['admin','content_manager'])) $role='content_manager';
    $hash=password_hash($pass,PASSWORD_DEFAULT);
    try{
        $id=UserModel::create(['name'=>$name,'email'=>$email,'password'=>$hash,'role'=>$role]);
        activity_log(Auth::user()['id'],'user.created',"Created user #$id");
        echo json_encode(['success'=>true,'message'=>'User created','id'=>$id]); exit;
    }catch(Throwable $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit; }
}
echo json_encode(['success'=>false,'message'=>'Unknown']);
