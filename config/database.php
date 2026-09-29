<?php
require_once __DIR__.'/environment.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = env_get('DB_HOST','127.0.0.1');
    $port = env_get('DB_PORT','3306');
    $db   = env_get('DB_DATABASE','social_hub');
    $user = env_get('DB_USERNAME','root');
    $pass = env_get('DB_PASSWORD','root');
    $charset = 'utf8mb4';
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $opts);
    return $pdo;
}
