<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Helpers/Csrf.php';
env_send_security_headers();
json_no_store();

Auth::start();
header('Content-Type: application/json');
echo json_encode(['success'=>true,'csrf_token'=>Csrf::token()]);
