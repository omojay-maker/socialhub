<?php
require_once __DIR__.'/../Models/Analytics.php';
require_once __DIR__.'/../Models/Notification.php';
class DashboardController {
    public static function data(): array {
        $summary=AnalyticsModel::summary();
        $accounts=SocialAccountModel::all();
        $recentPosts=PostModel::all(null,null,null,5,0);
        $notifications=NotificationModel::all(null,null,false);
        $unread=NotificationModel::unreadCount();
        $byPlatform=AnalyticsModel::byPlatform();
        // top post
        $top=db()->query("SELECT p.id,p.content, (SELECT SUM(a.likes+a.comments+a.shares) FROM analytics a LIMIT 1) as eng FROM posts p WHERE p.status IN ('published','partial') ORDER BY p.published_at DESC LIMIT 1")->fetch();
        return compact('summary','accounts','recentPosts','notifications','unread','byPlatform','top');
    }
}
