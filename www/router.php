<?php
/**
 * FPP WLED API Proxy — PHP Built-in Server Router
 *
 * Routes API requests to wled_api.php
 * Serves plugin_setup.php directly for settings page
 * Used by: php -S 0.0.0.0:9000 router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve plugin_setup.php directly (it's in the parent directory)
if ($path === '/plugin_setup.php') {
    require __DIR__ . '/../plugin_setup.php';
    return;
}

// Let the built-in server serve actual files (CSS, JS, images, etc.)
$requested = __DIR__ . $path;
if ($requested !== __DIR__ . '/router.php' && is_file($requested)) {
    return false; // Let built-in server serve the file
}

// Route everything else to wled_api.php for API calls
require __DIR__ . '/wled_api.php';
