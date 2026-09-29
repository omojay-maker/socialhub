<?php
/**
 * Secrets template. Copy to config/secrets.php and fill in:
 *
 *   cp config/secrets.example.php config/secrets.php
 *   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # -> APP_KEY
 *
 * config/secrets.php is gitignored. Never commit the filled-in copy.
 *
 * Production secrets store.
 *
 * This file is PHP, not a flat .env, so a web server can never serve it as
 * plain text. Keep it OUT of version control and readable only by the web
 * server user (chmod 640). Every key can also be supplied as a real
 * environment variable (systemd, Docker, php-fpm pool) which takes precedence.
 *
 * Generate APP_KEY with:  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
 *
 * Redirect URIs are derived from APP_URL as
 *   <APP_URL>/public/api/oauth.php?action=callback&platform=<slug>
 * so you normally do NOT need to set *_REDIRECT_URI at all. If your portal
 * needs a different value, set it explicitly — it must match byte for byte.
 * docs/oauth-setup.md has the per-platform walkthrough.
 */
return [
    'APP_ENV'              => 'production',
    'APP_DEBUG'            => 'false',
    // Public origin, no trailing slash. This value builds the OAuth redirect
    // URIs, the media URLs handed to the platforms, and the CSP img-src, so it
    // must be a real absolute URL (https:// in production).
    'APP_URL'              => 'https://hub.example.com',
    'APP_KEY'              => '',   // REQUIRED in production: php -r 'echo bin2hex(random_bytes(32));',

    // 'mock' keeps the demo providers, 'live' uses the real platform APIs.
    'SOCIAL_MODE'          => 'mock',

    'DB_HOST'              => '127.0.0.1',
    'DB_PORT'              => '3306',
    'DB_DATABASE'          => 'social_hub',
    'DB_USERNAME'          => 'social_hub_app',
    'DB_PASSWORD'          => '',

    'SESSION_TIMEOUT'      => '1800',
    'SESSION_SECURE'       => 'auto',   // auto = secure cookies only when the request is HTTPS
    'TRUSTED_PROXIES'      => '',       // comma separated proxy IPs allowed to set X-Forwarded-Proto

    // Brute-force limits for POST api/auth.php?action=login
    'LOGIN_RATE_IP'        => '10',     // attempts per IP per window
    'LOGIN_RATE_ACCOUNT'   => '5',      // attempts per email per window

    // ---- Facebook / Meta -------------------------------------------------
    'FACEBOOK_CLIENT_ID'     => '',
    'FACEBOOK_CLIENT_SECRET' => '',
    'FACEBOOK_REDIRECT_URI'  => '',   // derived from APP_URL when empty
    'FACEBOOK_GRAPH_VERSION' => 'v21.0',
    'META_APP_SECRET'        => '',   // optional: verifies Meta webhook payloads

    // ---- Instagram (Instagram Graph API; needs a Business/Creator account
    //      linked to a Facebook Page, and a public HTTPS APP_URL) ----------
    'INSTAGRAM_CLIENT_ID'     => '',
    'INSTAGRAM_CLIENT_SECRET' => '',
    'INSTAGRAM_REDIRECT_URI'  => '',
    'INSTAGRAM_API_VERSION'   => 'v21.0',

    // ---- LinkedIn ---------------------------------------------------------
    'LINKEDIN_CLIENT_ID'     => '',
    'LINKEDIN_CLIENT_SECRET' => '',
    'LINKEDIN_REDIRECT_URI'  => '',
    'LINKEDIN_API_VERSION'   => '',   // e.g. 202601; defaults to two months ago
    'LINKEDIN_ORGANIZATION_URN' => '', // urn:li:organization:123 (omit to post as the member)

    // ---- TikTok (Login Kit + Content Posting API) --------------------------
    'TIKTOK_CLIENT_ID'     => '',
    'TIKTOK_CLIENT_SECRET' => '',
    'TIKTOK_REDIRECT_URI'  => '',
    // DIRECT_POST publishes straight to the feed; MEDIA_UPLOAD sends the draft
    // to the creator's TikTok app to finish there.
    'TIKTOK_POST_MODE'     => 'DIRECT_POST',
    // Must be one of the privacy_level_options from /v2/creator_info/query/.
    'TIKTOK_PRIVACY_LEVEL' => 'PUBLIC_TO_EVERYONE',

    // ---- X / Twitter (OAuth 2.0 + PKCE, X API v2) -------------------------
    'TWITTER_CLIENT_ID'     => '',
    'TWITTER_CLIENT_SECRET' => '',
    'TWITTER_REDIRECT_URI'  => '',
    'TWITTER_EXTRA_SCOPES'  => '',   // space separated extra scopes
    // X write endpoints still expect OAuth 1.0a user context. Without these the
    // account connects and reads fine but publishing reports a setup error.
    'TWITTER_CONSUMER_KEY'     => '',
    'TWITTER_CONSUMER_SECRET'  => '',
    'TWITTER_ACCESS_TOKEN'     => '',
    'TWITTER_ACCESS_TOKEN_SECRET' => '',

    'MAX_UPLOAD_SIZE'  => '10485760',
    'ALLOWED_IMAGE_TYPES' => 'jpg,jpeg,png,gif,webp',
    'ALLOWED_VIDEO_TYPES' => 'mp4,mov,avi,webm',

    // Public base URL used when handing media URLs to the platforms
    // (Instagram, TikTok and Facebook fetch the bytes over the internet).
    'PUBLIC_MEDIA_BASE' => '',

    // Extra origins allowed by the CSP connect-src directive. Only needed if
    // the browser ever talks to a platform API directly (it normally does not).
    'PLATFORM_API_ORIGINS' => '',
];
