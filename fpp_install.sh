#!/bin/bash
# ── FPP WLED API Proxy — Plugin Install Script ────────────────────────────────
#
# FPP runs this script after cloning/updating the plugin repository.
# It compiles the C++ shared library and configures the web server.
#
# Called from: /home/fpp/media/plugins/fpp-WLEDProxy/
# ─────────────────────────────────────────────────────────────────────────────

set -e

PLUGIN_NAME="fpp-WLEDProxy"
PLUGIN_DIR="/home/fpp/media/plugins/${PLUGIN_NAME}"
FPP_WEB_ROOT="/opt/fpp/www"          # adjust if your FPP uses a different path
LIGHTTPD_CONF_DIR="/etc/lighttpd/conf-enabled"

echo "[${PLUGIN_NAME}] Starting installation..."

# ── 1. Install system dependencies ───────────────────────────────────────────
echo "[${PLUGIN_NAME}] Installing dependencies..."
apt-get install -qq -y \
    libjsoncpp-dev \
    libcurl4-openssl-dev \
    avahi-utils \
    2>/dev/null || true   # non-fatal — may already be present

# ── 2. Compile the C++ plugin ─────────────────────────────────────────────────
echo "[${PLUGIN_NAME}] Compiling WLEDProxyPlugin.so..."
cd "${PLUGIN_DIR}"
make clean
make -j"$(nproc)"
echo "[${PLUGIN_NAME}] Compilation succeeded."

# ── 3. Symlink the www/ directory into FPP's web root ─────────────────────────
# FPP serves plugin PHP files from /opt/fpp/www/plugin/{name}/
WEB_LINK="${FPP_WEB_ROOT}/plugin/${PLUGIN_NAME}"
WEB_PARENT=$(dirname "${WEB_LINK}")
mkdir -p "${WEB_PARENT}"
if [ ! -L "${WEB_LINK}" ]; then
    ln -s "${PLUGIN_DIR}/www" "${WEB_LINK}"
    echo "[${PLUGIN_NAME}] Created web symlink: ${WEB_LINK}"
fi

# ── 4. Install lighttpd URL rewriting config ───────────────────────────────────
# Routes /json/* and /win to our PHP handler so WLED apps find the API
# at the expected paths.
CONF_SRC="${PLUGIN_DIR}/conf/88-wled-proxy.conf"
CONF_DEST="${LIGHTTPD_CONF_DIR}/88-wled-proxy.conf"

mkdir -p "${LIGHTTPD_CONF_DIR}"
if [ -f "${CONF_SRC}" ]; then
    cp "${CONF_SRC}" "${CONF_DEST}"
    echo "[${PLUGIN_NAME}] Installed lighttpd config: ${CONF_DEST}"
    # Validate and reload lighttpd (try multiple paths)
    LIGHTTPD_BIN=$(which lighttpd 2>/dev/null || echo /usr/sbin/lighttpd)
    if [ -x "${LIGHTTPD_BIN}" ]; then
        if "${LIGHTTPD_BIN}" -t -f /etc/lighttpd/lighttpd.conf 2>&1 | grep -q "syntax ok"; then
            systemctl reload lighttpd 2>/dev/null || service lighttpd reload 2>/dev/null || true
            echo "[${PLUGIN_NAME}] lighttpd config validated and service reloaded."
        else
            echo "[${PLUGIN_NAME}] WARNING: lighttpd config test failed:"
            "${LIGHTTPD_BIN}" -t -f /etc/lighttpd/lighttpd.conf 2>&1 || true
        fi
    else
        echo "[${PLUGIN_NAME}] NOTE: lighttpd binary not found; skipping validation."
        echo "[${PLUGIN_NAME}] Please verify config manually: ${CONF_DEST}"
    fi
else
    echo "[${PLUGIN_NAME}] WARNING: lighttpd config not found at ${CONF_SRC}"
fi

# ── 5. Create state and config directories ────────────────────────────────────
mkdir -p /home/fpp/media/config

# Write a default plugin config if none exists
CONFIG_FILE="/home/fpp/media/config/plugin.fpp-WLEDProxy.json"
if [ ! -f "${CONFIG_FILE}" ]; then
    cat > "${CONFIG_FILE}" <<'EOF'
{
    "OverlayModelName": "All Pixels",
    "LEDCount": 300,
    "DeviceName": "FPP WLED",
    "EnableUDPDiscovery": true
}
EOF
    echo "[${PLUGIN_NAME}] Created default config: ${CONFIG_FILE}"
fi

# Write a default state file if none exists
STATE_FILE="/home/fpp/media/config/wled_proxy_state.json"
if [ ! -f "${STATE_FILE}" ]; then
    cat > "${STATE_FILE}" <<'EOF'
{
    "on": true,
    "bri": 255,
    "transition": 7,
    "ps": -1,
    "mainseg": 0,
    "seg": [{
        "id": 0,
        "start": 0,
        "stop": 300,
        "len": 300,
        "fx": 0,
        "sx": 128,
        "ix": 128,
        "pal": 0,
        "sel": true,
        "rev": false,
        "on": true,
        "bri": 255,
        "col": [[255,0,0],[0,0,0],[0,0,0]]
    }]
}
EOF
    echo "[${PLUGIN_NAME}] Created default state file: ${STATE_FILE}"
fi

# ── 6. Set permissions ─────────────────────────────────────────────────────────
chown -R fpp:fpp "${PLUGIN_DIR}" 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/fpp_start.sh" 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/fpp_stop.sh"  2>/dev/null || true

# ── 7. Restart lighttpd to ensure all configs are loaded ───────────────────────
systemctl restart lighttpd 2>/dev/null || service lighttpd restart 2>/dev/null || true

echo "[${PLUGIN_NAME}] Installation complete."
echo "[${PLUGIN_NAME}] Next steps:"
echo "   1. Go to FPP → Content Setup → Pixel Overlay Models and create a model."
echo "   2. Visit FPP → Plugin Settings → WLED API Proxy to configure the model name."
echo "   3. Verify plugin is enabled and restart FPP if needed."
