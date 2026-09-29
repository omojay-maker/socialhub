-- 005: register TikTok and X in the platform catalogue.
-- Both are first-class OAuth platforms with a real provider, not demo stubs.
-- Idempotent: re-running only fills in the columns a previous run left blank.

INSERT INTO social_platforms (slug, name, icon, color, is_active) VALUES
('tiktok',  'TikTok', 'tiktok',  '#FE2C55', TRUE),
('twitter', 'X',      'twitter', '#e7e9ea', TRUE)
ON CONFLICT (slug) DO UPDATE SET
  name      = EXCLUDED.name,
  icon      = EXCLUDED.icon,
  color     = EXCLUDED.color,
  is_active = EXCLUDED.is_active;
