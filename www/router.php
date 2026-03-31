<?php
/**
 * FPP WLED API Proxy — PHP Built-in Server Router
 *
 * Routes all API requests to wled_api.php
 * Used by: php -S 0.0.0.0:9000 router.php
 *
 * Note: plugin_setup.php is served by FPP's web server, not this router.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Let the built-in server serve actual files (CSS, JS, images, etc.)
$requested = __DIR__ . $path;
if ($requested !== __DIR__ . '/router.php' && is_file($requested)) {
    return false; // Let built-in server serve the file
}

// Route everything else to wled_api.php for API calls
require __DIR__ . '/wled_api.php';
