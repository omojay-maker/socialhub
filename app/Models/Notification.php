<?php
class NotificationModel {
    public static function all(?string $platform=null, ?string $type=null, bool $unreadOnly=false): array {
        $w=[]; $p=[];
        if($platform){ $w[]="sp.slug=?"; $p[]=$platform; }
        if($type){ $w[]="n.type=?"; $p[]=$type; }
        if($unreadOnly){ $w[]="n.is_read=FALSE"; }
        $where=$w?"WHERE ".implode(" AND ",$w):"";
        $sql="SELECT n.*, sp.name as platform_name, sp.slug as platform_slug, sp.color FROM notifications n LEFT JOIN social_platforms sp ON sp.id=n.platform_id $where ORDER BY n.created_at DESC LIMIT 100";
        $stmt=db()->prepare($sql); $stmt->execute($p);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) $r['is_read'] = db_bool($r['is_read'] ?? 0);
        return $rows;
    }
    public static function markRead(int $id): void { db()->prepare("UPDATE notifications SET is_read=TRUE WHERE id=?")->execute([$id]); }
    public static function markAllRead(): void { db()->query("UPDATE notifications SET is_read=TRUE WHERE is_read=FALSE"); }
    public static function unreadCount(): int { return (int)db()->query("SELECT COUNT(*) FROM notifications WHERE is_read=FALSE")->fetchColumn(); }
}
