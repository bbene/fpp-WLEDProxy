#!/bin/bash
# ── FPP WLED API Proxy — Plugin Uninstall Script ──────────────────────────────
#
# FPP runs this script before removing the plugin repository.
# It cleans up the systemd service, web symlink, and old configs.
#
# Called from: FPP plugin manager
# ─────────────────────────────────────────────────────────────────────────────

PLUGIN_NAME="fpp-WLEDProxy"
FPP_WEB_ROOT="/opt/fpp/www"
WEB_LINK="${FPP_WEB_ROOT}/plugin/${PLUGIN_NAME}"

echo "[${PLUGIN_NAME}] Starting uninstallation..."

# ── 1. Stop and disable systemd service ────────────────────────────────────────
SERVICE_FILE="/etc/systemd/system/fpp-wled-proxy.service"
if [ -f "${SERVICE_FILE}" ]; then
    if systemctl is-active --quiet fpp-wled-proxy 2>/dev/null; then
        systemctl stop fpp-wled-proxy 2>/dev/null || true
    fi
    systemctl disable fpp-wled-proxy 2>/dev/null || true
    rm -f "${SERVICE_FILE}"
    systemctl daemon-reload 2>/dev/null || true
    echo "[${PLUGIN_NAME}] Removed systemd service."
fi

# ── 2. Remove web symlink ──────────────────────────────────────────────────────
if [ -L "${WEB_LINK}" ]; then
    rm -f "${WEB_LINK}"
    echo "[${PLUGIN_NAME}] Removed web symlink: ${WEB_LINK}"
fi

# ── 3. Clean up old Apache configs (from prior versions) ───────────────────────
APACHE_SITE_CONF="/etc/apache2/sites-available/000-default.conf"
if [ -f "${APACHE_SITE_CONF}" ]; then
    if grep -q "WLED API Proxy" "${APACHE_SITE_CONF}" 2>/dev/null; then
        # Remove the WLED API Proxy section
        sed -i '/# WLED API Proxy/,/<\/IfModule>/d' "${APACHE_SITE_CONF}" 2>/dev/null || true

        # Reload Apache if active
        if systemctl is-active --quiet apache2 2>/dev/null; then
            systemctl reload apache2 2>/dev/null || true
        fi

        echo "[${PLUGIN_NAME}] Cleaned up old Apache WLED rewrite rules."
    fi
fi

# ── 4. Clean up old lighttpd configs (from prior versions) ────────────────────
LIGHTTPD_CONF="/etc/lighttpd/conf-enabled/88-wled-proxy.conf"
if [ -f "${LIGHTTPD_CONF}" ]; then
    rm -f "${LIGHTTPD_CONF}"

    # Reload lighttpd if active
    if systemctl is-active --quiet lighttpd 2>/dev/null; then
        systemctl reload lighttpd 2>/dev/null || true
    fi

    echo "[${PLUGIN_NAME}] Cleaned up old lighttpd WLED config."
fi

# ── 5. Backup config and state files ────────────────────────────────────────────
# We don't delete these automatically in case they contain user config.
BACKUP_DIR="/home/fpp/media/config/backups"
CONFIG_FILE="/home/fpp/media/config/plugin.fpp-WLEDProxy.json"
STATE_FILE="/home/fpp/media/config/wled_proxy_state.json"

if [ -f "${CONFIG_FILE}" ] || [ -f "${STATE_FILE}" ]; then
    mkdir -p "${BACKUP_DIR}"
    if [ -f "${CONFIG_FILE}" ]; then
        cp "${CONFIG_FILE}" "${BACKUP_DIR}/plugin.fpp-WLEDProxy.json.backup"
        echo "[${PLUGIN_NAME}] Backed up config to: ${BACKUP_DIR}/plugin.fpp-WLEDProxy.json.backup"
    fi
    if [ -f "${STATE_FILE}" ]; then
        cp "${STATE_FILE}" "${BACKUP_DIR}/wled_proxy_state.json.backup"
        echo "[${PLUGIN_NAME}] Backed up state to: ${BACKUP_DIR}/wled_proxy_state.json.backup"
    fi
fi

echo "[${PLUGIN_NAME}] Uninstallation complete."
echo "[${PLUGIN_NAME}] Config files have been backed up to ${BACKUP_DIR}"
echo "[${PLUGIN_NAME}] If you want to fully remove the plugin, manually delete:"
echo "[${PLUGIN_NAME}]   - ${CONFIG_FILE}"
echo "[${PLUGIN_NAME}]   - ${STATE_FILE}"
