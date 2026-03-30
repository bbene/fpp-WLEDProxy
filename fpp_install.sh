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

# ── 4. Install Apache URL rewriting config ───────────────────────────────────
# Routes /json/* and /win to our PHP handler so WLED apps find the API.
# FPP v9 uses Apache with AllowOverride disabled globally, so .htaccess doesn't work.
# Instead, we add rewrite rules directly to the VirtualHost config.

# Ensure Apache mod_rewrite is enabled
if command -v a2enmod &>/dev/null; then
    a2enmod rewrite 2>/dev/null || true
    echo "[${PLUGIN_NAME}] Ensured Apache mod_rewrite is enabled."
fi

# Update Apache VirtualHost config to add rewrite rules
APACHE_SITE_CONF="/etc/apache2/sites-available/000-default.conf"
if [ -f "${APACHE_SITE_CONF}" ]; then
    # Check if WLED rules already exist
    if grep -q "WLED API Proxy" "${APACHE_SITE_CONF}"; then
        echo "[${PLUGIN_NAME}] WLED rewrite rules already present in Apache config"
    else
        # Add WLED rewrite rules after DocumentRoot directive
        REWRITE_RULES='

    # WLED API Proxy - route /json/* and /win/* to plugin
    <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_URI} ^/(json|win)
        RewriteRule ^(json|win)(.*) /plugin/fpp-WLEDProxy/$1$2 [L,QSA,PT]
    </IfModule>'
        
        # Use sed to insert after DocumentRoot line
        sudo sed -i "/DocumentRoot \/opt\/fpp\/www/a\\${REWRITE_RULES}" "${APACHE_SITE_CONF}" 2>/dev/null || \
        echo "[${PLUGIN_NAME}] WARNING: Could not modify Apache config. Please add rewrite rules manually."
        
        echo "[${PLUGIN_NAME}] Added WLED rewrite rules to Apache VirtualHost config."
    fi
else
    echo "[${PLUGIN_NAME}] WARNING: Apache site config not found at ${APACHE_SITE_CONF}"
fi

# Reload Apache to apply rewrite rules
if systemctl is-active --quiet apache2 2>/dev/null; then
    systemctl reload apache2 2>/dev/null || service apache2 reload 2>/dev/null || true
    echo "[${PLUGIN_NAME}] Apache reloaded."
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
