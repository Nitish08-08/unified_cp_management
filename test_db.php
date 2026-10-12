<?php
// test_db.php - Diagnostic script to test TiDB Cloud connectivity, insert, and fetch
require_once 'db.php';

header('Content-Type: application/json');

if ($pdo === null) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database connection failed',
        'details' => $dbConnectionError ?? 'Unknown error',
        'host'    => $host ?? null,
        'port'    => $port ?? null,
        'db'      => $db ?? null,
        'user'    => $user ?? null,
    ], JSON_PRETTY_PRINT);
    exit;
}

try {
    // 1. Check existing tables in competitive_cp
    $tables = $pdo->query("SHOW TABLES;")->fetchAll(PDO::FETCH_COLUMN);

    // 2. Insert test record into users
    $testEmail = 'test_' . time() . '@example.com';
    $insertStmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, join_date) VALUES (?, ?, ?, NOW())");
    $insertStmt->execute(['TiDB Verified User', $testEmail, password_hash('test123', PASSWORD_DEFAULT)]);
    $testUserId = (int)$pdo->lastInsertId();

    // 3. Fetch the inserted record back
    $fetchStmt = $pdo->prepare("SELECT id, name, email, join_date FROM users WHERE id = ?");
    $fetchStmt->execute([$testUserId]);
    $fetchedUser = $fetchStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'             => 'success',
        'message'            => 'Successfully connected to TiDB Cloud, inserted a row, and fetched it back!',
        'database'           => $db,
        'tables_found'       => $tables,
        'inserted_and_fetched' => $fetchedUser,
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
