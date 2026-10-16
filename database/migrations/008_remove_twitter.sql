-- 008: remove X (Twitter) from the platform catalogue.
-- The product covers Instagram, LinkedIn and TikTok only. Historical X rows
-- are dropped; reconnecting is impossible without a provider, so keeping
-- them would only feed dead data to the dashboard.

UPDATE social_platforms SET is_active=FALSE WHERE slug IN ('twitter', 'facebook');

DELETE FROM social_accounts WHERE platform_id IN (SELECT id FROM social_platforms WHERE slug='twitter');
DELETE FROM analytics WHERE platform_id IN (SELECT id FROM social_platforms WHERE slug='twitter');
DELETE FROM notifications WHERE platform_id IN (SELECT id FROM social_platforms WHERE slug='twitter');
