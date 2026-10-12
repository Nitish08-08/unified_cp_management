<?php
// db.php - Resilient TiDB Cloud & MySQL connection handler

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

// Locate CA certificate bundle for cloud SSL
$caCandidates = [
    __DIR__ . '/certs/ca.pem',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/ssl/cert.pem',
];
$foundCa = null;
foreach ($caCandidates as $caPath) {
    if (file_exists($caPath) && is_readable($caPath)) {
        $foundCa = $caPath;
        break;
    }
}

// Build SSL connection strategies (1007 = MYSQL_ATTR_SSL_CA, 1014 = MYSQL_ATTR_SSL_VERIFY_SERVER_CERT)
$strategies = [];

if ($foundCa) {
    // Strategy 1: CA file with verify server cert disabled (most reliable across clouds)
    $strategies[] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => $foundCa,
        1014                         => false,
    ];

    // Strategy 2: CA file with verify server cert enabled
    $strategies[] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => $foundCa,
        1014                         => true,
    ];
}

// Strategy 3: AWS Lambda system CA
if (file_exists('/etc/pki/tls/certs/ca-bundle.crt')) {
    $strategies[] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => '/etc/pki/tls/certs/ca-bundle.crt',
        1014                         => false,
    ];
}

// Strategy 4: Linux system CA
if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
    $strategies[] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => '/etc/ssl/certs/ca-certificates.crt',
        1014                         => false,
    ];
}

// Strategy 5: Plain connection (for local development or proxies)
$strategies[] = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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

        // If database does not exist yet on TiDB Cloud, auto-create it
        if (strpos($dbConnectionError, '1049') !== false || stripos($dbConnectionError, 'Unknown database') !== false) {
            try {
                $rootDsn = "mysql:host=$host;port=$port;charset=$charset";
                $rootPdo = new PDO($rootDsn, $user, $pass, $opts);
                $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$db`;");
                $rootPdo->exec("USE `$db`;");
                $pdo = $rootPdo;
                $dbConnectionError = null;
                break;
            } catch (PDOException $e2) {
                // Keep trying
            }
        }
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
        // Retain original error
    }
}

// Ensure database schema is present if successfully connected
if ($pdo !== null) {
    try {
        $tables = $pdo->query("SHOW TABLES;")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('users', $tables)) {
            $schemaFile = __DIR__ . '/clean_schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);
            }
        }
    } catch (Exception $e) {
        // Silently continue if tables already exist or permission error
    }
}

require_once __DIR__ . '/helpers.php';
