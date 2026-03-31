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
FPP_WEB_ROOT="/opt/fpp/www"

echo "[${PLUGIN_NAME}] Starting installation..."

# ── 1. Install system dependencies ───────────────────────────────────────────
echo "[${PLUGIN_NAME}] Installing dependencies..."
apt-get install -qq -y \
    libjsoncpp-dev \
    libcurl4-openssl-dev \
    avahi-utils \
    php-cli \
    2>/dev/null || true   # non-fatal — may already be present

# ── 2. Compile the C++ plugin ─────────────────────────────────────────────────
echo "[${PLUGIN_NAME}] Compiling WLEDProxyPlugin.so..."
cd "${PLUGIN_DIR}"
make clean
make -j"$(nproc)"
echo "[${PLUGIN_NAME}] Compilation succeeded."

# ── 3. Clean up old Apache/lighttpd configs from prior versions ──────────────
# Remove old Apache rewrite rules if present
APACHE_SITE_CONF="/etc/apache2/sites-available/000-default.conf"
if [ -f "${APACHE_SITE_CONF}" ]; then
    if grep -q "WLED API Proxy" "${APACHE_SITE_CONF}" 2>/dev/null; then
        sed -i '/# WLED API Proxy/,/<\/IfModule>/d' "${APACHE_SITE_CONF}" 2>/dev/null || true
        if systemctl is-active --quiet apache2 2>/dev/null; then
            systemctl reload apache2 2>/dev/null || true
        fi
        echo "[${PLUGIN_NAME}] Cleaned up old Apache WLED rewrite rules."
    fi
fi

# Remove old lighttpd config if present
LIGHTTPD_CONF="/etc/lighttpd/conf-enabled/88-wled-proxy.conf"
if [ -f "${LIGHTTPD_CONF}" ]; then
    rm -f "${LIGHTTPD_CONF}"
    if systemctl is-active --quiet lighttpd 2>/dev/null; then
        systemctl reload lighttpd 2>/dev/null || true
    fi
    echo "[${PLUGIN_NAME}] Cleaned up old lighttpd WLED config."
fi

# ── 4. Symlink the www/ directory into FPP's web root ─────────────────────────
# FPP serves plugin PHP files from /opt/fpp/www/plugin/{name}/
WEB_LINK="${FPP_WEB_ROOT}/plugin/${PLUGIN_NAME}"
WEB_PARENT=$(dirname "${WEB_LINK}")
mkdir -p "${WEB_PARENT}"
if [ ! -L "${WEB_LINK}" ]; then
    ln -s "${PLUGIN_DIR}/www" "${WEB_LINK}"
    echo "[${PLUGIN_NAME}] Created web symlink: ${WEB_LINK}"
fi

# ── 5. Install systemd service ───────────────────────────────────────────────
# The HTTP server runs on port 9000 via systemd service.
SERVICE_DEST="/etc/systemd/system/fpp-wled-proxy.service"
SERVICE_SRC="${PLUGIN_DIR}/systemd/fpp-wled-proxy.service"

if [ -f "${SERVICE_SRC}" ]; then
    cp "${SERVICE_SRC}" "${SERVICE_DEST}"
    chmod 644 "${SERVICE_DEST}"
    systemctl daemon-reload
    systemctl enable fpp-wled-proxy 2>/dev/null || true
    echo "[${PLUGIN_NAME}] Installed systemd service: fpp-wled-proxy"
else
    echo "[${PLUGIN_NAME}] WARNING: systemd service file not found at ${SERVICE_SRC}"
fi

# ── 6. Create state and config directories ────────────────────────────────────
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

# ── 7. Set permissions ─────────────────────────────────────────────────────────
chown -R fpp:fpp "${PLUGIN_DIR}" 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/fpp_start.sh" 2>/dev/null || true
chmod +x "${PLUGIN_DIR}/fpp_stop.sh"  2>/dev/null || true
chmod +x "${PLUGIN_DIR}/www/server.php" 2>/dev/null || true

echo "[${PLUGIN_NAME}] Installation complete."
echo "[${PLUGIN_NAME}] Next steps:"
echo "   1. Go to FPP → Content Setup → Pixel Overlay Models and create a model."
echo "   2. Visit FPP → Plugin Settings → WLED API Proxy to configure the model name."
echo "   3. The HTTP server will start automatically on port 9000."
echo "   4. Check status: systemctl status fpp-wled-proxy"
echo "   5. View logs: journalctl -u fpp-wled-proxy -f"
