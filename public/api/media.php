<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../app/Helpers/Logger.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/Media.php';
define('BASE_URL', env_get('APP_URL','http://localhost/PHP_projects/social-hub'));

env_send_security_headers();
json_no_store();
Auth::start();
header('Content-Type: application/json');
if(!Auth::check()){ http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
if(($_GET['action']??'')==='list' || ($_SERVER['REQUEST_METHOD']==='GET' && empty($_GET['action']))){
    require_once __DIR__.'/../../app/Models/Media.php';
    // media list already requires model, already included, but safe
    $media = array_map(static function (array $m): array {
        $m['url']       = media_url($m['filename'], false);
        $m['public_url'] = media_url($m['filename'], true);
        unset($m['file_path']);
        return $m;
    }, MediaModel::all(100));
    echo json_encode(['success'=>true,'media'=>$media]); exit;
}
if(($_GET['action']??'')==='delete'){
    $in=json_decode(file_get_contents('php://input'),true);
    if(!Csrf::verify($in['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')){ echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    $id=(int)($_GET['id']??0);
    MediaModel::delete($id);
    echo json_encode(['success'=>true,'message'=>'Deleted']); exit;
}
if($_SERVER['REQUEST_METHOD']==='POST' && ($_GET['action']??'')!=='list'){
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if(!Csrf::verify($token)){ echo json_encode(['success'=>false,'message'=>'CSRF']); exit; }
    if(empty($_FILES['file'])){ echo json_encode(['success'=>false,'message'=>'No file']); exit; }
    // Shared with the create-post endpoint so both produce identically named,
    // media.php-servable files.
    $stored=media_store_upload($_FILES['file']);
    if(!$stored){ echo json_encode(['success'=>false,'message'=>$media_upload_error ?? 'Upload rejected']); exit; }
    $id=MediaModel::create(
        Auth::user()['id'],
        $stored['filename'], $stored['original_name'], $stored['file_path'],
        $stored['file_type'], $stored['file_size'], $stored['width'], $stored['height']
    );
    Logger::info('media uploaded',['id'=>$id,'type'=>$stored['file_type'],'size'=>$stored['file_size']]);
    activity_log(Auth::user()['id'],'media.uploaded',"Uploaded media #$id");
    echo json_encode(['success'=>true,'message'=>'Uploaded','id'=>$id]); exit;
}
echo json_encode(['success'=>false,'message'=>'Invalid']);
