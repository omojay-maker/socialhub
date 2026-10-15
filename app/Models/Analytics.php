<?php
class AnalyticsModel {
    public static function summary(): array {
        $pdo=db();
        $followers = (int)$pdo->query("SELECT SUM(followers) FROM social_accounts WHERE connection_status='connected'")->fetchColumn();
        $from = date('Y-m-d', strtotime('-7 days'));
        $stmt = $pdo->prepare("SELECT SUM(likes+comments+shares) as e, SUM(reach) as r, SUM(impressions) as imp FROM analytics WHERE date >= ?");
        $stmt->execute([$from]);
        $eng = $stmt->fetch();
        $posts = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status IN ('published','partial')")->fetchColumn();
        $scheduled = (int)$pdo->query("SELECT COUNT(*) FROM posts WHERE status='scheduled'")->fetchColumn();
        return ['followers'=>$followers,'engagement'=> (int)($eng['e']??0),'reach'=>(int)($eng['r']??0),'impressions'=>(int)($eng['imp']??0),'posts'=>$posts,'scheduled'=>$scheduled];
    }
    public static function byPlatform(?string $from=null, ?string $to=null): array {
        $sql="SELECT sp.slug, sp.name, sp.color, SUM(a.likes) likes, SUM(a.comments) comments, SUM(a.shares) shares, SUM(a.reach) reach, SUM(a.impressions) impressions, AVG(a.engagement_rate) eng_rate FROM analytics a JOIN social_platforms sp ON sp.id=a.platform_id WHERE sp.is_active=TRUE GROUP BY sp.id";
        return db()->query($sql)->fetchAll();
    }
    public static function timeline(int $platformId=null, int $days=7): array {
        $days = max(1, min(365, $days));
        $from = date('Y-m-d', strtotime("-$days days"));
        $sql="SELECT date, followers, likes, comments, shares, reach, impressions FROM analytics WHERE date >= ? ";
        $params = [$from];
        if($platformId) { $sql.=" AND platform_id=? "; $params[] = $platformId; }
        $sql.=" ORDER BY date ASC";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Record today's counters for a live account. Portable UPDATE-first
     * upsert (no ON CONFLICT / ON DUPLICATE dialect). Engagement starts at
     * zero and grows as real syncs land — never demo data again.
     */
    public static function recordLiveSnapshot(int $platformId, int $accountId, int $followers, array $extra = []): void {
        $pdo = db();
        $today = date('Y-m-d');
        $upd = $pdo->prepare("UPDATE analytics SET followers=? WHERE platform_id=? AND date=?");
        $upd->execute([$followers, $platformId, $today]);
        if ($upd->rowCount() === 0) {
            $pdo->prepare("INSERT INTO analytics (platform_id, account_id, date, followers, follower_growth, likes, comments, shares, reach, impressions, engagement_rate) VALUES (?,?,?,?,0,0,0,0,0,0,0)")
                ->execute([$platformId, $accountId, $today, $followers]);
        }
    }
}
