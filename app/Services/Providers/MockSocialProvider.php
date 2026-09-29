<?php
require_once __DIR__ . '/SocialMediaProvider.php';

/**
 * Demo provider. Used when SOCIAL_MODE=mock or when a platform has no
 * credentials configured, so the UI stays fully explorable.
 */
class MockSocialProvider implements SocialMediaProvider {
    private string $slug;
    private string $name;

    public function __construct(string $slug, string $name) {
        $this->slug = $slug;
        $this->name = $name;
    }

    /* --- OAuth: never real ------------------------------------------- */

    public function isConfigured(): bool { return false; }

    public function prepareStateContext(): array { return []; }

    public function authUrl(string $state, array $extra = []): string {
        throw new RuntimeException("{$this->name} OAuth is not configured");
    }

    public function exchange(string $code, array $extra = []): array {
        return ['token'=>null,'refresh'=>null,'expires_in'=>null,'scopes'=>null,'raw'=>[]];
    }

    public function refresh(?string $refreshToken, ?string $accessToken = null): array {
        return ['token'=>null,'refresh'=>null,'expires_in'=>null,'raw'=>[]];
    }

    public function profile(array $credentials, array $context = []): array {
        return [
            'account_name' => $context['account_name'] ?? ($this->name . ' Demo'),
            'username'     => $context['username'] ?? ('@' . strtolower($this->slug)),
            'account_type' => 'page',
            'external_account_id' => $context['external_account_id'] ?? ('demo_' . $this->slug),
            'external_user_id'     => null,
            'avatar_url'   => null,
            'followers'    => mt_rand(5000, 15000),
            'token'        => null, 'refresh' => null, 'expires_in' => null, 'scopes' => null,
            'meta'         => ['demo' => true],
        ];
    }

    public function getPlatformSlug(): string { return $this->slug; }
    public function connect(array $credentials): array {
        return ['success'=>true,'message'=>"Mock connected to {$this->name}",'demo'=>true];
    }
    public function disconnect(int $accountId): bool { return true; }
    public function publishPost(int $accountId, array $postData): array {
        $rand = mt_rand(1, 100);
        $fail = ($this->slug === 'linkedin' && $rand > 75) || $rand > 90;
        if ($fail) {
            return ['success'=>false,'external_id'=>null,
                    'error'=>"Mock {$this->name} publishing failed: rate limit exceeded (demo).",'demo'=>true];
        }
        return ['success'=>true,'external_id'=>$this->slug.'_'.uniqid(),'error'=>null,'demo'=>true];
    }
    public function getAccountInformation(int $accountId): array {
        return ['followers'=>mt_rand(5000,15000),'following'=>mt_rand(100,600),'posts'=>mt_rand(100,400),'engagement'=>mt_rand(300,600)/100,'demo'=>true];
    }
    public function getNotifications(int $accountId): array {
        return [
            ['type'=>'comment','title'=>'New comment','message'=>"Demo comment on {$this->name}",'demo'=>true],
            ['type'=>'reaction','title'=>'New reaction','message'=>"Demo reaction on {$this->name}",'demo'=>true],
        ];
    }
    public function getAnalytics(int $accountId, string $from, string $to): array {
        return ['followers'=>mt_rand(5000,15000),'likes'=>mt_rand(200,600),'comments'=>mt_rand(20,80),
                'shares'=>mt_rand(10,40),'reach'=>mt_rand(4000,9000),'impressions'=>mt_rand(6000,12000),'demo'=>true];
    }
    public function getPosts(int $accountId, int $limit = 10): array { return []; }
}
