-- 007: purge remaining demo seed content.
-- Live connections already retire their own platform's demo rows (006 +
-- retireDemoForPlatform at OAuth time). This removes the original seed rows
-- that predate those mechanisms, matched by exact seed content so anything
-- the user created is never touched.
--
-- Every destructive statement below is guarded on at least one live
-- (token-holding) account existing, so fresh installs and CI keep demo data.

-- Demo posts (cascades to post_platforms + post_publications).
DELETE FROM posts WHERE content IN (
'At 1TechLink, we build technology solutions that help businesses grow. #Innovation #Tech',
'Excited to announce our new product line launching next week! Stay tuned. #1TechLink #Launch',
'Technology That Moves Businesses Forward - Join us for a webinar on digital transformation.',
'Draft: Behind the scenes at 1TechLink HQ',
'Our team delivered another successful project! Client satisfaction is our priority.'
) AND EXISTS (SELECT 1 FROM social_accounts WHERE access_token IS NOT NULL);

-- Demo notifications (exact seed messages only).
DELETE FROM notifications WHERE message IN (
'Sarah Johnson commented: "Love this solution!"',
'@tech_daily mentioned you in a story',
'TechCorp started following you',
'Your post received 120 reactions',
'You have a new DM from @client_xyz',
'Your post was shared 15 times',
'Your TikTok passed 10K views',
'You are close to the X monthly post cap'
) AND EXISTS (SELECT 1 FROM social_accounts WHERE access_token IS NOT NULL);

-- Demo media rows (user uploads are untouched; files on disk are harmless).
DELETE FROM media WHERE filename IN (
'64e32f7b317ff9e8b3d36c7f50cb8d9d.jpg',
'5e65d5bc420cf292ca7772cb4b728123.jpg'
) AND EXISTS (SELECT 1 FROM social_accounts WHERE access_token IS NOT NULL);

-- Demo activity log lines (real user actions use different wording).
DELETE FROM activity_logs WHERE description IN (
'Admin logged in',
'Created post #1',
'Published post #1 to Facebook',
'Published post #1 to Instagram',
'Connected Facebook account',
'Connected Instagram account',
'Scheduled post #3'
) AND EXISTS (SELECT 1 FROM social_accounts WHERE access_token IS NOT NULL);

-- Token-less demo accounts on platforms that already have a live account.
DELETE FROM social_accounts
WHERE access_token IS NULL AND refresh_token IS NULL
AND platform_id IN (SELECT DISTINCT platform_id FROM social_accounts WHERE access_token IS NOT NULL);

-- Demo analytics: rows for platforms with no connected accounts at all, plus
-- orphan rows pointing at deleted (or no) accounts. Live snapshots stay.
-- Guarded: without any live account this matches everything, so require one.
DELETE FROM analytics
WHERE EXISTS (SELECT 1 FROM social_accounts WHERE access_token IS NOT NULL)
AND (
  platform_id NOT IN (SELECT DISTINCT platform_id FROM social_accounts WHERE connection_status='connected')
  OR account_id IS NULL
  OR account_id NOT IN (SELECT id FROM social_accounts)
);

-- Ensure every live account has today's snapshot row for the dashboard.
INSERT INTO analytics (platform_id, account_id, date, followers, follower_growth, likes, comments, shares, reach, impressions, engagement_rate)
SELECT platform_id, id, CURRENT_DATE, followers, 0, 0, 0, 0, 0, 0, 0
FROM social_accounts WHERE access_token IS NOT NULL AND connection_status='connected'
ON CONFLICT (platform_id, "date") DO UPDATE SET
  followers=EXCLUDED.followers, account_id=EXCLUDED.account_id;
