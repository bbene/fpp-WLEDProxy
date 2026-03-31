<?php
/**
 * FPP WLED API Proxy — PHP Built-in Server Router
 *
 * Routes all requests to wled_api.php
 * Used by: php -S 0.0.0.0:9000 router.php
 */

// Don't process actual files/directories, let the server handle them
if (is_file($_SERVER["SCRIPT_FILENAME"])) {
    return false;
}

// Route everything to wled_api.php
require_once __DIR__ . '/wled_api.php';
