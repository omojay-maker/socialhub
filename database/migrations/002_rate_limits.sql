-- 002: rate limiting table for login + platform call budgets

CREATE TABLE IF NOT EXISTS rate_limits (
  rate_key     VARCHAR(190) NOT NULL PRIMARY KEY,
  hits         INTEGER NOT NULL DEFAULT 0,
  window_start BIGINT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_window ON rate_limits (window_start);
