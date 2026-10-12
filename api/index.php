<?php
// api/index.php - Serverless Entry Point & Front Controller for Vercel

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = ltrim($uri, '/');

if ($file === '' || $file === '/') {
    $file = 'index.php';
}

$rootDir = realpath(__DIR__ . '/..');

// Clean URL handling (e.g. /dashboard -> /dashboard.php)
if (!pathinfo($file, PATHINFO_EXTENSION)) {
    if (file_exists($rootDir . '/' . $file . '.php')) {
        $file = $file . '.php';
    } elseif (file_exists($rootDir . '/' . $file . '.html')) {
        $file = $file . '.html';
    }
}

$target = realpath($rootDir . '/' . $file);

// Guard: verify path is inside root directory
if ($target && str_starts_with($target, $rootDir) && file_exists($target)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

    $mimes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'html' => 'text/html; charset=UTF-8',
        'txt'  => 'text/plain',
        'sql'  => 'text/plain',
    ];

    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
        readfile($target);
        exit;
    }

    if ($ext === 'php') {
        chdir(dirname($target));
        require $target;
        exit;
    }
}

// Fallback to index.php
chdir($rootDir);
require $rootDir . '/index.php';
