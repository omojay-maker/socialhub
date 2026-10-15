-- 006: retire demo data for platforms that already have a live connection.
-- New connections are handled by SocialAccountModel::retireDemoForPlatform()
-- at OAuth time; this converges databases connected before that existed.
-- Safe to run anywhere: with no live accounts every statement is a no-op.

-- Snapshot today's live counters first so the dashboard keeps a real row.
INSERT INTO analytics (platform_id, account_id, date, followers, follower_growth, likes, comments, shares, reach, impressions, engagement_rate)
SELECT platform_id, id, CURRENT_DATE, followers, 0, 0, 0, 0, 0, 0, 0
FROM social_accounts WHERE access_token IS NOT NULL AND connection_status='connected'
ON CONFLICT (platform_id, "date") DO UPDATE SET
  followers=EXCLUDED.followers, account_id=EXCLUDED.account_id;

-- Then drop token-less demo accounts of those platforms...
DELETE FROM social_accounts
WHERE access_token IS NULL AND refresh_token IS NULL
AND platform_id IN (SELECT DISTINCT platform_id FROM social_accounts WHERE access_token IS NOT NULL);

-- ...and any analytics left pointing at deleted (or no) accounts, keeping
-- only rows that belong to a real, still-present account.
DELETE FROM analytics
WHERE platform_id IN (SELECT DISTINCT platform_id FROM social_accounts WHERE access_token IS NOT NULL)
AND (account_id IS NULL OR account_id NOT IN (SELECT id FROM social_accounts));
