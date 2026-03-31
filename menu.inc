<?php
/**
 * FPP Plugin Menu Configuration
 *
 * Registers the WLED API Proxy plugin settings in FPP's UI.
 * FPP loads this automatically to make the plugin visible in menus.
 */

$pluginName = "fpp-WLEDProxy";
$pluginURL = "/plugin/{$pluginName}/plugin_setup.php";

// Register in the "Plugins" content setup section (most FPP versions)
$contentSetupItems[] = [
    "name" => "WLED API Proxy",
    "link" => $pluginURL,
    "description" => "Configure FPP as a WLED-compatible device for use with WLED apps"
];
?>
