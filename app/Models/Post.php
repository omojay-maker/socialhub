<?php
class PostModel {
    /**
     * $accountIds maps platform_id => social_account_id so each platform posts
     * from the account the author actually picked.
     */
    public static function create(int $uid, string $content, ?string $hashtags, ?array $mediaIds, string $status, ?string $scheduledAt, array $platformIds, array $accountIds = []): int {
        $pdo=db();
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("INSERT INTO posts (user_id,content,hashtags,media_ids,status,scheduled_at,published_at) VALUES (?,?,?,?,?,?,?)");
            $publishedAt = in_array($status,['published','partial'])?date('Y-m-d H:i:s'):null;
            if($status==='scheduled' && !$scheduledAt) $status='draft';
            $stmt->execute([$uid,$content,$hashtags,$mediaIds?json_encode($mediaIds):null,$status,$scheduledAt,$publishedAt]);
            $postId=(int)$pdo->lastInsertId();
            $pubStatus = $status==='scheduled' ? 'scheduled' : 'pending';
            foreach($platformIds as $pid){
                $pid=(int)$pid;
                $pdo->prepare("INSERT INTO post_platforms (post_id,platform_id) VALUES (?,?)")->execute([$postId,$pid]);
                $accountId = (int)($accountIds[$pid] ?? 0) ?: null;
                $pdo->prepare("INSERT INTO post_publications (post_id,platform_id,account_id,status) VALUES (?,?,?,?)")
                    ->execute([$postId,$pid,$accountId,$pubStatus]);
            }
            $pdo->commit(); return $postId;
        }catch(Throwable $e){ $pdo->rollBack(); throw $e; }
    }

    /** The social_accounts id chosen for a platform, or null when unpinned. */
    public static function accountIdForPlatform(int $platformId): ?int {
        $stmt = db()->prepare("SELECT id FROM social_accounts WHERE platform_id=? AND connection_status='connected' ORDER BY id LIMIT 1");
        $stmt->execute([$platformId]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }
    public static function all(?string $status=null, ?string $search=null, ?string $platform=null, int $limit=50, int $offset=0): array {
        $where=[]; $params=[];
        if($status){ $where[]="p.status=?"; $params[]=$status; }
        if($search){ $where[]="(p.content LIKE ? OR p.hashtags LIKE ?)"; $params[]="%$search%"; $params[]="%$search%"; }
        if($platform){ $where[]="EXISTS(SELECT 1 FROM post_platforms pp JOIN social_platforms sp ON sp.id=pp.platform_id WHERE pp.post_id=p.id AND sp.slug=?)"; $params[]=$platform; }
        $w=$where?"WHERE ".implode(" AND ",$where):"";
        $sql="SELECT p.*, u.name as author_name,
            (SELECT GROUP_CONCAT(sp.slug) FROM post_platforms pp JOIN social_platforms sp ON sp.id=pp.platform_id WHERE pp.post_id=p.id) as platforms
            FROM posts p JOIN users u ON u.id=p.user_id $w ORDER BY p.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt=db()->prepare($sql); $stmt->execute($params); return $stmt->fetchAll();
    }
    public static function find(int $id): ?array {
        $stmt=db()->prepare("SELECT p.*, u.name as author_name FROM posts p JOIN users u ON u.id=p.user_id WHERE p.id=?");
        $stmt->execute([$id]); $r=$stmt->fetch(); if(!$r) return null;
        $r['platforms']=db()->prepare("SELECT sp.* FROM post_platforms pp JOIN social_platforms sp ON sp.id=pp.platform_id WHERE pp.post_id=?");
        $r['platforms']->execute([$id]); $r['platforms']=$r['platforms']->fetchAll();
        $pub=db()->prepare("SELECT pp.*, sp.slug, sp.name as platform_name FROM post_publications pp JOIN social_platforms sp ON sp.id=pp.platform_id WHERE pp.post_id=?");
        $pub->execute([$id]); $r['publications']=$pub->fetchAll();
        return $r;
    }
    public static function update(int $id, array $data): void {
        $stmt=db()->prepare("UPDATE posts SET content=?, hashtags=?, status=?, scheduled_at=? WHERE id=?");
        $stmt->execute([$data['content'],$data['hashtags'],$data['status'],$data['scheduled_at'],$id]);
        if(isset($data['platform_ids'])){
            $accountIds = $data['account_ids'] ?? [];
            db()->prepare("DELETE FROM post_platforms WHERE post_id=?")->execute([$id]);
            db()->prepare("DELETE FROM post_publications WHERE post_id=? AND status IN ('pending','scheduled')")->execute([$id]);
            foreach($data['platform_ids'] as $pid){
                $pid=(int)$pid;
                db()->prepare("INSERT INTO post_platforms (post_id,platform_id) VALUES (?,?)")->execute([$id,$pid]);
                db()->prepare("INSERT INTO post_publications (post_id,platform_id,account_id,status) VALUES (?,?,?,?)")
                    ->execute([$id,$pid,(int)($accountIds[$pid]??0) ?: null, $data['status']==='scheduled'?'scheduled':'pending']);
            }
        }
    }
    public static function delete(int $id): void { db()->prepare("DELETE FROM posts WHERE id=?")->execute([$id]); }
    public static function scheduledDue(int $limit = 20): array {
        $limit = max(1, min(100, $limit));
        return db()->query("SELECT * FROM posts WHERE status='scheduled' AND scheduled_at <= NOW()
                             AND (publish_locked_at IS NULL OR publish_locked_at < DATE_SUB(NOW(), INTERVAL 300 SECOND))
                             ORDER BY scheduled_at LIMIT $limit")->fetchAll();
    }
}
