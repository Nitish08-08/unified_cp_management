<?php
// db.php - Database connection with environment variable support for cloud deployment (Vercel, Railway, Render, etc.)

$host = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: 'localhost');
$port = getenv('DB_PORT') ?: (getenv('MYSQLPORT') ?: '3306');
$db   = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'competitive_cp');
$user = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root');
$pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '');
$charset = 'utf8mb4';

// Check for full database connection URL (e.g. DATABASE_URL or MYSQL_URL)
if ($dbUrl = (getenv('DATABASE_URL') ?: getenv('MYSQL_URL'))) {
    $parsed = parse_url($dbUrl);
    if ($parsed) {
        $host = $parsed['host'] ?? $host;
        $port = $parsed['port'] ?? $port;
        $user = $parsed['user'] ?? $user;
        $pass = $parsed['pass'] ?? $pass;
        $db   = ltrim($parsed['path'] ?? '', '/') ?: $db;
    }
}

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Enable SSL CA if provided (required by cloud providers like TiDB, Aiven, PlanetScale)
if ($sslCa = getenv('MYSQL_ATTR_SSL_CA')) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
}

$pdo = null;
$dbConnectionError = null;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    $dbConnectionError = $e->getMessage();
    // Do not die() immediately so static & landing views can still render with friendly alerts
}

if (session_status() === PHP_SESSION_NONE) {
    // Configure session cookie params for secure environments
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

require_once __DIR__ . '/helpers.php';
