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

    /** Daily aggregates across all platforms for the last N days. */
    public static function dailySeries(int $days = 30): array {
        $days = max(2, min(365, $days));
        $from = date('Y-m-d', strtotime("-$days days"));
        $stmt = db()->prepare("SELECT date, SUM(likes+comments+shares) AS interactions, SUM(reach) AS reach, SUM(followers) AS followers FROM analytics WHERE date >= ? GROUP BY date ORDER BY date ASC");
        $stmt->execute([$from]);
        return $stmt->fetchAll();
    }

    /**
     * Current vs previous window totals for honest trend pills.
     * Returns ['current' => [...], 'previous' => [...]] with keys
     * interactions, reach, followers, posts. Zeros when there is no history.
     */
    /**
     * Current vs previous window totals for honest trend pills.
     * Keys: interactions, reach, posts (windowed sums) + followers
     * (point-in-time — audience deltas need history, which dailySeries
     * provides instead). Zeros when there is no history.
     */
    public static function trend(int $days = 7): array {
        $pdo = db();
        $mid = date('Y-m-d', strtotime("-$days days"));
        $start = date('Y-m-d', strtotime('-' . ($days * 2) . ' days'));
        $window = function (string $from, string $to) use ($pdo): array {
            $s = $pdo->prepare("SELECT SUM(likes+comments+shares) AS interactions, SUM(reach) AS reach FROM analytics WHERE date >= ? AND date < ?");
            $s->execute([$from, $to]);
            $r = $s->fetch() ?: [];
            // Window bounds are DATE strings; post timestamps compare fine.
            $p = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE status IN ('published','partial') AND COALESCE(published_at, created_at) >= ? AND COALESCE(published_at, created_at) < ?");
            $p->execute([$from . ' 00:00:00', $to . ' 00:00:00']);
            return [
                'interactions' => (int)($r['interactions'] ?? 0),
                'reach'        => (int)($r['reach'] ?? 0),
                'posts'        => (int)$p->fetchColumn(),
            ];
        };
        $followers = (int)$pdo->query("SELECT SUM(followers) FROM social_accounts WHERE connection_status='connected'")->fetchColumn();
        return [
            'current'  => $window($mid, '2999-01-01') + ['followers' => $followers],
            'previous' => $window($start, $mid) + ['followers' => $followers],
        ];
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
