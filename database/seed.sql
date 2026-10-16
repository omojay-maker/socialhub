-- Baseline seed (PostgreSQL): platform catalogue, login users, sample media.
--
-- Run after database/schema.sql (and any migrations):
--   psql "$DATABASE_URL" -f database/schema.sql
--   psql "$DATABASE_URL" -f database/seed.sql
--   php scripts/seed_demo_media.php      # installs the images referenced below
--
-- Deliberately contains NO posts, analytics, notifications or activity rows:
-- every number in the UI must come from a connected account or user action.
-- This script is re-runnable. Rows are matched on their natural keys
-- (users.email, social_platforms.slug, media.filename) so a second run
-- updates in place instead of failing halfway and leaving the schema
-- half-populated.
--
-- The demo users are admin@1techlink.com and manager@1techlink.com, both with
-- the password "password". Change them before exposing this to anyone.

-- ---------------------------------------------------------------- platforms --
-- Match on slug (not id): migration 005 may already own low ids with the
-- same slugs, and an id-based upsert would leave those stale slugs behind
-- to collide with the rows inserted below.
INSERT INTO social_platforms (slug, name, icon, color, is_active) VALUES
('facebook',  'Facebook',     'facebook',  '#1877F2', FALSE),
('instagram', 'Instagram',    'instagram', '#E4405F', TRUE),
('linkedin',  'LinkedIn',     'linkedin',  '#0A66C2', TRUE),
('tiktok',    'TikTok',       'tiktok',    '#FE2C55', TRUE)
ON CONFLICT (slug) DO UPDATE SET
  name=EXCLUDED.name, icon=EXCLUDED.icon, color=EXCLUDED.color, is_active=EXCLUDED.is_active;

-- -------------------------------------------------------------------- users --
-- password_hash('password', PASSWORD_DEFAULT)
INSERT INTO users (name, email, password, role) VALUES
('Admin User',      'admin@1techlink.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Content Manager', 'manager@1techlink.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'content_manager')
ON CONFLICT (email) DO UPDATE SET name=EXCLUDED.name, role=EXCLUDED.role;

-- -------------------------------------------------------------------- media --
-- The filenames must match /^[a-f0-9]{32}\.[a-z0-9]{2,5}$/ or public/media.php
-- will refuse to serve them. The files are tracked in database/demo-media/ and
-- copied into storage/uploads/ by scripts/seed_demo_media.php.
INSERT INTO media (user_id, filename, original_name, file_path, file_type, file_size, width, height)
SELECT (SELECT id FROM users WHERE email='admin@1techlink.com'), v.filename, v.original_name, CONCAT('uploads/', v.filename), v.file_type, v.file_size, v.width, v.height
FROM (
  SELECT '64e32f7b317ff9e8b3d36c7f50cb8d9d.jpg' AS filename, 'tech-launch.jpg' AS original_name, 'image/jpeg' AS file_type, 21778 AS file_size, 1200 AS width, 630 AS height
  UNION ALL SELECT '5e65d5bc420cf292ca7772cb4b728123.jpg', 'team.jpg', 'image/jpeg', 21782, 1200, 630
) v
WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.filename = v.filename);

-- ------------------------------------------------- identity sequences ------
-- Explicit ids above must not collide with future auto-generated ones.
SELECT setval(pg_get_serial_sequence('social_platforms','id'), (SELECT MAX(id) FROM social_platforms));
SELECT setval(pg_get_serial_sequence('users','id'), (SELECT MAX(id) FROM users));
SELECT setval(pg_get_serial_sequence('social_accounts','id'), COALESCE((SELECT MAX(id) FROM social_accounts), 1));
SELECT setval(pg_get_serial_sequence('media','id'), COALESCE((SELECT MAX(id) FROM media), 1));
SELECT setval(pg_get_serial_sequence('posts','id'), COALESCE((SELECT MAX(id) FROM posts), 1));
SELECT setval(pg_get_serial_sequence('post_platforms','id'), COALESCE((SELECT MAX(id) FROM post_platforms), 1));
SELECT setval(pg_get_serial_sequence('post_publications','id'), COALESCE((SELECT MAX(id) FROM post_publications), 1));
SELECT setval(pg_get_serial_sequence('notifications','id'), COALESCE((SELECT MAX(id) FROM notifications), 1));
SELECT setval(pg_get_serial_sequence('analytics','id'), COALESCE((SELECT MAX(id) FROM analytics), 1));
SELECT setval(pg_get_serial_sequence('activity_logs','id'), COALESCE((SELECT MAX(id) FROM activity_logs), 1));
SELECT setval(pg_get_serial_sequence('settings','id'), COALESCE((SELECT MAX(id) FROM settings), 1));