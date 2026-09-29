<?php
/**
 * OAuth entry points.
 *
 *   GET  ?action=start&platform=facebook|instagram|linkedin|tiktok|twitter
 *         -> 302 to the platform authorization dialog
 *   GET  ?action=callback&platform=...&code=...&state=...
 *         -> exchanges the code, stores encrypted credentials, 302 back to the SPA
 *   GET  ?action=status
 *         -> JSON configuration state for the Accounts screen
 *   POST ?action=disconnect
 *         -> revokes upstream and removes the stored account
 *
 * The redirect URIs registered with each platform must point at
 * <APP_URL>/public/api/oauth.php?action=callback&platform=<slug>
 * Exactly. See docs/oauth-setup.md.
 */
require_once __DIR__ . '/../../config/environment.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../app/Helpers/Auth.php';
require_once __DIR__ . '/../../app/Helpers/Csrf.php';
require_once __DIR__ . '/../../app/Helpers/Logger.php';
require_once __DIR__ . '/../../app/Helpers/helpers.php';
require_once __DIR__ . '/../../app/Models/SocialAccount.php';
require_once __DIR__ . '/../../app/Services/OAuth.php';
require_once __DIR__ . '/../../app/Services/ProviderFactory.php';

define('BASE_URL', env_get('APP_URL', base_url_public()));

env_send_security_headers();
json_no_store();

function platform_id_for(string $slug): int {
    $stmt = db()->prepare("SELECT id FROM social_platforms WHERE slug=? AND is_active=1 LIMIT 1");
    $stmt->execute([$slug]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Every provider below (Meta, LinkedIn, TikTok, X) rejects a plain-http
 * redirect URI outside of native-app localhost testing, and the platform needs
 * to reach our signed media URLs over TLS. So live OAuth requires HTTPS.
 */
const HTTPS_ONLY_PLATFORMS = ['facebook', 'instagram', 'linkedin', 'tiktok', 'twitter'];

env_send_security_headers();
json_no_store();
Auth::start();

$action   = (string)($_GET['action'] ?? '');
$platform = strtolower((string)($_GET['platform'] ?? ''));

/* ------------------------------------------------------------------ status */

if ($action === 'status') {
    if (!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    echo json_encode([
        'success'   => true,
        'mode'      => ProviderFactory::liveMode() ? 'live' : 'mock',
        'platforms' => ProviderFactory::statusForUi(),
        'flash'     => OAuth::takeFlash(),
    ]);
    exit;
}

/* -------------------------------------------------------------------- start */

if ($action === 'start') {
    if (!Auth::check()) { OAuth::backWithFlash('error', 'Sign in before connecting an account.'); }
    if (!ProviderFactory::supports($platform)) {
        OAuth::backWithFlash('error', 'Unknown platform');
    }

    $provider = ProviderFactory::real($platform);
    if ($provider === null) {
        OAuth::backWithFlash('error', 'Unsupported platform');
    }
    if (!ProviderFactory::liveMode()) {
        OAuth::backWithFlash('error', 'Set SOCIAL_MODE=live and add the ' . $provider->getDisplayName() . ' app credentials to connect a real account.');
    }
    if (!$provider->isConfigured()) {
        OAuth::backWithFlash('error', $provider->getDisplayName() . ' is not configured yet. Add its client id, client secret and redirect URI.');
    }

    // Meta refuses non-HTTPS redirect URIs, and the other providers reject them
    // too outside of native-app testing.
    if (in_array($platform, HTTPS_ONLY_PLATFORMS, true) && !request_is_https()) {
        OAuth::backWithFlash('error', $provider->getDisplayName() . ' requires a public HTTPS URL. '
            . 'Serve the app over TLS, then set APP_URL to the https origin.');
    }

    $platformId = platform_id_for($platform);
    if ($platformId === 0) {
        OAuth::backWithFlash('error', 'That platform is not enabled on this installation');
    }

    try {
        // Anything the callback will need later (e.g. a PKCE verifier) is
        // captured here and stored with the state.
        $context = array_merge(
            ['user_id' => (int)Auth::id(), 'platform_id' => $platformId],
            $provider->prepareStateContext()
        );
        $state = OAuth::issueState($platform, $context);
        $url   = $provider->authUrl($state, $context);
        Logger::info('oauth start', ['platform' => $platform, 'user_id' => Auth::id()]);
        OAuth::redirect($url);
    } catch (Throwable $e) {
        Logger::error('oauth start failed', ['platform'=>$platform,'error'=>$e->getMessage()]);
        OAuth::backWithFlash('error', 'Could not start the connection: ' . $e->getMessage());
    }
}

/* ----------------------------------------------------------------- callback */

if ($action === 'callback') {
    $code  = (string)($_GET['code'] ?? '');
    $state = (string)($_GET['state'] ?? '');
    $denied = isset($_GET['error']);

    if (!Auth::check()) {
        // The session may have expired while the user was on the platform.
        OAuth::flash('error', 'Your session expired during the connection. Please sign in and try again.');
        OAuth::redirect(OAuth::appUrl('public/#/accounts'));
    }
    if (!ProviderFactory::supports($platform)) {
        OAuth::backWithFlash('error', 'Callback received an unknown platform');
    }

    $context = OAuth::consumeState($state, $platform);
    if ($context === null) {
        OAuth::backWithFlash('error', 'The connection request expired or was tampered with. Please try again.');
    }
    if ((int)($context['user_id'] ?? 0) !== (int)Auth::id()) {
        OAuth::backWithFlash('error', 'The connection was started from a different account.');
    }
    if ($denied) {
        OAuth::backWithFlash('error', 'Connection cancelled: ' . ((string)($_GET['error_description'] ?? $_GET['error'])));
    }
    if ($code === '') {
        OAuth::backWithFlash('error', 'The platform did not return an authorization code.');
    }

    $provider = ProviderFactory::real($platform);
    if ($provider === null) OAuth::backWithFlash('error', 'Unsupported platform');

    try {
        $credentials = $provider->exchange($code, $context);
        $profile     = $provider->profile($credentials, $context);

        if (empty($profile['external_account_id'])) {
            throw new RuntimeException('The platform did not identify the account to connect.');
        }

        $accountId = SocialAccountModel::upsertConnected([
            'platform_id'          => (int)($context['platform_id'] ?? platform_id_for($platform)),
            'external_account_id'  => $profile['external_account_id'],
            'external_user_id'     => $profile['external_user_id'] ?? null,
            'account_name'         => $profile['account_name'],
            'username'             => $profile['username'] ?? null,
            'account_type'         => $profile['account_type'] ?? 'page',
            'avatar_url'           => $profile['avatar_url'] ?? null,
            'followers'            => (int)($profile['followers'] ?? 0),
            'meta'                 => $profile['meta'] ?? [],
        ], (int)Auth::id());

        SocialAccountModel::storeToken(
            $accountId,
            $profile['token'] ?? null,
            $profile['refresh'] ?? null,
            $profile['expires_in'] ?? null,
            $profile['scopes'] ?? null
        );
        // The Page/IG token can expire independently of the user token.
        if (!empty($profile['meta']['expires_in'])) {
            SocialAccountModel::storeToken($accountId, null, null, (int)$profile['meta']['expires_in']);
        }

        activity_log((int)Auth::id(), 'account.connected',
            "Connected {$provider->getDisplayName()} account #{$accountId} ({$profile['account_name']})");
        Logger::info('oauth connected', ['platform'=>$platform,'account_id'=>$accountId,'user_id'=>Auth::id()]);

        OAuth::backWithFlash('success',
            $profile['account_name'] . ' is now connected to ' . $provider->getDisplayName() . '.');
    } catch (Throwable $e) {
        Logger::error('oauth callback failed', ['platform'=>$platform,'error'=>$e->getMessage()]);
        OAuth::backWithFlash('error', $provider->getDisplayName() . ' connection failed: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------- disconnect */

if ($action === 'disconnect' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    if (!Csrf::verify((string)($in['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
        exit;
    }
    $id = (int)($_GET['id'] ?? $in['id'] ?? 0);
    $account = SocialAccountModel::find($id);
    if (!$account) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Account not found']); exit; }

    if (!Auth::isAdmin() && (int)($account['user_id'] ?? 0) !== (int)Auth::id()) {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Not allowed']); exit;
    }

    $provider = ProviderFactory::real((string)$account['slug']);
    if ($provider) $provider->disconnect($id);   // revoke upstream

    SocialAccountModel::delete($id);
    activity_log(Auth::id(), 'account.disconnected', "Disconnected account #$id ({$account['platform_name']})");
    echo json_encode(['success'=>true,'message'=>"Disconnected {$account['account_name']}"]);
    exit;
}

http_response_code(404);
echo json_encode(['success'=>false,'message'=>'Unknown OAuth action']);
