<?php
/**
 * FPP Content Menu — WLED API Proxy Plugin Settings
 *
 * This file is included by FPP's main menu.inc when displaying
 * the Content Setup → Plugins section.
 */

// Point to our standalone HTTP server on port 9000, not FPP's web server
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$host = explode(':', $host)[0]; // Remove port if present
$settingsUrl = "http://{$host}:9000/plugin_setup.php";
?>
<li><a href="<?php echo $settingsUrl; ?>">WLED API Proxy</a></li>
