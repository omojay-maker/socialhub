-- 001: real OAuth credentials on social accounts
-- Tokens are stored encrypted (AES-256-GCM) by the application.

ALTER TABLE social_accounts
  ADD COLUMN scopes           TEXT NULL AFTER refresh_token,
  ADD COLUMN token_expires_at DATETIME NULL AFTER scopes,
  ADD COLUMN external_user_id VARCHAR(190) NULL AFTER username,
  ADD COLUMN external_account_id VARCHAR(190) NULL AFTER external_user_id,
  ADD COLUMN avatar_url        VARCHAR(500) NULL AFTER external_account_id,
  ADD COLUMN last_error        TEXT NULL AFTER connection_status;

CREATE INDEX idx_sa_platform_status ON social_accounts (platform_id, connection_status);
CREATE INDEX idx_sa_external      ON social_accounts (platform_id, external_account_id);
