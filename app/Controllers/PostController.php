<?php
require_once __DIR__.'/../Services/Publisher.php';
require_once __DIR__.'/../Helpers/helpers.php';
class PostController {
    public static function handleCreate(): ?array {
        if($_SERVER['REQUEST_METHOD']!=='POST') return null;
        $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (empty($token)) {
            $json = json_decode(file_get_contents('php://input'), true);
            $token = $json['csrf_token'] ?? '';
        }
        if(!Csrf::verify($token)) return ['success'=>false,'message'=>'CSRF failed'];
        // Support both FormData and JSON
        $isJson = empty($_POST) && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
        $jsonInput = $isJson ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
        $content=trim($_POST['content'] ?? $jsonInput['content'] ?? '');
        $hashtags=trim($_POST['hashtags'] ?? $jsonInput['hashtags'] ?? '');
        $platforms=$_POST['platforms'] ?? $jsonInput['platforms'] ?? []; // array of slugs
        if (is_string($platforms)) $platforms = json_decode($platforms, true) ?? [$platforms];
        $status=$_POST['action'] ?? $jsonInput['action'] ?? 'draft'; // draft|scheduled|publish
        $scheduledAt = trim($_POST['scheduled_at'] ?? $jsonInput['scheduled_at'] ?? '');
        if($content==='') return ['success'=>false,'message'=>'Content required'];
        if(empty($platforms) && $status!=='draft') return ['success'=>false,'message'=>'Select at least one platform'];
        // map slugs to ids
        $platformIds=[];
        if(!empty($platforms)){
            $in=str_repeat('?,',count($platforms)-1).'?';
            $stmt=db()->prepare("SELECT id,slug FROM social_platforms WHERE slug IN ($in)");
            $stmt->execute($platforms);
            foreach($stmt->fetchAll() as $r) $platformIds[]=$r['id'];
        }
        $dbStatus = match($status){ 'publish'=>'published','scheduled'=>'scheduled', default=>'draft' };
        if($dbStatus==='scheduled' && !$scheduledAt) return ['success'=>false,'message'=>'Scheduled date required'];
        if($dbStatus==='scheduled' && strtotime($scheduledAt) < time()) return ['success'=>false,'message'=>'Scheduled time must be in future'];
        // media
        $mediaIds=null;
        if(!empty($_POST['media_ids'])){ $mediaIds=json_decode($_POST['media_ids'],true); if(!is_array($mediaIds)) $mediaIds=null; }
        // handle file upload if present
        if(!empty($_FILES['media_file']['name'])){
            $mid=self::handleUpload($_FILES['media_file']);
            if($mid){ $mediaIds=$mediaIds??[]; $mediaIds[]=$mid; }
        }
        $uid=Auth::user()['id'];
        try{
            $postId=PostModel::create($uid,$content,$hashtags?:null,$mediaIds,$dbStatus,$scheduledAt?:null,$platformIds);
            activity_log($uid,'post.created',"Created post #$postId",['post_id'=>$postId]);
            if($dbStatus==='published'){
                $res=Publisher::publishNow($postId);
                return ['success'=>true,'message'=>'Post publishing attempted','post_id'=>$postId,'publish_result'=>$res];
            }
            return ['success'=>true,'message'=> $dbStatus==='scheduled'?'Post scheduled':'Draft saved','post_id'=>$postId];
        }catch(Throwable $e){ Logger::error('post create failed',['error'=>$e->getMessage()]); return ['success'=>false,'message'=>$e->getMessage()]; }
    }
    private static function handleUpload(array $file): ?int {
        // Shared with media.php. This used to name files uniqid('media_') with a
        // leading-slash path, which produced files that media.php would refuse
        // to serve and whose names were guessable.
        $stored=media_store_upload($file);
        if(!$stored) return null;
        return MediaModel::create(
            Auth::user()['id'],
            $stored['filename'], $stored['original_name'], $stored['file_path'],
            $stored['file_type'], $stored['file_size'], $stored['width'], $stored['height']
        );
    }
}
