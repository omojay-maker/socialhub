<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
require_once __DIR__.'/../../scripts/render_init_db.php';
env_send_security_headers();
json_no_store();

// Fresh deploys self-initialize on first contact: when the users table is
// missing, build the schema and seed demo data. Afterwards this is a no-op
// (the seed alone converges idempotently). Failures are ignored here so a
// down database still surfaces properly at login instead of here.
try {
    db()->query('SELECT 1 FROM users LIMIT 1');
} catch (Throwable $e) {
    try { render_init_db(); } catch (Throwable $ignored) {}
}

Auth::start();
header('Content-Type: application/json');
echo json_encode(['success'=>true,'csrf_token'=>Csrf::token()]);
