<?php
require_once __DIR__.'/../../config/environment.php';
require_once __DIR__.'/../../config/database.php';
require_once __DIR__.'/../../app/Helpers/Auth.php';
require_once __DIR__.'/../../app/Helpers/helpers.php';
require_once __DIR__.'/../../app/Models/Analytics.php';
define('BASE_URL', env_get('APP_URL','http://localhost/PHP_projects/social-hub'));

env_send_security_headers();
json_no_store();
Auth::start();
header('Content-Type: application/json');
if (!Auth::check()) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }
$summary = AnalyticsModel::summary();
$byPlatform = AnalyticsModel::byPlatform();
$timeline = AnalyticsModel::timeline(null,7);
echo json_encode(['success'=>true,'summary'=>$summary,'byPlatform'=>$byPlatform,'timeline'=>$timeline]);
