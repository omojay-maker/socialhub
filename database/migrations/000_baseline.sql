-- 1TechLink Social Hub — incremental migrations
-- Run with: php scripts/migrate.php
-- Forward-only, non-destructive. Each file runs once and is recorded in schema_migrations.

CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(190) NOT NULL PRIMARY KEY,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
