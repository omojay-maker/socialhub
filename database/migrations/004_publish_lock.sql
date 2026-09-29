-- 004: publish idempotency lock so a post is never sent twice to a platform

ALTER TABLE posts
  ADD COLUMN publish_locked_at DATETIME NULL AFTER status,
  ADD COLUMN publish_token    CHAR(36) NULL AFTER publish_locked_at;

CREATE INDEX idx_posts_sched ON posts (status, scheduled_at);
