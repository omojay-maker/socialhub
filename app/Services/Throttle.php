<?php
/**
 * Fixed-window rate limiter backed by MySQL so it survives across workers.
 * Used for login attempts and outbound platform calls.
 */
class Throttle {
    /** @return bool true when the attempt is allowed */
    public static function attempt(string $key, int $limit, int $windowSeconds): bool {
        if ($limit <= 0) return true;
        $now = time();
        try {
            $pdo = db();
            $pdo->prepare("INSERT INTO rate_limits (rate_key, hits, window_start) VALUES (?,1,?)
                            ON DUPLICATE KEY UPDATE
                              hits = IF(window_start <= ?, hits + 1, 1),
                              window_start = IF(window_start <= ?, ?, window_start)")
                ->execute([$key, $now, $now - $windowSeconds, $now - $windowSeconds, $now]);

            $stmt = $pdo->prepare("SELECT hits, window_start FROM rate_limits WHERE rate_key=?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row) return true;

            if ((int)$row['window_start'] + $windowSeconds < $now) {
                return true; // window already expired
            }
            return (int)$row['hits'] <= $limit;
        } catch (Throwable $e) {
            Logger::error('throttle failure', ['error' => $e->getMessage()]);
            return true; // fail open, never lock users out because of the limiter
        }
    }

    public static function clear(string $key): void {
        try { db()->prepare("DELETE FROM rate_limits WHERE rate_key=?")->execute([$key]); }
        catch (Throwable $e) { Logger::error('throttle clear failure', ['error' => $e->getMessage()]); }
    }

    public static function gc(int $olderThanSeconds = 3600): int {
        $stmt = db()->prepare("DELETE FROM rate_limits WHERE window_start < ?");
        $stmt->execute([time() - $olderThanSeconds]);
        return $stmt->rowCount();
    }
}
