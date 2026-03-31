#!/bin/bash
# ── FPP WLED API Proxy — Plugin Start Script ──────────────────────────────────
#
# Called by FPP when the plugin is enabled or FPP starts.
# Starts the HTTP server on port 9000 via systemd service.
# The C++ .so (loaded by fppd) handles UDP discovery and mDNS internally.
# ─────────────────────────────────────────────────────────────────────────────

PLUGIN_NAME="fpp-WLEDProxy"

echo "[${PLUGIN_NAME}] Starting..."

# ── Start the HTTP server via systemd ──────────────────────────────────────────
if systemctl is-enabled fpp-wled-proxy &>/dev/null; then
    systemctl start fpp-wled-proxy 2>/dev/null || true
    sleep 1
    if systemctl is-active --quiet fpp-wled-proxy; then
        echo "[${PLUGIN_NAME}] HTTP server started on port 9000 (systemd service)"
    else
        echo "[${PLUGIN_NAME}] WARNING: Failed to start HTTP server"
    fi
else
    echo "[${PLUGIN_NAME}] WARNING: fpp-wled-proxy service not enabled"
fi

echo "[${PLUGIN_NAME}] Started. WLED API available at http://<fpp-ip>:9000/json/* and http://<fpp-ip>:9000/win"
