<?php
class NotificationModel {
    public static function all(?string $platform=null, ?string $type=null, bool $unreadOnly=false): array {
        $w=[]; $p=[];
        if($platform){ $w[]="sp.slug=?"; $p[]=$platform; }
        if($type){ $w[]="n.type=?"; $p[]=$type; }
        if($unreadOnly){ $w[]="n.is_read=0"; }
        $where=$w?"WHERE ".implode(" AND ",$w):"";
        $sql="SELECT n.*, sp.name as platform_name, sp.slug as platform_slug, sp.color FROM notifications n LEFT JOIN social_platforms sp ON sp.id=n.platform_id $where ORDER BY n.created_at DESC LIMIT 100";
        $stmt=db()->prepare($sql); $stmt->execute($p); return $stmt->fetchAll();
    }
    public static function markRead(int $id): void { db()->prepare("UPDATE notifications SET is_read=1 WHERE id=?")->execute([$id]); }
    public static function markAllRead(): void { db()->query("UPDATE notifications SET is_read=1 WHERE is_read=0"); }
    public static function unreadCount(): int { return (int)db()->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn(); }
}
