<?php
class MediaModel {
    public static function all(int $limit=100): array {
        $limit = max(1, min(500, $limit));
        return db()->query("SELECT m.*, u.name as uploader FROM media m JOIN users u ON u.id=m.user_id ORDER BY m.created_at DESC LIMIT $limit")->fetchAll();
    }
    public static function create(int $uid, string $filename,string $orig,string $path,string $type,int $size,?int $w=null,?int $h=null): int {
        $stmt=db()->prepare("INSERT INTO media (user_id,filename,original_name,file_path,file_type,file_size,width,height) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([$uid,$filename,$orig,$path,$type,$size,$w,$h]); return (int)db()->lastInsertId();
    }
    public static function find(int $id): ?array { $s=db()->prepare("SELECT * FROM media WHERE id=?"); $s->execute([$id]); return $s->fetch()?:null; }
    public static function findByFilename(string $filename): ?array { $s=db()->prepare("SELECT * FROM media WHERE filename=?"); $s->execute([$filename]); return $s->fetch()?:null; }
    public static function delete(int $id): void {
        $m=self::find($id);
        if($m){ @unlink(media_storage_path($m['filename'])); }
        db()->prepare("DELETE FROM media WHERE id=?")->execute([$id]);
    }
}
