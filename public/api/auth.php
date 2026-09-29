<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../app/Helpers/Validator.php';
require_once __DIR__.'/../../app/Helpers/Logger.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/User.php';
require_once __DIR__.'/../../app/Services/Throttle.php';

define('BASE_URL', env_get('APP_URL', base_url_public()));

env_send_security_headers();
json_no_store();

Auth::start();
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($action === 'csrf') {
    echo json_encode(['success'=>true,'csrf_token'=>Csrf::token()]);
    exit;
}

if ($action === 'me') {
    if (!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
    echo json_encode(['success'=>true,'user'=>Auth::user(),'csrf_token'=>Csrf::token()]);
    exit;
}

if ($action === 'login' && $method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = trim((string)($input['email'] ?? $_POST['email'] ?? ''));
    $pass  = (string)($input['password'] ?? $_POST['password'] ?? '');

    // CSRF is required once a token has been issued for this session.
    $token = (string)($input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token !== '' && !Csrf::verify($token)) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Invalid CSRF token']);
        exit;
    }

    // Throttle per IP and per account to stop credential stuffing.
    $ipKey = 'login_ip_' . md5(request_ip());
    if (!Throttle::attempt($ipKey, (int)env_get('LOGIN_RATE_IP', 10), 300)) {
        Logger::warn('login throttled by ip', ['ip' => request_ip()]);
        http_response_code(429);
        header('Retry-After: 300');
        echo json_encode(['success'=>false,'message'=>'Too many attempts. Try again in a few minutes.']);
        exit;
    }
    if ($email !== '') {
        $key = 'login_acct_' . md5(strtolower($email));
        if (!Throttle::attempt($key, (int)env_get('LOGIN_RATE_ACCOUNT', 5), 900)) {
            http_response_code(429);
            header('Retry-After: 900');
            echo json_encode(['success'=>false,'message'=>'Too many attempts for this account.']);
            exit;
        }
    }

    if (!Validator::email($email)) {
        http_response_code(422);
        echo json_encode(['success'=>false,'message'=>'Enter a valid email address']);
        exit;
    }

    $user = UserModel::findByEmail($email);
    // Constant-ish work whether or not the user exists.
    $hash = $user['password'] ?? '$2y$10$usesomesillystringfoxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';
    $valid = password_verify($pass, $hash);

    if (!$user || !$valid) {
        Logger::warn('failed login', ['email' => $email, 'ip' => request_ip()]);
        usleep(250000);
        http_response_code(401);
        echo json_encode(['success'=>false,'message'=>'Invalid email or password']);
        exit;
    }
    if (!$user['is_active']) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'This account is disabled']);
        exit;
    }

    db()->prepare("UPDATE users SET last_login_at=NOW() WHERE id=?")->execute([$user['id']]);
    Auth::login([
        'id'    => (int)$user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ]);
    Throttle::clear($ipKey);
    activity_log((int)$user['id'], 'user.login', 'User logged in');
    Logger::info('login', ['user_id' => $user['id'], 'ip' => request_ip()]);

    echo json_encode([
        'success'    => true,
        'message'    => 'Logged in',
        'user'       => Auth::user(),
        'csrf_token' => Csrf::rotate(),
    ]);
    exit;
}

if ($action === 'logout') {
    activity_log(Auth::id(), 'user.logout', 'User logged out');
    Auth::logout();
    echo json_encode(['success'=>true,'message'=>'Logged out']);
    exit;
}

http_response_code(404);
echo json_encode(['success'=>false,'message'=>'Unknown auth action']);
