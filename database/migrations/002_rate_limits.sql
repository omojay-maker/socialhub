-- 002: rate limiting table for login + platform call budgets

CREATE TABLE IF NOT EXISTS rate_limits (
  rate_key     VARCHAR(190) NOT NULL PRIMARY KEY,
  hits         INT NOT NULL DEFAULT 0,
  window_start BIGINT NOT NULL,
  INDEX idx_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
