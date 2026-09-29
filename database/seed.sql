-- Seed data for demo mode (PostgreSQL).
--
-- Run after database/schema.sql (and any migrations):
--   psql "$DATABASE_URL" -f database/schema.sql
--   psql "$DATABASE_URL" -f database/seed.sql
--   php scripts/seed_demo_media.php      # installs the images referenced below
--
-- This script is re-runnable. Rows are matched on their natural keys
-- (users.email, social_platforms.slug, media.filename, the unique index on
-- analytics and post_platforms) so a second run updates in place instead of
-- failing halfway and leaving the schema half-populated.
--
-- The demo users are admin@1techlink.com and manager@1techlink.com, both with
-- the password "password". Change them before exposing this to anyone.

-- ---------------------------------------------------------------- platforms --
INSERT INTO social_platforms (id, slug, name, icon, color, is_active) VALUES
(1, 'facebook',  'Facebook',     'facebook',  '#1877F2', FALSE),
(2, 'instagram', 'Instagram',    'instagram', '#E4405F', TRUE),
(3, 'linkedin',  'LinkedIn',     'linkedin',  '#0A66C2', TRUE),
(4, 'tiktok',    'TikTok',       'tiktok',    '#FE2C55', TRUE),
(5, 'twitter',   'X',            'twitter',   '#e7e9ea', TRUE)
ON CONFLICT (id) DO UPDATE SET
  name=EXCLUDED.name, icon=EXCLUDED.icon, color=EXCLUDED.color, is_active=EXCLUDED.is_active;

-- -------------------------------------------------------------------- users --
-- password_hash('password', PASSWORD_DEFAULT)
INSERT INTO users (name, email, password, role) VALUES
('Admin User',      'admin@1techlink.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Content Manager', 'manager@1techlink.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'content_manager')
ON CONFLICT (email) DO UPDATE SET name=EXCLUDED.name, role=EXCLUDED.role;

-- ----------------------------------------------------------------- accounts --
-- access_token is NULL: these are demo channels, not real OAuth connections.
-- Real connections fill these columns with AES-256-GCM encrypted tokens.
INSERT INTO social_accounts (platform_id, user_id, account_name, username, account_type, connection_status, followers, following, posts_count, engagement_rate, last_synced_at)
SELECT v.platform_id, (SELECT id FROM users WHERE email='admin@1techlink.com'), v.account_name, v.username, v.account_type, 'connected', v.followers, v.following, v.posts_count, v.engagement_rate, NOW()
FROM (
  SELECT (SELECT id FROM social_platforms WHERE slug='instagram') AS platform_id, '1TechLink' AS account_name, '@1techlink.official' AS username, 'business' AS account_type,  8920 AS followers, 540 AS following,  278 AS posts_count, 6.12 AS engagement_rate
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='linkedin'), '1TechLink Company', '1techlink',           'company',   5420, 180,  156, 3.90
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='tiktok'), '1TechLink',         '@1techlink',          'creator',  22400,  90,   61, 8.40
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='twitter'), '1TechLink',         '@1techlink',          'user',      6350, 410, 1280, 2.75
) v
WHERE NOT EXISTS (
  SELECT 1 FROM social_accounts a
  WHERE a.platform_id = v.platform_id AND a.username = v.username
);

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

-- -------------------------------------------------------------------- posts --
INSERT INTO posts (user_id, content, hashtags, status, scheduled_at, published_at)
SELECT (SELECT id FROM users WHERE email='admin@1techlink.com'), v.content, v.hashtags, v.status, v.scheduled_at, v.published_at
FROM (
  SELECT 'At 1TechLink, we build technology solutions that help businesses grow. #Innovation #Tech' AS content, '#Innovation #Tech' AS hashtags, 'published' AS status, NULL::TIMESTAMP AS scheduled_at, (NOW() - INTERVAL '2 days') AS published_at
  UNION ALL SELECT 'Excited to announce our new product line launching next week! Stay tuned. #1TechLink #Launch', '#1TechLink #Launch', 'published', NULL, (NOW() - INTERVAL '1 day')
  UNION ALL SELECT 'Technology That Moves Businesses Forward - Join us for a webinar on digital transformation.', '', 'scheduled', (NOW() + INTERVAL '2 days'), NULL
  UNION ALL SELECT 'Draft: Behind the scenes at 1TechLink HQ', '', 'draft', NULL, NULL
  UNION ALL SELECT 'Our team delivered another successful project! Client satisfaction is our priority.', '', 'published', NULL, (NOW() - INTERVAL '5 hours')
) v
WHERE NOT EXISTS (SELECT 1 FROM posts p WHERE p.user_id = (SELECT id FROM users WHERE email='admin@1techlink.com') AND p.content = v.content);

-- post_platforms has a unique (post_id, platform_id) index.
INSERT INTO post_platforms (post_id, platform_id)
SELECT v.post_id, v.platform_id FROM (
  SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1) AS post_id, (SELECT id FROM social_platforms WHERE slug='instagram') AS platform_id
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='linkedin')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='tiktok')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='twitter')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Excited to announce our new product line%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='instagram')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Excited to announce our new product line%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='twitter')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Technology That Moves Businesses Forward%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='linkedin')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Technology That Moves Businesses Forward%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='tiktok')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Our team delivered another successful project%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='instagram')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Our team delivered another successful project%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='linkedin')
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Our team delivered another successful project%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='tiktok')
) v WHERE v.post_id IS NOT NULL
ON CONFLICT (post_id, platform_id) DO NOTHING;

INSERT INTO post_publications (post_id, platform_id, account_id, status, external_post_id, published_at, error_message)
SELECT v.post_id, v.platform_id, v.account_id, v.status, v.external_post_id, v.published_at, v.error_message
FROM (
  SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1) AS post_id, (SELECT id FROM social_platforms WHERE slug='instagram') AS platform_id, (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='instagram') AND username='@1techlink.official') AS account_id, 'published' AS status, 'ig_1001' AS external_post_id, (NOW() - INTERVAL '2 days') AS published_at, NULL::TEXT AS error_message
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='linkedin'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='linkedin') AND username='1techlink'), 'failed',   NULL,       NULL, 'LinkedIn publishing failed: rate limit exceeded (demo).'
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='tiktok'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='tiktok') AND username='@1techlink'), 'published','tt_1001', (NOW() - INTERVAL '2 days'), NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='twitter'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='twitter') AND username='@1techlink'), 'published','tw_1001', (NOW() - INTERVAL '2 days'), NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Excited to announce our new product line%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='instagram'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='instagram') AND username='@1techlink.official'), 'published','ig_1002', (NOW() - INTERVAL '1 day'), NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Excited to announce our new product line%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='twitter'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='twitter') AND username='@1techlink'), 'published','tw_1002', (NOW() - INTERVAL '1 day'), NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Technology That Moves Businesses Forward%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='linkedin'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='linkedin') AND username='1techlink'), 'scheduled',NULL,NULL, NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Technology That Moves Businesses Forward%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='tiktok'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='tiktok') AND username='@1techlink'), 'scheduled',NULL,NULL, NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Our team delivered another successful project%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='instagram'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='instagram') AND username='@1techlink.official'), 'published','ig_1005', (NOW() - INTERVAL '5 hours'), NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Our team delivered another successful project%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='linkedin'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='linkedin') AND username='1techlink'), 'published','li_1005', (NOW() - INTERVAL '5 hours'), NULL
  UNION ALL SELECT (SELECT id FROM posts WHERE content LIKE 'Our team delivered another successful project%' ORDER BY id LIMIT 1), (SELECT id FROM social_platforms WHERE slug='tiktok'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='tiktok') AND username='@1techlink'), 'published','tt_1005', (NOW() - INTERVAL '5 hours'), NULL
) v
WHERE v.post_id IS NOT NULL
AND NOT EXISTS (
  SELECT 1 FROM post_publications x
  WHERE x.post_id = v.post_id AND x.platform_id = v.platform_id AND x.account_id IS NOT DISTINCT FROM v.account_id
);

-- One publication failed, so the post as a whole is only partially delivered.
UPDATE posts SET status='partial'
WHERE content LIKE 'At 1TechLink, we build technology solutions%'
AND EXISTS (SELECT 1 FROM post_publications pp JOIN posts p ON p.id = pp.post_id
            WHERE p.content LIKE 'At 1TechLink, we build technology solutions%' AND pp.status='failed');

-- ------------------------------------------------------------- notifications --
INSERT INTO notifications (platform_id, account_id, type, title, message, is_read)
SELECT v.platform_id, v.account_id, v.type, v.title, v.message, v.is_read
FROM (
  SELECT (SELECT id FROM social_platforms WHERE slug='instagram') AS platform_id, (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='instagram') AND username='@1techlink.official') AS account_id, 'mention' AS type, 'You were mentioned' AS title, '@tech_daily mentioned you in a story' AS message, FALSE AS is_read
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='linkedin'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='linkedin') AND username='1techlink'), 'follower', 'New follower',            'TechCorp started following you', TRUE
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='instagram'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='instagram') AND username='@1techlink.official'), 'message',  'New message',             'You have a new DM from @client_xyz', FALSE
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='tiktok'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='tiktok') AND username='@1techlink'), 'view',     'Video milestone',         'Your TikTok passed 10K views', FALSE
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='twitter'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='twitter') AND username='@1techlink'), 'alert',    'Post limit warning',      'You are close to the X monthly post cap', FALSE
) v
WHERE NOT EXISTS (
  SELECT 1 FROM notifications n
  WHERE n.platform_id = v.platform_id AND n.account_id IS NOT DISTINCT FROM v.account_id
    AND n.type = v.type AND n.title = v.title
);

-- ---------------------------------------------------------------- analytics --
-- uniq_platform_date (platform_id, date) is a unique index.
INSERT INTO analytics (platform_id, account_id, date, followers, follower_growth, likes, comments, shares, reach, impressions, engagement_rate)
SELECT v.platform_id, v.account_id, v.date, v.followers, v.follower_growth, v.likes, v.comments, v.shares, v.reach, v.impressions, v.engagement_rate
FROM (
  SELECT (SELECT id FROM social_platforms WHERE slug='instagram') AS platform_id, (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='instagram') AND username='@1techlink.official') AS account_id, CURRENT_DATE AS date, 8920 AS followers,  95 AS follower_growth, 520 AS likes, 68 AS comments, 34 AS shares,  6200 AS reach,  9800 AS impressions, 6.12 AS engagement_rate
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='instagram'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='instagram') AND username='@1techlink.official'), (CURRENT_DATE - INTERVAL '1 day'), 8825, 70, 445, 52, 28,  5900,  9100, 5.88
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='linkedin'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='linkedin') AND username='1techlink'), CURRENT_DATE, 5420,  45, 180, 24, 15,  4200,  6500, 3.90
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='linkedin'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='linkedin') AND username='1techlink'), (CURRENT_DATE - INTERVAL '1 day'), 5375, 30, 152, 18, 10, 3900,  6100, 3.65
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='tiktok'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='tiktok') AND username='@1techlink'), CURRENT_DATE,22400, 310, 980, 74, 52, 41000, 58000, 8.40
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='tiktok'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='tiktok') AND username='@1techlink'), (CURRENT_DATE - INTERVAL '1 day'),22090, 260, 910, 69, 48, 38000, 54000, 8.11
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='twitter'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='twitter') AND username='@1techlink'), CURRENT_DATE, 6350,  40,  95, 12, 22,  2100, 4800, 2.75
  UNION ALL SELECT (SELECT id FROM social_platforms WHERE slug='twitter'), (SELECT id FROM social_accounts WHERE platform_id=(SELECT id FROM social_platforms WHERE slug='twitter') AND username='@1techlink'), (CURRENT_DATE - INTERVAL '1 day'), 6310,  35,  88, 11, 19, 1950, 4500, 2.60
) v
ON CONFLICT (platform_id, "date") DO UPDATE SET
  account_id=EXCLUDED.account_id, followers=EXCLUDED.followers, follower_growth=EXCLUDED.follower_growth,
  likes=EXCLUDED.likes, comments=EXCLUDED.comments, shares=EXCLUDED.shares, reach=EXCLUDED.reach,
  impressions=EXCLUDED.impressions, engagement_rate=EXCLUDED.engagement_rate;

-- ------------------------------------------------------------- activity log --
INSERT INTO activity_logs (user_id, action, description, ip_address)
SELECT v.user_id, v.action, v.description, '127.0.0.1'
FROM (
  SELECT (SELECT id FROM users WHERE email='admin@1techlink.com') AS user_id, 'user.login' AS action, 'Admin logged in' AS description
  UNION ALL SELECT (SELECT id FROM users WHERE email='admin@1techlink.com'), 'post.created',   'Created post #1'
  UNION ALL SELECT (SELECT id FROM users WHERE email='admin@1techlink.com'), 'post.published', 'Published post #1 to Instagram'
  UNION ALL SELECT (SELECT id FROM users WHERE email='admin@1techlink.com'), 'account.connected', 'Connected Instagram account'
  UNION ALL SELECT (SELECT id FROM users WHERE email='manager@1techlink.com'), 'post.scheduled', 'Scheduled post #3'
) v
WHERE NOT EXISTS (
  SELECT 1 FROM activity_logs a
  WHERE a.user_id = v.user_id AND a.action = v.action AND a.description = v.description
);

-- ----------------------------------------------------------------- settings --
INSERT INTO settings ("key","value") VALUES
('site_name','1TechLink Social Hub'),
('demo_mode','1'),
('analytics_label','Demo data - simulated for presentation')
ON CONFLICT ("key") DO UPDATE SET "value"=EXCLUDED."value";

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
