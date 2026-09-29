-- 004: publish idempotency lock so a post is never sent twice to a platform

ALTER TABLE posts
  ADD COLUMN IF NOT EXISTS publish_locked_at TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS publish_token    CHAR(36) NULL;

CREATE INDEX IF NOT EXISTS idx_posts_sched ON posts (status, scheduled_at);
