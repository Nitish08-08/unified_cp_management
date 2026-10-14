<?php
// db.php - Production TiDB Cloud & MySQL connection handler

if (!ob_get_level()) {
    ob_start();
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_samesite', 'Lax');
    if ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// Load .env file if present
$envFile = dirname(__DIR__) . '/.env';
if (!file_exists($envFile)) {
    $envFile = __DIR__ . '/.env';
}
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

// CA certificate detection
$caFile = dirname(__DIR__) . '/certs/ca.pem';
if (!file_exists($caFile) || !is_readable($caFile)) {
    $altCas = [
        __DIR__ . '/certs/ca.pem',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/ssl/cert.pem',
    ];
    foreach ($altCas as $alt) {
        if (file_exists($alt) && is_readable($alt)) {
            $caFile = $alt;
            break;
        }
    }
}

// PHP 8.5 vs older PHP PDO constants resolution
$attrCa = defined('Pdo\Mysql::ATTR_SSL_CA') ? constant('Pdo\Mysql::ATTR_SSL_CA') : (defined('PDO::MYSQL_ATTR_SSL_CA') ? PDO::MYSQL_ATTR_SSL_CA : 1008);
$attrVerify = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT : 1013);

$strategies = [
    // 1. SSL with CA and verify false (optimal for serverless TiDB)
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        $attrCa                      => $caFile,
        $attrVerify                  => false,
    ],
    // 2. SSL with CA only
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        $attrCa                      => $caFile,
    ],
    // 3. Fallback without SSL (for local dev)
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ],
];

$pdo = null;
$dbConnectionError = null;
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

foreach ($strategies as $opts) {
    try {
        $pdo = new PDO($dsn, $user, $pass, $opts);
        $dbConnectionError = null;
        break;
    } catch (PDOException $e) {
        $dbConnectionError = $e->getMessage();
    }
}

// Localhost fallback if TiDB unreachable from local machine
if ($pdo === null && in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'])) {
    try {
        $localDsn = "mysql:host=localhost;port=3306;dbname=competitive_cp;charset=utf8mb4";
        $pdo = new PDO($localDsn, 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $dbConnectionError = null;
    } catch (PDOException $e2) {
        // Keep original error
    }
}

// Auto-seed schema if tables missing
if ($pdo !== null) {
    try {
        $tables = $pdo->query("SHOW TABLES;")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('users', $tables)) {
            $schemaFile = dirname(__DIR__) . '/database/clean_schema.sql';
            if (!file_exists($schemaFile)) {
                $schemaFile = __DIR__ . '/clean_schema.sql';
            }
            if (file_exists($schemaFile)) {
                $sqlContent = file_get_contents($schemaFile);
                $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
                foreach ($statements as $stmt) {
                    if ($stmt !== '' && stripos($stmt, 'CREATE DATABASE') === false && stripos($stmt, 'USE ') === false) {
                        $pdo->exec($stmt);
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Ignore table check errors
    }
}

// Add app directories to include_path for maximum resilience
$baseDir = dirname(__DIR__);
set_include_path(
    get_include_path() . PATH_SEPARATOR .
    $baseDir . '/includes' . PATH_SEPARATOR .
    $baseDir . '/config' . PATH_SEPARATOR .
    $baseDir . '/services' . PATH_SEPARATOR .
    $baseDir . '/public'
);

$helpersFile = dirname(__DIR__) . '/includes/helpers.php';
if (file_exists($helpersFile)) {
    require_once $helpersFile;
} else {
    require_once __DIR__ . '/helpers.php';
}
