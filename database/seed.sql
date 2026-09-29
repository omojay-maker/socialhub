-- Seed data for demo mode.
--
-- Run after database/schema.sql and the migrations in database/migrations/:
--   mysql -h 127.0.0.1 -usocial_hub_app -p social_hub < database/seed.sql
--   php scripts/seed_demo_media.php      # installs the images referenced below
--
-- This script is re-runnable. Ids are never hardcoded: rows are matched on their
-- natural keys (users.email, social_platforms.slug, settings.key, media.filename,
-- the unique indexes on analytics and post_platforms) so a second run updates
-- in place instead of failing halfway and leaving the schema half-populated.
--
-- The demo users are admin@1techlink.com and manager@1techlink.com, both with
-- the password "password". Change them before exposing this to anyone.
USE social_hub;

-- ---------------------------------------------------------------- platforms --
INSERT INTO social_platforms (id, slug, name, icon, color, is_active) VALUES
(1, 'facebook',  'Facebook',     'facebook',  '#1877F2', 0),
(2, 'instagram', 'Instagram',    'instagram', '#E4405F', 1),
(3, 'linkedin',  'LinkedIn',     'linkedin',  '#0A66C2', 1),
(4, 'tiktok',    'TikTok',       'tiktok',    '#FE2C55', 1),
(5, 'twitter',   'X',            'twitter',   '#e7e9ea', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), icon=VALUES(icon), color=VALUES(color), is_active=VALUES(is_active);

-- Resolved by slug, never assumed, so migration order does not matter.
SET @fb := (SELECT id FROM social_platforms WHERE slug='facebook');
SET @ig := (SELECT id FROM social_platforms WHERE slug='instagram');
SET @li := (SELECT id FROM social_platforms WHERE slug='linkedin');
SET @tt := (SELECT id FROM social_platforms WHERE slug='tiktok');
SET @tw := (SELECT id FROM social_platforms WHERE slug='twitter');

-- -------------------------------------------------------------------- users --
-- password_hash('password', PASSWORD_DEFAULT)
INSERT INTO users (name, email, password, role) VALUES
('Admin User',      'admin@1techlink.com',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Content Manager', 'manager@1techlink.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'content_manager')
ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role);

SET @u1 := (SELECT id FROM users WHERE email='admin@1techlink.com');
SET @u2 := (SELECT id FROM users WHERE email='manager@1techlink.com');

-- ----------------------------------------------------------------- accounts --
-- access_token is NULL: these are demo channels, not real OAuth connections.
-- Real connections fill these columns with AES-256-GCM encrypted tokens.
INSERT INTO social_accounts (platform_id, user_id, account_name, username, account_type, connection_status, followers, following, posts_count, engagement_rate, last_synced_at)
SELECT v.platform_id, @u1, v.account_name, v.username, v.account_type, 'connected', v.followers, v.following, v.posts_count, v.engagement_rate, NOW()
FROM (
  SELECT @ig AS platform_id, '1TechLink' AS account_name, '@1techlink.official' AS username, 'business' AS account_type,  8920, 540,  278, 6.12
  UNION ALL SELECT @li, '1TechLink Company', '1techlink',           'company',   5420, 180,  156, 3.90
  UNION ALL SELECT @tt, '1TechLink',         '@1techlink',          'creator',  22400,  90,   61, 8.40
  UNION ALL SELECT @tw, '1TechLink',         '@1techlink',          'user',      6350, 410, 1280, 2.75
) v
WHERE NOT EXISTS (
  SELECT 1 FROM social_accounts a
  WHERE a.platform_id = v.platform_id AND a.username = v.username
);

SET @acc_fb := (SELECT id FROM social_accounts WHERE platform_id=@fb AND username='@1techlink');
SET @acc_ig := (SELECT id FROM social_accounts WHERE platform_id=@ig AND username='@1techlink.official');
SET @acc_li := (SELECT id FROM social_accounts WHERE platform_id=@li AND username='1techlink');
SET @acc_tt := (SELECT id FROM social_accounts WHERE platform_id=@tt AND username='@1techlink');
SET @acc_tw := (SELECT id FROM social_accounts WHERE platform_id=@tw AND username='@1techlink');

-- -------------------------------------------------------------------- media --
-- The filenames must match /^[a-f0-9]{32}\.[a-z0-9]{2,5}$/ or public/media.php
-- will refuse to serve them. The files are tracked in database/demo-media/ and
-- copied into storage/uploads/ by scripts/seed_demo_media.php.
INSERT INTO media (user_id, filename, original_name, file_path, file_type, file_size, width, height)
SELECT @u1, v.filename, v.original_name, CONCAT('uploads/', v.filename), v.file_type, v.file_size, v.width, v.height
FROM (
  SELECT '64e32f7b317ff9e8b3d36c7f50cb8d9d.jpg' AS filename, 'tech-launch.jpg' AS original_name, 'image/jpeg' AS file_type, 21778 AS file_size, 1200 AS width, 630 AS height
  UNION ALL SELECT '5e65d5bc420cf292ca7772cb4b728123.jpg', 'team.jpg', 'image/jpeg', 21782, 1200, 630
) v
WHERE NOT EXISTS (SELECT 1 FROM media m WHERE m.filename = v.filename);

-- -------------------------------------------------------------------- posts --
INSERT INTO posts (user_id, content, hashtags, status, scheduled_at, published_at)
SELECT @u1, v.content, v.hashtags, v.status, v.scheduled_at, v.published_at
FROM (
  SELECT 'At 1TechLink, we build technology solutions that help businesses grow. #Innovation #Tech' AS content, '#Innovation #Tech' AS hashtags, 'published' AS status, NULL AS scheduled_at, DATE_SUB(NOW(), INTERVAL 2 DAY) AS published_at
  UNION ALL SELECT 'Excited to announce our new product line launching next week! Stay tuned. #1TechLink #Launch', '#1TechLink #Launch', 'published', NULL, DATE_SUB(NOW(), INTERVAL 1 DAY)
  UNION ALL SELECT 'Technology That Moves Businesses Forward - Join us for a webinar on digital transformation.', '', 'scheduled', DATE_ADD(NOW(), INTERVAL 2 DAY), NULL
  UNION ALL SELECT 'Draft: Behind the scenes at 1TechLink HQ', '', 'draft', NULL, NULL
  UNION ALL SELECT 'Our team delivered another successful project! Client satisfaction is our priority.', '', 'published', NULL, DATE_SUB(NOW(), INTERVAL 5 HOUR)
) v
WHERE NOT EXISTS (SELECT 1 FROM posts p WHERE p.user_id = @u1 AND p.content = v.content);

SET @p1 := (SELECT id FROM posts WHERE content LIKE 'At 1TechLink, we build technology solutions%' ORDER BY id LIMIT 1);
SET @p2 := (SELECT id FROM posts WHERE content LIKE 'Excited to announce our new product line%' ORDER BY id LIMIT 1);
SET @p3 := (SELECT id FROM posts WHERE content LIKE 'Technology That Moves Businesses Forward%' ORDER BY id LIMIT 1);
SET @p5 := (SELECT id FROM posts WHERE content LIKE 'Our team delivered another successful project%' ORDER BY id LIMIT 1);

-- post_platforms has a unique (post_id, platform_id) index.
INSERT IGNORE INTO post_platforms (post_id, platform_id) VALUES
(@p1,@fb),(@p1,@ig),(@p1,@li),(@p1,@tt),(@p1,@tw),
(@p2,@fb),(@p2,@ig),(@p2,@tw),
(@p3,@fb),(@p3,@li),(@p3,@tt),
(@p5,@fb),(@p5,@ig),(@p5,@li),(@p5,@tt);

INSERT INTO post_publications (post_id, platform_id, account_id, status, external_post_id, published_at, error_message)
SELECT v.post_id, v.platform_id, v.account_id, v.status, v.external_post_id, v.published_at, v.error_message
FROM (
  SELECT @p1 AS post_id, @fb AS platform_id, @acc_fb AS account_id, 'published' AS status, 'fb_1001' AS external_post_id, DATE_SUB(NOW(), INTERVAL 2 DAY) AS published_at, NULL AS error_message
  UNION ALL SELECT @p1,@ig,@acc_ig,'published','ig_1001', DATE_SUB(NOW(), INTERVAL 2 DAY), NULL
  UNION ALL SELECT @p1,@li,@acc_li,'failed',   NULL,       NULL, 'LinkedIn publishing failed: rate limit exceeded (demo).'
  UNION ALL SELECT @p1,@tt,@acc_tt,'published','tt_1001', DATE_SUB(NOW(), INTERVAL 2 DAY), NULL
  UNION ALL SELECT @p1,@tw,@acc_tw,'published','tw_1001', DATE_SUB(NOW(), INTERVAL 2 DAY), NULL
  UNION ALL SELECT @p2,@fb,@acc_fb,'published','fb_1002', DATE_SUB(NOW(), INTERVAL 1 DAY), NULL
  UNION ALL SELECT @p2,@ig,@acc_ig,'published','ig_1002', DATE_SUB(NOW(), INTERVAL 1 DAY), NULL
  UNION ALL SELECT @p2,@tw,@acc_tw,'published','tw_1002', DATE_SUB(NOW(), INTERVAL 1 DAY), NULL
  UNION ALL SELECT @p3,@fb,@acc_fb,'scheduled',NULL,NULL, NULL
  UNION ALL SELECT @p3,@li,@acc_li,'scheduled',NULL,NULL, NULL
  UNION ALL SELECT @p3,@tt,@acc_tt,'scheduled',NULL,NULL, NULL
  UNION ALL SELECT @p5,@fb,@acc_fb,'published','fb_1005', DATE_SUB(NOW(), INTERVAL 5 HOUR), NULL
  UNION ALL SELECT @p5,@ig,@acc_ig,'published','ig_1005', DATE_SUB(NOW(), INTERVAL 5 HOUR), NULL
  UNION ALL SELECT @p5,@li,@acc_li,'published','li_1005', DATE_SUB(NOW(), INTERVAL 5 HOUR), NULL
  UNION ALL SELECT @p5,@tt,@acc_tt,'published','tt_1005', DATE_SUB(NOW(), INTERVAL 5 HOUR), NULL
) v
WHERE NOT EXISTS (
  SELECT 1 FROM post_publications x
  WHERE x.post_id = v.post_id AND x.platform_id = v.platform_id AND x.account_id = v.account_id
);

-- One publication failed, so the post as a whole is only partially delivered.
UPDATE posts SET status='partial' WHERE id=@p1
  AND EXISTS (SELECT 1 FROM post_publications WHERE post_id=@p1 AND status='failed');

-- ------------------------------------------------------------- notifications --
INSERT INTO notifications (platform_id, account_id, type, title, message, is_read)
SELECT v.platform_id, v.account_id, v.type, v.title, v.message, v.is_read
FROM (
  SELECT @ig AS platform_id, @acc_ig AS account_id, 'mention',  'You were mentioned',      '@tech_daily mentioned you in a story', 0
  UNION ALL SELECT @li,@acc_li,'follower', 'New follower',            'TechCorp started following you', 1
  UNION ALL SELECT @ig,@acc_ig,'message',  'New message',             'You have a new DM from @client_xyz', 0
  UNION ALL SELECT @tt,@acc_tt,'view',     'Video milestone',         'Your TikTok passed 10K views', 0
  UNION ALL SELECT @tw,@acc_tw,'alert',    'Post limit warning',      'You are close to the X monthly post cap', 0
) v
WHERE NOT EXISTS (
  SELECT 1 FROM notifications n
  WHERE n.platform_id = v.platform_id AND n.account_id = v.account_id
    AND n.type = v.type AND n.title = v.title
);

-- ---------------------------------------------------------------- analytics --
-- uniq_platform_date (platform_id, date) is a unique index.
INSERT INTO analytics (platform_id, account_id, date, followers, follower_growth, likes, comments, shares, reach, impressions, engagement_rate)
SELECT v.platform_id, v.account_id, v.date, v.followers, v.follower_growth, v.likes, v.comments, v.shares, v.reach, v.impressions, v.engagement_rate
FROM (
  SELECT @ig AS platform_id, @acc_ig AS account_id, CURDATE(), 8920,  95, 520, 68, 34,  6200,  9800, 6.12
  UNION ALL SELECT @ig,@acc_ig, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 8825, 70, 445, 52, 28,  5900,  9100, 5.88
  UNION ALL SELECT @li,@acc_li, CURDATE(), 5420,  45, 180, 24, 15,  4200,  6500, 3.90
  UNION ALL SELECT @li,@acc_li, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 5375, 30, 152, 18, 10, 3900,  6100, 3.65
  UNION ALL SELECT @tt,@acc_tt, CURDATE(),22400, 310, 980, 74, 52, 41000, 58000, 8.40
  UNION ALL SELECT @tt,@acc_tt, DATE_SUB(CURDATE(), INTERVAL 1 DAY),22090, 260, 910, 69, 48, 38000, 54000, 8.11
  UNION ALL SELECT @tw,@acc_tw, CURDATE(), 6350,  40,  95, 12, 22,  2100,  4800, 2.75
  UNION ALL SELECT @tw,@acc_tw, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 6310,  35,  88, 11, 19,  1950,  4500, 2.60
) v
ON DUPLICATE KEY UPDATE
  account_id=VALUES(account_id), followers=VALUES(followers), follower_growth=VALUES(follower_growth),
  likes=VALUES(likes), comments=VALUES(comments), shares=VALUES(shares), reach=VALUES(reach),
  impressions=VALUES(impressions), engagement_rate=VALUES(engagement_rate);

-- ------------------------------------------------------------- activity log --
INSERT INTO activity_logs (user_id, action, description, ip_address)
SELECT v.user_id, v.action, v.description, '127.0.0.1'
FROM (
  SELECT @u1 AS user_id, 'user.login' AS action, 'Admin logged in' AS description
  UNION ALL SELECT @u1, 'post.created',   'Created post #1'
  UNION ALL SELECT @u1, 'post.published', 'Published post #1 to Instagram'
  UNION ALL SELECT @u1, 'account.connected', 'Connected Instagram account'
  UNION ALL SELECT @u2, 'post.scheduled', 'Scheduled post #3'
) v
WHERE NOT EXISTS (
  SELECT 1 FROM activity_logs a
  WHERE a.user_id = v.user_id AND a.action = v.action AND a.description = v.description
);

-- ----------------------------------------------------------------- settings --
INSERT INTO settings (`key`,`value`) VALUES
('site_name','1TechLink Social Hub'),
('demo_mode','1'),
('analytics_label','Demo data - simulated for presentation')
ON DUPLICATE KEY UPDATE `value`=VALUES(`value`);
