-- 005: register TikTok and X (Twitter) in the platform catalogue.
-- Both are first-class OAuth platforms with a real provider, not demo stubs.
-- Idempotent: re-running only fills in the columns a previous run left blank.

INSERT INTO social_platforms (slug, name, icon, color, is_active) VALUES
('tiktok',  'TikTok',         'tiktok',   '#010101', 1),
('twitter', 'X (Twitter)',    'twitter',  '#000000', 1)
ON DUPLICATE KEY UPDATE
  name      = VALUES(name),
  icon      = VALUES(icon),
  color     = VALUES(color),
  is_active = VALUES(is_active);
