<?php
require_once __DIR__ . '/ProviderFactory.php';
require_once __DIR__ . '/../Helpers/Logger.php';

/**
 * Publishes a post to every selected platform exactly once.
 *
 * Safety properties:
 *  - a database lock (posts.publish_locked_at / publish_token) stops the web
 *    request and the cron job from publishing the same post simultaneously
 *  - a publication that is already 'published' is never re-sent
 *  - failures record attempts + next_retry_at with exponential backoff, and
 *    are only retried when the platform is recoverable
 */
class Publisher {
    const LOCK_TIMEOUT = 300;      // seconds before a stuck lock is considered abandoned
    const MAX_ATTEMPTS = 5;

    public static function publishNow(int $postId, bool $force = false): array {
        $post = PostModel::find($postId);
        if (!$post) return ['success'=>false,'message'=>'Post not found'];

        if (!self::acquireLock($postId, $force)) {
            return ['success'=>false,'message'=>'This post is already being published','locked'=>true];
        }

        try {
            return self::run($post);
        } finally {
            self::releaseLock($postId);
        }
    }

    /** The actual work, always called with the lock held. */
    private static function run(array $post): array {
        $postId  = (int)$post['id'];
        $mediaIds = self::decodeMediaIds($post['media_ids'] ?? null);
        $payload = [
            'content'   => (string)$post['content'],
            'hashtags'  => $post['hashtags'] ?? null,
            'media_ids' => $mediaIds,
            'link'      => self::firstLink((string)$post['content']),
        ];

        $results   = [];
        $succeeded = 0;
        $failed    = 0;

        foreach ($post['publications'] as $pub) {
            if ($pub['status'] === 'published') { $succeeded++; continue; }
            if (($pub['attempts'] ?? 0) >= self::MAX_ATTEMPTS && !$force) {
                $results[] = self::result($pub, ['success'=>false,'error'=>'Maximum publish attempts reached']);
                $failed++;
                continue;
            }

            $accountId = self::resolveAccountId($pub);
            if (!$accountId) {
                $res = ['success'=>false,'error'=>'No connected account for ' . $pub['platform_name'],'needs_reauth'=>true];
            } else {
                $provider = ProviderFactory::make((string)$pub['slug']);
                $res = $provider->publishPost($accountId, $payload);
            }

            self::recordPublication((int)$pub['id'], $res);
            if (!empty($res['success'])) $succeeded++; else $failed++;
            $results[] = self::result($pub, $res);
        }

        $final = $failed === 0 ? 'published' : ($succeeded > 0 ? 'partial' : 'failed');
        db()->prepare("UPDATE posts SET status=?, published_at=COALESCE(published_at,?) WHERE id=?")
            ->execute([$final, $succeeded > 0 ? date('Y-m-d H:i:s') : null, $postId]);

        $uid = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : (int)$post['user_id'];
        activity_log($uid, 'post.published', "Published post #$postId => $final", ['post_id'=>$postId, 'results'=>$results]);
        Logger::info('post published', ['post_id'=>$postId, 'status'=>$final, 'results'=>$results]);

        return ['success'=>$succeeded > 0, 'post_status'=>$final, 'results'=>$results];
    }

    private static function result(array $pub, array $res): array {
        return [
            'publication_id' => (int)$pub['id'],
            'platform'       => $pub['slug'],
            'platform_name'  => $pub['platform_name'],
            'status'         => !empty($res['success']) ? 'published' : 'failed',
            'external_id'    => $res['external_id'] ?? null,
            'error'          => $res['error'] ?? null,
            'needs_reauth'   => (bool)($res['needs_reauth'] ?? false),
            'demo'           => (bool)($res['demo'] ?? false),
        ];
    }

    /** Persists the outcome and schedules a backoff for retryable failures. */
    private static function recordPublication(int $publicationId, array $res): void {
        $ok = !empty($res['success']);
        $stmt = db()->prepare("SELECT attempts FROM post_publications WHERE id=?");
        $stmt->execute([$publicationId]);
        $attempts = (int)($stmt->fetchColumn() ?: 0) + 1;

        // Reauth problems only clear when a human reconnects the account.
        $retryable = !$ok && empty($res['needs_reauth']) && self::isRetryable((string)($res['error'] ?? ''));
        $nextRetry = $retryable && $attempts < self::MAX_ATTEMPTS
            ? date('Y-m-d H:i:s', time() + self::backoffSeconds($attempts))
            : null;

        db()->prepare("UPDATE post_publications SET
                status=?, external_post_id=?, error_message=?, published_at=?, attempts=?, next_retry_at=?, updated_at=NOW()
            WHERE id=?")
            ->execute([
                $ok ? 'published' : 'failed',
                $res['external_id'] ?? null,
                $ok ? null : substr((string)($res['error'] ?? 'Unknown error'), 0, 2000),
                $ok ? date('Y-m-d H:i:s') : null,
                $attempts,
                $nextRetry,
                $publicationId,
            ]);
    }

    /** Minutes: 5, 20, 60, 240 … */
    private static function backoffSeconds(int $attempt): int {
        $minutes = [5, 20, 60, 240, 720][min($attempt - 1, 4)];
        return $minutes * 60;
    }

    private static function isRetryable(string $error): bool {
        $fatal = ['(#200)', 'invalid oauth', 'permission', 'unsupported get request', 'is not authorized', 'reconnect'];
        foreach ($fatal as $needle) {
            if (stripos($error, $needle) !== false) return false;
        }
        return true;
    }

    /** Uses the account chosen for the publication, else the first connected one. */
    private static function resolveAccountId(array $pub): int {
        if (!empty($pub['account_id'])) {
            $stmt = db()->prepare("SELECT id FROM social_accounts WHERE id=? AND connection_status='connected'");
            $stmt->execute([(int)$pub['account_id']]);
            $id = (int)($stmt->fetchColumn() ?: 0);
            if ($id) return $id;
        }
        $stmt = db()->prepare("SELECT id FROM social_accounts WHERE platform_id=? AND connection_status='connected' ORDER BY id LIMIT 1");
        $stmt->execute([(int)$pub['platform_id']]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    private static function decodeMediaIds($json): array {
        if (is_array($json)) return array_values(array_filter(array_map('intval', $json)));
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? array_values(array_filter(array_map('intval', $decoded))) : [];
    }

    private static function firstLink(string $content): ?string {
        if (preg_match('#https?://[^\s<>"]+#i', $content, $m)) return rtrim($m[0], '.,);');
        return null;
    }

    /* ---------------------------------------------------------------- lock */

    private static function acquireLock(int $postId, bool $force): bool {
        $token = bin2hex(random_bytes(16));
        $sql = "UPDATE posts SET publish_locked_at=NOW(), publish_token=?
                 WHERE id=? AND (publish_locked_at IS NULL OR publish_locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND))";
        $stmt = db()->prepare($sql);
        $stmt->execute([$token, $postId, self::LOCK_TIMEOUT]);
        return $stmt->rowCount() === 1;
    }

    private static function releaseLock(int $postId): void {
        db()->prepare("UPDATE posts SET publish_locked_at=NULL, publish_token=NULL WHERE id=? AND publish_token IS NOT NULL")
            ->execute([$postId]);
    }

    /* --------------------------------------------------------------- retry */

    public static function retryFailed(int $postId, string $platformSlug, bool $force = true): array {
        $post = PostModel::find($postId);
        if (!$post) return ['success'=>false,'message'=>'Post not found'];

        foreach ($post['publications'] as $pub) {
            if ($pub['slug'] !== $platformSlug || $pub['status'] !== 'failed') continue;

            $accountId = self::resolveAccountId($pub);
            if (!$accountId) {
                return ['success'=>false,'error'=>'No connected account for ' . $pub['platform_name']];
            }
            $provider = ProviderFactory::make($platformSlug);
            $res = $provider->publishPost($accountId, [
                'content'   => (string)$post['content'],
                'media_ids' => self::decodeMediaIds($post['media_ids'] ?? null),
                'link'      => self::firstLink((string)$post['content']),
            ]);
            self::recordPublication((int)$pub['id'], $res);
            self::syncPostStatus($postId);

            return [
                'success'     => (bool)($res['success'] ?? false),
                'status'      => !empty($res['success']) ? 'published' : 'failed',
                'external_id' => $res['external_id'] ?? null,
                'error'       => $res['error'] ?? null,
            ];
        }
        return ['success'=>false,'message'=>'No failed publication for ' . $platformSlug];
    }

    /** Recomputes the post status from its publication rows. */
    public static function syncPostStatus(int $postId): void {
        $stmt = db()->prepare("SELECT status, COUNT(*) c FROM post_publications WHERE post_id=? GROUP BY status");
        $stmt->execute([$postId]);
        $counts = [];
        foreach ($stmt->fetchAll() as $r) $counts[$r['status']] = (int)$r['c'];

        $published = $counts['published'] ?? 0;
        $failed    = $counts['failed'] ?? 0;
        $pending   = ($counts['pending'] ?? 0) + ($counts['scheduled'] ?? 0);

        if ($published > 0 && $failed > 0)      $final = 'partial';
        elseif ($published > 0 && $pending === 0) $final = 'published';
        elseif ($failed > 0 && $published === 0 && $pending === 0) $final = 'failed';
        else return; // still in flight, leave the status alone

        db()->prepare("UPDATE posts SET status=? WHERE id=?")->execute([$final, $postId]);
    }

    /**
     * Publications eligible for an automatic retry.
     * @return array<int,array{post_id:int,publication_id:int,platform:string}>
     */
    public static function dueRetries(int $limit = 25): array {
        $stmt = db()->prepare("SELECT pp.post_id, pp.id AS publication_id, sp.slug
                                FROM post_publications pp
                                JOIN social_platforms sp ON sp.id = pp.platform_id
                                WHERE pp.status='failed'
                                  AND pp.next_retry_at IS NOT NULL
                                  AND pp.next_retry_at <= NOW()
                                  AND pp.attempts < ?
                                ORDER BY pp.next_retry_at LIMIT ?");
        $stmt->bindValue(1, self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(2, max(1, min(250, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
