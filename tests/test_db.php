<?php
// test_db.php - Verification script: auto-initialize tables, insert, and fetch from TiDB Cloud
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json');

$host = 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com';
$port = 4000;
$db   = 'competitive_cp';
$user = 'jB2n4xjUGg37Ctb.root';
$pass = '1c5Egb0MK4f8iCxA';
$caFile = dirname(__DIR__) . '/certs/ca.pem';
if (!file_exists($caFile)) {
    $caFile = __DIR__ . '/certs/ca.pem';
}

$attrCa = defined('Pdo\Mysql::ATTR_SSL_CA') ? constant('Pdo\Mysql::ATTR_SSL_CA') : (defined('PDO::MYSQL_ATTR_SSL_CA') ? PDO::MYSQL_ATTR_SSL_CA : 1008);
$attrVerify = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT : 1013);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    $attrCa                      => $caFile,
    $attrVerify                  => false,
];

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, $options);

    // 1. Check existing tables
    $tables = $pdo->query("SHOW TABLES;")->fetchAll(PDO::FETCH_COLUMN);

    // 2. Initialize schema if tables don't exist
    $schemaInit = false;
    if (!in_array('users', $tables)) {
        $schemaFile = dirname(__DIR__) . '/database/clean_schema.sql';
        if (!file_exists($schemaFile)) {
            $schemaFile = __DIR__ . '/clean_schema.sql';
        }
        if (file_exists($schemaFile)) {
            $sqlContent = file_get_contents($schemaFile);
            // Split and execute SQL statements
            $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
            foreach ($statements as $stmt) {
                if ($stmt !== '' && stripos($stmt, 'CREATE DATABASE') === false && stripos($stmt, 'USE ') === false) {
                    $pdo->exec($stmt);
                }
            }
            $tables = $pdo->query("SHOW TABLES;")->fetchAll(PDO::FETCH_COLUMN);
            $schemaInit = true;
        }
    }

    // 3. Insert a test record into TiDB
    $testTimestamp = time();
    $testName = "TiDB Verification User ($testTimestamp)";
    $testEmail = "tidb_user_{$testTimestamp}@example.com";
    $testPassword = password_hash("securePassword123!", PASSWORD_DEFAULT);

    $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, join_date) VALUES (?, ?, ?, CURDATE())");
    $insertStmt->execute([$testName, $testEmail, $testPassword]);
    $insertedId = (int)$pdo->lastInsertId();

    // 4. Fetch the inserted record from TiDB
    $fetchStmt = $pdo->prepare("SELECT id, name, email, join_date FROM users WHERE id = ?");
    $fetchStmt->execute([$insertedId]);
    $fetchedRow = $fetchStmt->fetch();

    // Also fetch platform list
    $platforms = $pdo->query("SELECT id, platform_name, base_url FROM platforms ORDER BY id ASC")->fetchAll();

    echo json_encode([
        'status'                 => 'success',
        'message'                => 'TiDB Cloud connection, insert, and fetch verified successfully!',
        'database'               => $db,
        'host'                   => $host,
        'schema_auto_created'    => $schemaInit,
        'tables_in_database'     => $tables,
        'platforms_seeded'       => $platforms,
        'inserted_record_id'     => $insertedId,
        'fetched_record'         => $fetchedRow,
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
