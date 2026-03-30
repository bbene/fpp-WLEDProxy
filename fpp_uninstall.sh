#!/bin/bash
# ── FPP WLED API Proxy — Plugin Uninstall Script ──────────────────────────────
#
# FPP runs this script before removing the plugin repository.
# It cleans up the web symlink, lighttpd config, and other setup.
#
# Called from: FPP plugin manager
# ─────────────────────────────────────────────────────────────────────────────

PLUGIN_NAME="fpp-WLEDProxy"
PLUGIN_DIR="/home/fpp/media/plugins/${PLUGIN_NAME}"
FPP_WEB_ROOT="/opt/fpp/www"
WEB_LINK="${FPP_WEB_ROOT}/plugin/${PLUGIN_NAME}"

echo "[${PLUGIN_NAME}] Starting uninstallation..."

# ── 1. Remove web symlink (includes .htaccess) ────────────────────────────────
if [ -L "${WEB_LINK}" ]; then
    rm -f "${WEB_LINK}"
    echo "[${PLUGIN_NAME}] Removed web symlink: ${WEB_LINK}"
fi

# ── 2. Remove root-level Apache rewrite rules (if added by installer) ───────
# Restore backup or remove WLED rewrite rules from .htaccess
if [ -f "${FPP_WEB_ROOT}/.htaccess.backup-before-wledproxy" ]; then
    mv "${FPP_WEB_ROOT}/.htaccess.backup-before-wledproxy" "${FPP_WEB_ROOT}/.htaccess"
    echo "[${PLUGIN_NAME}] Restored previous root .htaccess from backup."
fi
# If no backup exists, sed can safely do nothing if pattern doesn't match
sed -i '/^# WLED API Proxy/,/^$/d' "${FPP_WEB_ROOT}/.htaccess" 2>/dev/null || true

# ── 3. Backup config and state files (optional) ──────────────────────────────
# We don't delete these automatically in case they contain user config.
# User can manually delete them if desired.
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
