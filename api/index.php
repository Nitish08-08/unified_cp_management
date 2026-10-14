<?php
// api/index.php - Serverless Entry Point & Front Controller for Vercel

if (!ob_get_level()) {
    ob_start();
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');

$rootDir = realpath(__DIR__ . '/..');
$publicDir = realpath($rootDir . '/public');

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = ltrim($uri, '/');

// Security Guard: block direct access to protected directories and sensitive files
$blockedPatterns = [
    '^\.env',
    '^\.git',
    '^config(/|$)',
    '^database(/|$)',
    '^services(/|$)',
    '^certs(/|$)',
    '^tests(/|$)',
    '\.sql$',
    '\.pem$',
];
foreach ($blockedPatterns as $pattern) {
    if (preg_match('#' . $pattern . '#i', $file)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "403 Forbidden: Access to protected resource is restricted.";
        exit;
    }
}

// Strip leading 'public/' if caller explicitly supplied it
if (str_starts_with($file, 'public/')) {
    $file = substr($file, 7);
}

if ($file === '' || $file === '/') {
    $file = 'index.php';
}

// Map root asset paths (e.g. /css/style.css -> /assets/css/style.css)
$candidatePaths = [];
if (str_starts_with($file, 'css/') || str_starts_with($file, 'js/')) {
    $candidatePaths[] = $publicDir . '/assets/' . $file;
    $candidatePaths[] = $publicDir . '/' . $file;
}

// Clean URL handling (e.g. /dashboard -> /dashboard.php, /login -> /login.php)
if (!pathinfo($file, PATHINFO_EXTENSION)) {
    $candidatePaths[] = $publicDir . '/' . $file . '.php';
    $candidatePaths[] = $publicDir . '/' . $file . '.html';
}

$candidatePaths[] = $publicDir . '/' . $file;

$target = null;
foreach ($candidatePaths as $candidate) {
    $real = realpath($candidate);
    if ($real && str_starts_with($real, $publicDir) && file_exists($real)) {
        $target = $real;
        break;
    }
}

if ($target && file_exists($target)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));

    $mimes = [
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'application/javascript; charset=UTF-8',
        'json'  => 'application/json; charset=UTF-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'html'  => 'text/html; charset=UTF-8',
        'txt'   => 'text/plain; charset=UTF-8',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'webp'  => 'image/webp',
    ];

    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
        header('Cache-Control: public, max-age=86400');
        readfile($target);
        exit;
    }

    if ($ext === 'php') {
        chdir(dirname($target));
        require $target;
        exit;
    }
}

// Fallback to public/index.php
chdir($publicDir);
require $publicDir . '/index.php';
