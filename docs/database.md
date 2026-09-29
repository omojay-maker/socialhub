# Database

Tables: users, social_platforms, social_accounts, media, posts, post_platforms, post_publications, notifications, analytics, activity_logs, settings

ER: users 1-N posts, posts N-M platforms via post_platforms, posts 1-N post_publications (per-platform result), social_accounts N-1 platforms, media N-1 users, analytics per platform per day.

Seeds provide immediate demo dashboard. Foreign keys enforced.

