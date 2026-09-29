# Architecture

```
config/  environment + database PDO
app/
  Helpers/ Auth, Csrf, Validator, Logger, helpers
  Models/ User, Post, SocialAccount, Media, Notification, Analytics
  Services/Providers/ SocialMediaProvider interface + Mock/Facebook/Instagram/LinkedIn + ProviderFactory + Publisher
  Controllers/ AuthController, DashboardController, PostController
public/  index.php (router via ?page=) + api/* JSON endpoints
views/   layouts/app.php + dashboard/posts/calendar/etc
storage/ uploads + logs
cron/    publish_scheduled_posts.php
database/ schema.sql + seed.sql
assets/  css/app.css + js/app.js (Vue 3 CDN for composer)
```

Separation of concerns: DB logic in Models, business in Services, controllers thin, views dumb. Provider abstraction prevents scattered platform logic. Env-based config for secrets.
