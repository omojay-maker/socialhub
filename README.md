# 1TechLink Social Hub

Centralized social media management platform for 1TechLink - lightweight Buffer/Hootsuite/Meta Business Suite clone.

**Stack:** PHP 8.4 (PDO, API-only backend) + Vue 3 + Vite + Pinia + Vue Router (pure Vue frontend) + PostgreSQL + Chart.js + Apache

This version uses **purely Vue for the frontend** (Vue 3 SFC + Vite) and **core PHP for the backend** (JSON APIs). No PHP server-rendered views; all UI is Vue SPA calling PHP JSON endpoints.

## Architecture
```
frontend/          Vue 3 + Vite SPA (pure Vue)
  src/
    api/client.js  axios + CSRF + session (withCredentials)
    stores/auth.js Pinia auth (login/me/csrf)
    router/        hash history (no Apache rewrite needed)
    views/         Login, Dashboard, Posts, CreatePost, Calendar, Notifications, Analytics, Accounts, Media, Users, Logs
  vite.config.js   base /PHP_projects/social-hub/public/dist, build to ../public/dist, dev proxy to Apache

public/
  index.php        Serves Vue SPA (dist/index.html) - entry point
  dist/            Vite build output (served as SPA)
  api/             PHP JSON APIs (auth, dashboard, posts, publish, analytics, calendar, media, accounts, notifications, users, logs, csrf)
  legacy_index.php Fallback PHP views (deprecated, kept for reference)

app/
  Helpers/ Auth, Csrf, Validator, Logger
  Models/ User, Post, SocialAccount, Media, Notification, Analytics
  Services/Providers SocialMediaProvider interface + Mock/Facebook/Instagram/LinkedIn + ProviderFactory + Publisher
  Controllers/ PostController, DashboardController

config/ environment.php + database.php (PDO)
database/ schema.sql + seed.sql
storage/ uploads + logs
cron/ publish_scheduled_posts.php
views/ legacy PHP views (not used by Vue, kept for reference)
```

## Features
- Auth + RBAC (admin/content_manager), CSRF via header/body, session timeout, password_hash, httpOnly SameSite
- Dashboard (Vue): overview cards, platform overview, recent activity, Chart.js
- Social Accounts with abstraction layer + mock providers (demo mode)
- Create Post (Vue): content, hashtags, platforms, media, schedule → Save Draft / Schedule / Publish Now with per-platform result tracking
- Multi-platform publishing: tracks status per platform, shows published/failed separately, retry failed
- Scheduling + cron: `cron/publish_scheduled_posts.php` locks and publishes due posts
- Calendar (Vue): monthly grid, status colors
- Notifications: filter, mark read, aggregated mock
- Analytics: audience/engagement/reach, platform comparison, demo vs real label
- Media Library: secure upload (mime via finfo, 10MB limit, extension not trusted), reuse
- Posts: search/filter, view publications, delete
- Activity Logs

## Installation
```bash
# 1. DB (PostgreSQL — local, or Render Postgres via its External URL)
createdb social_hub
psql social_hub < database/schema.sql
php scripts/migrate.php
psql social_hub < database/seed.sql

# 2. Env
cp .env.example .env  # configure DB + FACEBOOK_CLIENT_ID etc

# 3. Permissions
chmod -R 775 storage

# 4. Frontend build (pure Vue)
cd frontend
npm install
npm run build  # outputs to public/dist

# 5. Visit Vue SPA (pure Vue frontend, PHP API backend)
http://localhost/PHP_projects/social-hub/public/index.php
# or
http://localhost/PHP_projects/social-hub/public/dist/
# Hash routing: #/login, #/dashboard, #/posts, etc

# For dev with hot reload (proxy APIs to Apache)
npm run dev  # -> http://localhost:5173 (proxy to http://localhost)

# Credentials
# Admin: admin@1techlink.com / password
# Manager: manager@1techlink.com / password
```

## Environment
See `.env.example` for `FACEBOOK_CLIENT_ID`, `INSTAGRAM_CLIENT_ID`, `LINKEDIN_CLIENT_ID`, `SOCIAL_MODE=mock`. Never commit `.env`.

## Demo Mode
`SOCIAL_MODE=mock` uses `MockSocialProvider` (85% success, LinkedIn fails more). UI behaves as if communicating with platforms, clearly labeled demo.

## Real API Readiness
Interface `SocialMediaProvider` isolates real vs mock. To go live: set `SOCIAL_MODE=live`, fill OAuth env vars, implement Graph API calls in `FacebookProvider` etc. No controller changes needed.

## Cron
```bash
*/5 * * * * php /var/www/html/PHP_projects/social-hub/cron/publish_scheduled_posts.php >> /var/www/html/PHP_projects/social-hub/storage/logs/cron.log 2>&1
php cron/publish_scheduled_posts.php  # manual
```

## Security
- password_hash / password_verify
- PDO prepared statements
- CSRF tokens (Vue sends via X-CSRF-TOKEN header + body)
- XSS escaping
- Session httpOnly, SameSite, timeout, regeneration
- File upload mime validation via finfo, size limits
- Env secrets

## Demo Flow (Vue)
1. Open `http://localhost/PHP_projects/social-hub/public/index.php` → redirects to `#/login`
2. Login as admin → Vue stores session via PHP cookie + Pinia
3. Dashboard → 3 accounts, follower stats (via `/public/api/dashboard.php`)
4. Notifications → `#/notifications`
5. Create Post → `#/create` → upload image → select FB/IG/LI → Publish Now → per-platform results from `/public/api/posts.php?action=create`
6. Posts → `#/posts` → view publications
7. Schedule another → Calendar → `#/calendar` shows scheduled
8. Analytics → `#/analytics` platform comparison
9. Activity Logs → `#/logs`

## PHP APIs (JSON)
- `public/api/auth.php?action=login|logout|me|csrf`
- `public/api/dashboard.php`
- `public/api/posts.php?action=list|create|delete` + `post_detail.php` + `publish.php`
- `public/api/analytics.php`
- `public/api/calendar.php`
- `public/api/media.php?action=list|delete` + upload
- `public/api/accounts.php?action=list|create|delete|sync`
- `public/api/notifications.php?action=list|read|read_all`
- `public/api/users.php?action=list|delete|create`
- `public/api/logs.php`
- `public/api/csrf.php`

All APIs check `Auth::check()` and return `{success, message, data}` with 401 if unauthenticated.

## Legacy PHP Views
`views/` + `public/legacy_index.php` + `login.php` (now redirects to Vue) are kept for reference but not used in pure Vue mode. To use legacy, add `?legacy=1` to login.php or run `php -S` with legacy flag.
