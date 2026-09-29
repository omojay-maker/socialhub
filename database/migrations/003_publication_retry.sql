-- 003: publication attempt bookkeeping (idempotency, retries, next retry time)

ALTER TABLE post_publications
  ADD COLUMN IF NOT EXISTS attempts       INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS locked_at      TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS next_retry_at  TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS permalink      VARCHAR(500) NULL;

CREATE INDEX IF NOT EXISTS idx_pp_retry ON post_publications (status, next_retry_at);
