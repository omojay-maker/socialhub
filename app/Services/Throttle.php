<?php
/**
 * Fixed-window rate limiter backed by the database so it survives workers.
 * Used for login attempts and outbound platform calls. Portable across
 * MySQL and PostgreSQL (plain SELECT/INSERT/UPDATE, no upsert dialect).
 */
class Throttle {
    /** @return bool true when the attempt is allowed */
    public static function attempt(string $key, int $limit, int $windowSeconds): bool {
        if ($limit <= 0) return true;
        $now = time();
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT hits, window_start FROM rate_limits WHERE rate_key=?");
            $stmt->execute([$key]);
            $row = $stmt->fetch();

            if (!$row || (int)$row['window_start'] <= $now - $windowSeconds) {
                // New window. DELETE+INSERT keeps this portable (no ON DUPLICATE
                // KEY / ON CONFLICT dialect); the limiter fails open anyway.
                $pdo->prepare("DELETE FROM rate_limits WHERE rate_key=?")->execute([$key]);
                $pdo->prepare("INSERT INTO rate_limits (rate_key, hits, window_start) VALUES (?,?,?)")
                    ->execute([$key, 1, $now]);
                return true;
            }

            $pdo->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE rate_key=?")->execute([$key]);
            return (int)$row['hits'] + 1 <= $limit;
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
