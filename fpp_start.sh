#!/bin/bash
# ── FPP WLED API Proxy — Plugin Start Script ──────────────────────────────────
#
# Called by FPP when the plugin is enabled or FPP starts.
# Ensures lighttpd is serving the WLED routes correctly.
# The C++ .so (loaded by fppd) handles UDP discovery and mDNS internally.
# ─────────────────────────────────────────────────────────────────────────────

PLUGIN_NAME="fpp-WLEDProxy"
LIGHTTPD_CONF_DIR="/etc/lighttpd/conf-enabled"
CONF_DEST="${LIGHTTPD_CONF_DIR}/88-wled-proxy.conf"
PLUGIN_DIR="/home/fpp/media/plugins/${PLUGIN_NAME}"
CONF_SRC="${PLUGIN_DIR}/conf/88-wled-proxy.conf"

echo "[${PLUGIN_NAME}] Starting..."

# ── Ensure lighttpd URL rewriting config is in place ─────────────────────────
if [ ! -f "${CONF_DEST}" ] && [ -f "${CONF_SRC}" ]; then
    cp "${CONF_SRC}" "${CONF_DEST}"
    if lighttpd -t -f /etc/lighttpd/lighttpd.conf 2>/dev/null; then
        service lighttpd reload 2>/dev/null || true
        echo "[${PLUGIN_NAME}] lighttpd reloaded with WLED routes."
    fi
fi

echo "[${PLUGIN_NAME}] Started. WLED API available at /json/* and /win"
