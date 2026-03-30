#pragma once

/*
 * FPP WLED API Proxy Plugin
 *
 * Exposes FPP as a WLED-compatible device so that WLED apps (Lightbow,
 * WLED Wiz, Home Assistant WLED integration, etc.) can discover and
 * control FPP's pixel overlay model effects via the WLED JSON API.
 *
 * Implements:
 *   - WLED JSON API  (/json, /json/state, /json/info, /json/effects, /json/palettes)
 *   - WLED HTTP API  (/win)
 *   - UDP discovery  (port 21324, WLED-compatible protocol)
 *   - mDNS advertisement (_wled._tcp via avahi)
 */

#include <string>
#include <thread>
#include <atomic>
#include <mutex>
#include <functional>
#include <map>

// FPP plugin base — adjust include path to match your FPP source tree.
// Typically found at /opt/fpp/src/Plugin.h after FPP is installed.
#include <Plugin.h>

// FPP JSON library (jsoncpp, shipped with FPP)
#include <jsoncpp/json/json.h>

// ── Constants ────────────────────────────────────────────────────────────────

static constexpr int    WLED_UDP_PORT      = 21324;
static constexpr int    WLED_WEBSOCKET_PORT = 81;     // future use
static constexpr int    WLED_MAX_EFFECTS   = 118;
static constexpr int    WLED_MAX_PALETTES  = 71;

// Path where this plugin stores persistent state on the FPP filesystem.
static constexpr const char* STATE_FILE_PATH =
    "/home/fpp/media/config/wled_proxy_state.json";

// Path to the plugin's settings config file.
static constexpr const char* CONFIG_FILE_PATH =
    "/home/fpp/media/config/plugin.fpp-WLEDProxy.json";

// ── WLEDState ────────────────────────────────────────────────────────────────

/**
 * Mirrors a subset of the WLED device state object.
 * This is the authoritative in-memory state; the PHP layer reads/writes
 * the serialized JSON from STATE_FILE_PATH.
 */
struct WLEDSegmentState {
    int id       = 0;
    int start    = 0;
    int stop     = 300;
    int len      = 300;
    int fx       = 0;    // effect index
    int sx       = 128;  // speed  (0-255)
    int ix       = 128;  // intensity (0-255)
    int pal      = 0;    // palette index
    bool sel     = true;
    bool rev     = false;
    bool on      = true;
    int bri      = 255;
    int col[3][3] = {{255,0,0},{0,0,0},{0,0,0}};  // [slot][r,g,b]

    Json::Value toJson() const;
    void fromJson(const Json::Value& v);
};

struct WLEDState {
    bool on          = true;
    int  bri         = 255;
    int  transition  = 7;
    int  ps          = -1;   // preset
    int  mainseg     = 0;
    WLEDSegmentState seg;

    Json::Value toJson() const;
    void fromJson(const Json::Value& v);
};

// ── WLEDProxyPlugin ──────────────────────────────────────────────────────────

class WLEDProxyPlugin : public FPPPlugin {
public:
    WLEDProxyPlugin();
    virtual ~WLEDProxyPlugin();

    // ── FPPPlugin overrides ──────────────────────────────────────────────────

    /**
     * Called by fppd to let the plugin register API routes.
     * We register /fpp/api/plugin/fpp-WLEDProxy/* routes here.
     * (The main WLED paths /json/* and /win are handled by lighttpd + PHP.)
     */
    virtual void registerApis(httpserver::webserver* server) override;

    // ── Public helpers (called by fppd API routes) ───────────────────────────

    /** Return the current WLED state as a JSON string. */
    std::string getStateJson();

    /** Return the WLED /json/info object as a JSON string. */
    std::string getInfoJson();

    /** Apply a WLED state update. Returns the new state JSON. */
    std::string applyStateUpdate(const Json::Value& update);

    /** Return the effect name list as a JSON array string. */
    static std::string getEffectsJson();

    /** Return the palette name list as a JSON array string. */
    static std::string getPalettesJson();

    // ── Effect + palette name tables ─────────────────────────────────────────

    static const std::string EFFECT_NAMES[WLED_MAX_EFFECTS];
    static const std::string PALETTE_NAMES[WLED_MAX_PALETTES];

private:
    // ── Settings ─────────────────────────────────────────────────────────────
    std::string  m_overlayModelName;
    int          m_ledCount;
    std::string  m_deviceName;
    bool         m_enableUdpDiscovery;

    // ── State ─────────────────────────────────────────────────────────────────
    WLEDState    m_state;
    std::mutex   m_stateMutex;

    // ── UDP discovery ─────────────────────────────────────────────────────────
    std::thread  m_udpThread;
    std::atomic<bool> m_udpRunning{false};
    int          m_udpSocket = -1;

    void startUdpDiscovery();
    void stopUdpDiscovery();
    void udpDiscoveryLoop();

    // ── mDNS ─────────────────────────────────────────────────────────────────
    void registerMdns();
    void unregisterMdns();
    pid_t m_avahiPid = -1;

    // ── State persistence ─────────────────────────────────────────────────────
    void loadState();
    void saveState();

    // ── FPP overlay model control ─────────────────────────────────────────────
    bool applyEffectToModel(const WLEDSegmentState& seg, int globalBri, bool on);
    bool setModelSolidColor(int r, int g, int b, int bri);
    bool callFppApi(const std::string& method,
                    const std::string& path,
                    const std::string& body,
                    std::string& response);

    // ── Helpers ───────────────────────────────────────────────────────────────
    std::string m_pluginDir;
    std::string getMacAddress();
    std::string buildInfoJson();
};

// ── Factory function required by FPP plugin loader ────────────────────────────

extern "C" {
    FPPPlugin* createPlugin() {
        return new WLEDProxyPlugin();
    }
}
