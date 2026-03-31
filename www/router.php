<?php
/**
 * FPP WLED API Proxy — PHP Built-in Server Router
 *
 * Routes all requests to wled_api.php
 * Used by: php -S 0.0.0.0:9000 router.php
 */

// Let the built-in server serve actual files (CSS, JS, images, etc.)
$requested = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requested = __DIR__ . $requested;

if ($requested !== __DIR__ . '/router.php' && is_file($requested)) {
    return false; // Let built-in server serve the file
}

// Route everything else to wled_api.php
require __DIR__ . '/wled_api.php';
