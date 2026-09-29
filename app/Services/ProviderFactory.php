<?php
require_once __DIR__ . '/Providers/SocialMediaProvider.php';
require_once __DIR__ . '/Providers/AbstractSocialProvider.php';
require_once __DIR__ . '/Providers/MockSocialProvider.php';
require_once __DIR__ . '/Providers/FacebookProvider.php';
require_once __DIR__ . '/Providers/InstagramProvider.php';
require_once __DIR__ . '/Providers/LinkedInProvider.php';
require_once __DIR__ . '/Providers/TikTokProvider.php';
require_once __DIR__ . '/Providers/XProvider.php';

class ProviderFactory {
    /**
     * Slugs that have a real OAuth implementation. The OAuth endpoints use
     * this as the allow-list so adding a provider never means hunting down
     * hard-coded platform arrays across the codebase.
     */
    const SUPPORTED = ['instagram', 'linkedin', 'tiktok', 'twitter'];

    /** True when the app is allowed to talk to live platform APIs. */
    public static function liveMode(): bool {
        return strtolower((string)env_get('SOCIAL_MODE', 'mock')) === 'live';
    }

    public static function supports(string $slug): bool {
        return in_array(strtolower(trim($slug)), self::SUPPORTED, true);
    }

    /**
     * Returns the real provider when it is configured and live mode is on,
     * otherwise the demo provider so the product stays usable.
     */
    public static function make(string $slug, bool $preferMock = false): SocialMediaProvider {
        $provider = self::real($slug);
        if ($provider === null) {
            return new MockSocialProvider($slug, ucfirst($slug));
        }
        if ($preferMock || !self::liveMode() || !$provider->isConfigured()) {
            return new MockSocialProvider($slug, $provider->getDisplayName());
        }
        return $provider;
    }

    /** Real provider regardless of mode — used by the OAuth endpoints. */
    public static function real(string $slug): ?AbstractSocialProvider {
        $slug = strtolower(trim($slug));
        return match ($slug) {
            'facebook' => new FacebookProvider(),
            'instagram'=> new InstagramProvider(),
            'linkedin' => new LinkedInProvider(),
            'tiktok'   => new TikTokProvider(),
            'twitter', 'x' => new XProvider(),
            default    => null,
        };
    }

    /** @return array<string,SocialMediaProvider> */
    public static function all(bool $preferMock = false): array {
        $slugs = db()->query("SELECT slug FROM social_platforms WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        $out = [];
        foreach ($slugs as $slug) $out[$slug] = self::make((string)$slug, $preferMock);
        return $out;
    }

    /** Platform rows enriched with configuration state for the UI. */
    public static function statusForUi(): array {
        $rows = SocialAccountModel::platforms();
        $stmt = db()->prepare("SELECT COUNT(*) FROM social_accounts WHERE platform_id=? AND connection_status='connected'");
        foreach ($rows as &$p) {
            $real = self::real((string)$p['slug']);
            $p['is_configured'] = $real ? $real->isConfigured() : false;
            $p['can_connect']  = $p['is_configured'] && self::liveMode();
            $stmt->execute([(int)$p['id']]);
            $p['connected'] = (int)($stmt->fetchColumn() ?: 0);
            $p['mode']      = self::liveMode() ? 'live' : 'mock';
            $p['supported'] = $real !== null;
            $p['setup_hint'] = self::setupHint((string)$p['slug']);
        }
        return $rows;
    }

    /**
     * Per-platform setup note shown in the UI. These encode the review rules
     * each portal enforces, which are the first thing that blocks a connection.
     */
    public static function setupHint(string $slug): ?string {
        return match (strtolower($slug)) {
            'facebook'  => 'A Meta app with the Facebook Login product, plus a Page the author can see.',
            'instagram' => 'Requires a public HTTPS URL and an Instagram Business or Creator account linked to a Facebook Page.',
            'linkedin'  => 'A LinkedIn app with "Sign In with LinkedIn". Add r_organization_social to post as a company page.',
            'tiktok'    => 'A TikTok for Developers app with the Content Posting API approved. Unaudited apps publish privately.',
            'twitter'   => 'An X developer app. offline.access is required for refresh tokens; publishing needs an OAuth 1.0a user token.',
            default     => null,
        };
    }
}
