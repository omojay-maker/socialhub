<?php
/**
 * Scheduled publisher. Run from cron:
 *
 *   * * * * * /usr/bin/php /var/www/html/PHP_projects/social-hub/cron/publish_scheduled_posts.php >> /var/www/html/PHP_projects/social-hub/storage/logs/cron.log 2>&1
 *
 * Safety: refuses to run from a web request, takes an exclusive lock file so
 * overlapping runs cannot double-post, and never retries fatal auth failures.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

require_once __DIR__ . '/../config/environment.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Helpers/Logger.php';
require_once __DIR__ . '/../app/Helpers/helpers.php';
require_once __DIR__ . '/../app/Models/Post.php';
require_once __DIR__ . '/../app/Models/SocialAccount.php';
require_once __DIR__ . '/../app/Services/Publisher.php';
require_once __DIR__ . '/../app/Services/Throttle.php';

$lockFile = dirname(__DIR__) . '/storage/cron.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another run is already in progress.\n";
    exit(0);
}

$say = static function (string $line): void { echo '[' . date('Y-m-d H:i:s') . '] ' . $line . "\n"; };

try {
    $say('Checking scheduled posts');

    foreach (PostModel::scheduledDue() as $post) {
        $postId = (int)$post['id'];
        $say("Publishing post #$postId (scheduled {$post['scheduled_at']})");

        $res = Publisher::publishNow($postId);
        if (!empty($res['locked'])) {
            $say("  skipped: $postId is already being published");
            continue;
        }
        $say('  result: ' . ($res['post_status'] ?? 'unknown'));
        foreach ($res['results'] ?? [] as $r) {
            $say("    {$r['platform']}: {$r['status']}" . ($r['error'] ? ' — ' . $r['error'] : ''));
        }
    }

    foreach (Publisher::dueRetries() as $retry) {
        $say("Retrying #{$retry['publication_id']} (post #{$retry['post_id']}, {$retry['platform']})");
        $out = Publisher::retryFailed((int)$retry['post_id'], (string)$retry['platform'], false);
        $say('  ' . (!empty($out['success']) ? 'recovered' : 'still failing: ' . ($out['error'] ?? 'unknown')));
    }

    $removed = Throttle::gc(3600);
    if ($removed > 0) $say("Cleaned $removed expired rate-limit window(s)");

    $say('Done');
} catch (Throwable $e) {
    Logger::error('cron failed', ['error' => $e->getMessage()]);
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
