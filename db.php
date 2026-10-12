<?php
// db.php - Database connection with multi-strategy SSL & .env support

if (!ob_get_level()) {
    ob_start();
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// Load .env file if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

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

$host = env_val('DB_HOST', 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com');
$port = env_val('DB_PORT', '4000');
$db   = env_val('DB_NAME', 'competitive_cp');
$user = env_val('DB_USER', 'jB2n4xjUGg37Ctb.root');
$pass = env_val('DB_PASS', '1c5Egb0MK4f8iCxA');
$charset = 'utf8mb4';

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

// Connection strategies to handle cloud TLS / OpenSSL in serverless environments
$strategies = [];

// Strategy 1: Standard auto-negotiated TLS (recommended for PHP 8.2+ mysqlnd)
$strategies[] = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

// Strategy 2: Bundled Mozilla CA certificate (if present)
if (file_exists(__DIR__ . '/certs/ca.pem')) {
    $strategies[] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => __DIR__ . '/certs/ca.pem',
    ];
}

// Strategy 3: System CA certificate on Linux / Vercel containers
if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
    $strategies[] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => '/etc/ssl/certs/ca-certificates.crt',
    ];
}

// Strategy 4: Explicit disable verify server cert
$strategies[] = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    1014                         => false,
];

$pdo = null;
$dbConnectionError = null;

foreach ($strategies as $opts) {
    try {
        $pdo = new PDO($dsn, $user, $pass, $opts);
        $dbConnectionError = null;
        break; // Successfully connected!
    } catch (PDOException $e) {
        $dbConnectionError = $e->getMessage();
    }
}

// Localhost fallback for local development
if ($pdo === null && in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'])) {
    try {
        $localDsn = "mysql:host=localhost;port=3306;dbname=competitive_cp;charset=utf8mb4";
        $pdo = new PDO($localDsn, 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $dbConnectionError = null;
    } catch (PDOException $e2) {
        // Retain original error
    }
}

require_once __DIR__ . '/helpers.php';
