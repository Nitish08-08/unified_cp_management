<?php
// test_db.php - Complete diagnostic & test runner for TiDB Cloud
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json');

$results = [
    'php_version' => PHP_VERSION,
    'attempts'    => [],
    'connected'   => false,
    'strategy'    => null,
    'database'    => null,
    'tables'      => [],
    'test_user'   => null,
    'status'      => 'pending',
    'message'     => '',
];

$host = 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com';
$port = 4000;
$db   = 'competitive_cp';
$user = 'jB2n4xjUGg37Ctb.root';
$pass = '1c5Egb0MK4f8iCxA';

// Check available CA certificate files
$possibleCas = [
    'bundled_ca'    => __DIR__ . '/certs/ca.pem',
    'aws_lambda_ca' => '/etc/pki/tls/certs/ca-bundle.crt',
    'debian_ca'     => '/etc/ssl/certs/ca-certificates.crt',
    'alpine_ca'     => '/etc/ssl/cert.pem',
];

$caStatus = [];
foreach ($possibleCas as $label => $path) {
    $caStatus[$label] = [
        'path' => $path,
        'exists' => file_exists($path),
        'readable' => is_readable($path),
        'size' => file_exists($path) ? filesize($path) : 0,
    ];
}
$results['ca_files'] = $caStatus;

// Build list of connection attempts
$connectionConfigs = [];

// 1. Bundled CA with verify_server_cert = false
if (file_exists(__DIR__ . '/certs/ca.pem')) {
    $connectionConfigs['bundled_ca_noverify'] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => __DIR__ . '/certs/ca.pem',
        1014                         => false,
    ];
    $connectionConfigs['bundled_ca_verify'] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => __DIR__ . '/certs/ca.pem',
        1014                         => true,
    ];
}

// 2. AWS Lambda system CA
if (file_exists('/etc/pki/tls/certs/ca-bundle.crt')) {
    $connectionConfigs['aws_ca_noverify'] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => '/etc/pki/tls/certs/ca-bundle.crt',
        1014                         => false,
    ];
}

// 3. Linux Debian / Ubuntu CA
if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
    $connectionConfigs['linux_ca_noverify'] = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        1007                         => '/etc/ssl/certs/ca-certificates.crt',
        1014                         => false,
    ];
}

// 4. Fallback SSL attempt with just verify = false
$connectionConfigs['ssl_capath_fallback'] = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    1008                         => '/etc/ssl/certs',
    1014                         => false,
];

// 5. Default without SSL
$connectionConfigs['plain_no_ssl'] = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

$pdo = null;
$workingStrategy = null;

// First try connecting directly to competitive_cp, or fallback to connecting without dbname
foreach ($connectionConfigs as $stratName => $options) {
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
        $testPdo = new PDO($dsn, $user, $pass, $options);
        $pdo = $testPdo;
        $workingStrategy = $stratName;
        $results['attempts'][$stratName] = 'SUCCESS (direct db)';
        break;
    } catch (PDOException $e) {
        $errMsg = $e->getMessage();
        $results['attempts'][$stratName] = $errMsg;

        // If unknown database, try connecting to cluster root without dbname
        if (strpos($errMsg, '1049') !== false || stripos($errMsg, 'Unknown database') !== false) {
            try {
                $rootDsn = "mysql:host=$host;port=$port;charset=utf8mb4";
                $testPdo = new PDO($rootDsn, $user, $pass, $options);
                $testPdo->exec("CREATE DATABASE IF NOT EXISTS `$db`;");
                $testPdo->exec("USE `$db`;");
                $pdo = $testPdo;
                $workingStrategy = $stratName . '_created_db';
                $results['attempts'][$stratName] = 'SUCCESS (created database)';
                break;
            } catch (PDOException $e2) {
                $results['attempts'][$stratName . '_root'] = $e2->getMessage();
            }
        }
    }
}

if ($pdo === null) {
    $results['status'] = 'error';
    $results['message'] = 'All connection strategies failed';
    echo json_encode($results, JSON_PRETTY_PRINT);
    exit;
}

$results['connected'] = true;
$results['strategy']  = $workingStrategy;
$results['database']  = $db;

try {
    // Check tables
    $tables = $pdo->query("SHOW TABLES;")->fetchAll(PDO::FETCH_COLUMN);
    $results['tables'] = $tables;

    // If tables do not exist, auto-initialize schema
    if (!in_array('users', $tables)) {
        $schemaFile = __DIR__ . '/clean_schema.sql';
        if (file_exists($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            $pdo->exec($sql);
            $tables = $pdo->query("SHOW TABLES;")->fetchAll(PDO::FETCH_COLUMN);
            $results['tables'] = $tables;
            $results['schema_auto_initialized'] = true;
        }
    }

    // Insert test row into users
    $testEmail = 'tidb_verify_' . time() . '@test.com';
    $testName  = 'TiDB Test User ' . date('Y-m-d H:i:s');
    $passHash  = password_hash('TestPass123!', PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, join_date) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$testName, $testEmail, $passHash]);
    $newId = (int)$pdo->lastInsertId();

    // Fetch the inserted row back
    $fetchStmt = $pdo->prepare("SELECT id, name, email, join_date FROM users WHERE id = ?");
    $fetchStmt->execute([$newId]);
    $fetched = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    $results['test_user'] = $fetched;
    $results['status'] = 'success';
    $results['message'] = 'Successfully connected to TiDB Cloud, created tables if needed, inserted a record, and fetched it back!';

} catch (Exception $e) {
    $results['status'] = 'error';
    $results['message'] = 'Operations failed after connect: ' . $e->getMessage();
}

echo json_encode($results, JSON_PRETTY_PRINT);
