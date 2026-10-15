<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../scripts/render_init_db.php';
env_send_security_headers();
json_no_store();

// Fresh deploys self-initialize on first contact and pending migrations
// converge silently on every hit (usually a single cheap SELECT). Failures
// are ignored here so a down database still surfaces properly at login.
try {
    render_ensure_db();
} catch (Throwable $e) {
    // Login/dashboard will report the real problem.
}

Auth::start();
header('Content-Type: application/json');
echo json_encode(['success'=>true,'csrf_token'=>Csrf::token()]);
