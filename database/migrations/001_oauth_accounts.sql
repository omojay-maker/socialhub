-- 001: real OAuth credentials on social accounts
-- Tokens are stored encrypted (AES-256-GCM) by the application.

ALTER TABLE social_accounts
  ADD COLUMN IF NOT EXISTS scopes              TEXT NULL,
  ADD COLUMN IF NOT EXISTS token_expires_at    TIMESTAMP NULL,
  ADD COLUMN IF NOT EXISTS external_user_id    VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS external_account_id VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS avatar_url           VARCHAR(500) NULL,
  ADD COLUMN IF NOT EXISTS last_error           TEXT NULL;

CREATE INDEX IF NOT EXISTS idx_sa_platform_status ON social_accounts (platform_id, connection_status);
CREATE INDEX IF NOT EXISTS idx_sa_external      ON social_accounts (platform_id, external_account_id);
