<?php
/**
 * Unified CP Management System
 * Root router / delegator for local development and CLI servers.
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = ltrim($uri, '/');

// In PHP built-in server (php -S), return false to serve public static files directly
if (php_sapi_name() === 'cli-server' && $file !== '' && file_exists(__DIR__ . '/public/' . $file)) {
    return false;
}

require_once __DIR__ . '/public/index.php';
