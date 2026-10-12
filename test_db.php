<?php
// test_db.php - Comprehensive diagnostic for TiDB SSL & mysqli
ob_start();
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json');

$host = 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com';
$port = 4000;
$db   = 'competitive_cp';
$user = 'jB2n4xjUGg37Ctb.root';
$pass = '1c5Egb0MK4f8iCxA';
$caFile = __DIR__ . '/certs/ca.pem';

$info = [
    'php_version' => PHP_VERSION,
    'constants' => [
        'PDO_MYSQL_ATTR_SSL_CA' => defined('PDO::MYSQL_ATTR_SSL_CA') ? PDO::MYSQL_ATTR_SSL_CA : null,
        'PDO_MYSQL_ATTR_SSL_VERIFY_SERVER_CERT' => defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT : null,
        'Pdo_Mysql_ATTR_SSL_CA' => defined('Pdo\Mysql::ATTR_SSL_CA') ? constant('Pdo\Mysql::ATTR_SSL_CA') : null,
        'Pdo_Mysql_ATTR_SSL_VERIFY_SERVER_CERT' => defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : null,
    ],
    'tests' => [],
];

// Test 1: MySQLi with mysqli_ssl_set
if (extension_loaded('mysqli')) {
    $m = mysqli_init();
    mysqli_ssl_set($m, null, null, $caFile, null, null);
    mysqli_options($m, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $connected = @mysqli_real_connect($m, $host, $user, $pass, $db, $port, null, MYSQLI_CLIENT_SSL);
    if ($connected) {
        $info['tests']['mysqli_with_ca_noverify'] = 'SUCCESS';
        // Test query
        $res = mysqli_query($m, "SHOW TABLES;");
        $tables = [];
        while ($row = mysqli_fetch_row($res)) {
            $tables[] = $row[0];
        }
        $info['mysqli_tables'] = $tables;
        mysqli_close($m);
    } else {
        $info['tests']['mysqli_with_ca_noverify'] = mysqli_connect_error() . ' (code ' . mysqli_connect_errno() . ')';
    }

    // Test 1b: MySQLi with NO CA, just MYSQLI_CLIENT_SSL
    $m2 = mysqli_init();
    mysqli_options($m2, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
    $connected2 = @mysqli_real_connect($m2, $host, $user, $pass, $db, $port, null, MYSQLI_CLIENT_SSL);
    if ($connected2) {
        $info['tests']['mysqli_noca_ssl'] = 'SUCCESS';
        mysqli_close($m2);
    } else {
        $info['tests']['mysqli_noca_ssl'] = mysqli_connect_error() . ' (code ' . mysqli_connect_errno() . ')';
    }
} else {
    $info['tests']['mysqli'] = 'extension not loaded';
}

// Test 2: PDO with different option combinations
$attrCa = defined('Pdo\Mysql::ATTR_SSL_CA') ? constant('Pdo\Mysql::ATTR_SSL_CA') : (defined('PDO::MYSQL_ATTR_SSL_CA') ? PDO::MYSQL_ATTR_SSL_CA : 1007);
$attrVerify = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') ? constant('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT') : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT : 1014);

$pdoConfigs = [
    'pdo_ca_and_verify_false' => [
        $attrCa => $caFile,
        $attrVerify => false,
    ],
    'pdo_ca_and_verify_false_int' => [
        1007 => $caFile,
        1014 => false,
    ],
    'pdo_verify_false_only' => [
        $attrVerify => false,
    ],
    'pdo_ca_only' => [
        $attrCa => $caFile,
    ],
    'pdo_no_opts' => [],
];

foreach ($pdoConfigs as $name => $opts) {
    $fullOpts = $opts + [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    try {
        $p = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $fullOpts);
        $info['tests'][$name] = 'SUCCESS';
    } catch (PDOException $e) {
        $info['tests'][$name] = $e->getMessage();
    }
}

// Test 3: Raw socket SSL handshake to check TLS negotiation
$streamContext = stream_context_create([
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
    ]
]);
$fp = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $streamContext);
if ($fp) {
    // Read initial handshake packet from MySQL server
    $packet = fread($fp, 1024);
    $info['raw_tcp_greeting_bytes'] = strlen($packet);
    fclose($fp);
} else {
    $info['raw_tcp_error'] = "$errstr ($errno)";
}

echo json_encode($info, JSON_PRETTY_PRINT);
