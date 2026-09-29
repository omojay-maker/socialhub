<?php
class AnalyticsModel {
    public static function summary(): array {
        $pdo=db();
        $followers = (int)$pdo->query("SELECT SUM(followers) FROM social_accounts WHERE connection_status='connected'")->fetchColumn();
        $eng = $pdo->query("SELECT SUM(likes+comments+shares) as e, SUM(reach) as r, SUM(impressions) as imp FROM analytics WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetch();
        $posts = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status IN ('published','partial')")->fetchColumn();
        $scheduled = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='scheduled'")->fetchColumn();
        return ['followers'=>$followers,'engagement'=> (int)($eng['e']??0),'reach'=>(int)($eng['r']??0),'impressions'=>(int)($eng['imp']??0),'posts'=>$posts,'scheduled'=>$scheduled];
    }
    public static function byPlatform(?string $from=null, ?string $to=null): array {
        $sql="SELECT sp.slug, sp.name, sp.color, SUM(a.likes) likes, SUM(a.comments) comments, SUM(a.shares) shares, SUM(a.reach) reach, SUM(a.impressions) impressions, AVG(a.engagement_rate) eng_rate FROM analytics a JOIN social_platforms sp ON sp.id=a.platform_id WHERE sp.is_active=1 GROUP BY sp.id";
        return db()->query($sql)->fetchAll();
    }
    public static function timeline(int $platformId=null, int $days=7): array {
        $sql="SELECT date, followers, likes, comments, shares, reach, impressions FROM analytics WHERE date >= DATE_SUB(CURDATE(), INTERVAL $days DAY) ";
        if($platformId) $sql.=" AND platform_id=$platformId ";
        $sql.=" ORDER BY date ASC";
        return db()->query($sql)->fetchAll();
    }
}
