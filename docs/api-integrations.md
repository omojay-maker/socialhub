# API Integrations

Interface: `SocialMediaProvider` (connect, disconnect, publishPost, getAccountInformation, getNotifications, getAnalytics, getPosts)

Mock provider simulates 85% success; LinkedIn fails more to demo per-platform tracking. Demo flag clearly labeled in UI.

Real integration: set SOCIAL_MODE=live, fill FACEBOOK_CLIENT_ID etc from Meta/LinkedIn developer portals. Providers already isolate real vs mock branch. Do not hard-code fake responses in controllers.

OAuth flow placeholder: add routes `api/auth/{platform}/callback` to exchange code for token and store in social_accounts.access_token.

Env: `FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`, etc per .env.example.

