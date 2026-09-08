<?php
declare(strict_types=1);

/**
 * Router for the PHP built-in dev server (php -S ... public/dev-router.php).
 * Production uses nginx (see README.md) — this file is dev-only.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}

if ($path === '/render.php') {
    require __DIR__ . '/render.php';
    return true;
}

// Serve existing static assets directly.
$file = __DIR__ . $path;
if (is_file($file)) {
    return false;
}

// Everything else is a web page.
require __DIR__ . '/index.php';
return true;
