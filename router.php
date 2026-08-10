<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in server:
 *   php -S 127.0.0.1:8765 router.php
 *
 * Serves /public as the site root and keeps /api available.
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = __DIR__;

if (str_starts_with($uri, '/api/')) {
    $file = $root . $uri;
    if (is_file($file)) {
        require $file;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Not found']);
    return true;
}

$publicFile = $root . '/public' . ($uri === '/' ? '/index.html' : $uri);
if (is_file($publicFile)) {
    $ext = strtolower(pathinfo($publicFile, PATHINFO_EXTENSION));
    $types = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    if (isset($types[$ext])) {
        header('Content-Type: ' . $types[$ext]);
    }
    readfile($publicFile);
    return true;
}

http_response_code(404);
echo 'Not found';
return true;
