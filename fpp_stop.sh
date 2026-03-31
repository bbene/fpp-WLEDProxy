#!/bin/bash
# ── FPP WLED API Proxy — Plugin Stop Script ───────────────────────────────────
#
# Called by FPP when the plugin is disabled or FPP stops.
# Stops the HTTP server systemd service.
# ─────────────────────────────────────────────────────────────────────────────

PLUGIN_NAME="fpp-WLEDProxy"

echo "[${PLUGIN_NAME}] Stopping..."

# ── Stop the HTTP server via systemd ───────────────────────────────────────────
if systemctl is-active --quiet fpp-wled-proxy 2>/dev/null; then
    systemctl stop fpp-wled-proxy 2>/dev/null || true
    echo "[${PLUGIN_NAME}] HTTP server stopped."
fi

echo "[${PLUGIN_NAME}] Stopped."
