-- 003: publication attempt bookkeeping (idempotency, retries, next retry time)

ALTER TABLE post_publications
  ADD COLUMN attempts       INT NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN locked_at      DATETIME NULL AFTER attempts,
  ADD COLUMN next_retry_at  DATETIME NULL AFTER locked_at,
  ADD COLUMN permalink      VARCHAR(500) NULL AFTER external_post_id;

CREATE INDEX idx_pp_retry ON post_publications (status, next_retry_at);
