#!/bin/bash
# ── FPP WLED API Proxy — Plugin Stop Script ───────────────────────────────────
#
# Called by FPP when the plugin is disabled or FPP stops.
# Removes the lighttpd URL rewriting config so /json/* and /win revert to
# normal FPP behaviour.
# ─────────────────────────────────────────────────────────────────────────────

PLUGIN_NAME="fpp-WLEDProxy"
CONF_DEST="/etc/lighttpd/conf-enabled/88-wled-proxy.conf"

echo "[${PLUGIN_NAME}] Stopping..."

if [ -f "${CONF_DEST}" ]; then
    rm -f "${CONF_DEST}"
    if lighttpd -t -f /etc/lighttpd/lighttpd.conf 2>/dev/null; then
        service lighttpd reload 2>/dev/null || true
    fi
    echo "[${PLUGIN_NAME}] lighttpd WLED routes removed."
fi

echo "[${PLUGIN_NAME}] Stopped."
