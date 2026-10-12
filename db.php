<?php
// db.php - Database connection with SSL & environment variable support

if (!function_exists('env_val')) {
    function env_val($key, $default = null) {
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return $val;
        }
        return $default;
    }
}

// 1. Connection credentials (Environment Variables take precedence; fallback to TiDB Cloud cluster)
$host = env_val('DB_HOST') ?: (env_val('MYSQLHOST') ?: 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com');
$port = env_val('DB_PORT') ?: (env_val('MYSQLPORT') ?: '4000');
$db   = env_val('DB_NAME') ?: (env_val('MYSQLDATABASE') ?: 'competitive_cp');
$user = env_val('DB_USER') ?: (env_val('MYSQLUSER') ?: 'jB2n4xjUGg37Ctb.root');
$pass = env_val('DB_PASS') ?? (env_val('MYSQLPASSWORD') ?? '1c5Egb0MK4f8iCxA');
$charset = 'utf8mb4';

// Check for DATABASE_URL / MYSQL_URL
if ($dbUrl = (env_val('DATABASE_URL') ?: env_val('MYSQL_URL'))) {
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
    PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
];

// Check for system CA bundle on Vercel / Linux containers
$caLocations = [
    env_val('MYSQL_ATTR_SSL_CA'),
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/ca-bundle.pem',
];
foreach ($caLocations as $caPath) {
    if (!empty($caPath) && @file_exists($caPath)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
        break;
    }
}

$pdo = null;
$dbConnectionError = null;

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    $dbConnectionError = $e->getMessage();

    // If TiDB Cloud connection fails on local XAMPP environment, fallback to localhost
    if (in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'])) {
        try {
            $localDsn = "mysql:host=localhost;port=3306;dbname=competitive_cp;charset=utf8mb4";
            $pdo = new PDO($localDsn, 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $dbConnectionError = null;
        } catch (PDOException $e2) {
            // Keep original error
        }
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

require_once __DIR__ . '/helpers.php';
