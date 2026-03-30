/*
 * FPP WLED API Proxy Plugin — WLEDProxyPlugin.cpp
 *
 * Handles:
 *   1. UDP port 21324 — WLED-compatible device discovery
 *   2. mDNS via avahi-publish — advertise as _wled._tcp
 *   3. FPP overlay model effect control via FPP's internal REST API
 *   4. State persistence to /home/fpp/media/config/wled_proxy_state.json
 *
 * The HTTP-facing WLED JSON API (/json/*, /win) is handled by the
 * companion PHP file www/wled_api.php, routed via lighttpd rewrite rules
 * in conf/88-wled-proxy.conf.  The PHP layer reads/writes the state file
 * and calls FPP's API to trigger effects.  This C++ plugin registers
 * lightweight internal API routes under /fpp/api/plugin/fpp-WLEDProxy/
 * that the PHP can optionally use for in-process state access.
 */

#include "WLEDProxyPlugin.h"

#include <cstring>
#include <cstdio>
#include <cerrno>
#include <fstream>
#include <sstream>
#include <chrono>
#include <stdexcept>

// POSIX / networking
#include <unistd.h>
#include <sys/socket.h>
#include <netinet/in.h>
#include <arpa/inet.h>
#include <net/if.h>
#include <sys/ioctl.h>
#include <sys/wait.h>
#include <ifaddrs.h>
#include <signal.h>

// FPP logging macro — adjust to your FPP version if needed.
// In FPP 6+ this is typically:  LogInfo(VB_PLUGIN, "message")
#ifndef LogInfo
#  define LogInfo(lvl, ...)   fprintf(stdout, "[WLEDProxy] " __VA_ARGS__)
#endif
#ifndef LogErr
#  define LogErr(lvl, ...)    fprintf(stderr, "[WLEDProxy] ERROR: " __VA_ARGS__)
#endif
#ifndef VB_PLUGIN
#  define VB_PLUGIN 0
#endif

// ── WLED effect name table (WLED 0.14 / FPP 7+) ─────────────────────────────

const std::string WLEDProxyPlugin::EFFECT_NAMES[WLED_MAX_EFFECTS] = {
/*  0*/ "Solid",
/*  1*/ "Blink",
/*  2*/ "Breathe",
/*  3*/ "Wipe",
/*  4*/ "Wipe Random",
/*  5*/ "Random Colors",
/*  6*/ "Sweep",
/*  7*/ "Dynamic",
/*  8*/ "Colorloop",
/*  9*/ "Rainbow",
/* 10*/ "Scan",
/* 11*/ "Scan Dual",
/* 12*/ "Fade",
/* 13*/ "Theater",
/* 14*/ "Theater Rainbow",
/* 15*/ "Running",
/* 16*/ "Saw",
/* 17*/ "Twinkle",
/* 18*/ "Dissolve",
/* 19*/ "Dissolve Rnd",
/* 20*/ "Sparkle",
/* 21*/ "Sparkle Dark",
/* 22*/ "Sparkle+",
/* 23*/ "Strobe",
/* 24*/ "Strobe Rainbow",
/* 25*/ "Strobe Mega",
/* 26*/ "Blink Rainbow",
/* 27*/ "Android",
/* 28*/ "Chase",
/* 29*/ "Chase Random",
/* 30*/ "Chase Rainbow",
/* 31*/ "Chase Flash",
/* 32*/ "Chase Flash Rnd",
/* 33*/ "Rainbow Runner",
/* 34*/ "Colorful",
/* 35*/ "Traffic Light",
/* 36*/ "Sweep Random",
/* 37*/ "Running 2",
/* 38*/ "Red & Blue",
/* 39*/ "Stream",
/* 40*/ "Scanner",
/* 41*/ "Lighthouse",
/* 42*/ "Fireworks",
/* 43*/ "Rain",
/* 44*/ "Merry Christmas",
/* 45*/ "Fire Flicker",
/* 46*/ "Gradient",
/* 47*/ "Loading",
/* 48*/ "Police",
/* 49*/ "Police All",
/* 50*/ "Two Dots",
/* 51*/ "Two Areas",
/* 52*/ "Circus",
/* 53*/ "Halloween",
/* 54*/ "Tri Chase",
/* 55*/ "Tri Wipe",
/* 56*/ "Tri Fade",
/* 57*/ "Lightning",
/* 58*/ "ICU",
/* 59*/ "Multi Comet",
/* 60*/ "Scanner Dual",
/* 61*/ "Stream 2",
/* 62*/ "Oscillate",
/* 63*/ "Pride 2015",
/* 64*/ "Juggle",
/* 65*/ "Palette",
/* 66*/ "Fire 2012",
/* 67*/ "Colorwaves",
/* 68*/ "Bpm",
/* 69*/ "Fill Noise",
/* 70*/ "Noise 1",
/* 71*/ "Noise 2",
/* 72*/ "Noise 3",
/* 73*/ "Noise 4",
/* 74*/ "Colortwinkles",
/* 75*/ "Lake",
/* 76*/ "Meteor",
/* 77*/ "Meteor Smooth",
/* 78*/ "Railway",
/* 79*/ "Ripple",
/* 80*/ "Twinklefox",
/* 81*/ "Twinklecat",
/* 82*/ "Halloween Eyes",
/* 83*/ "Solid Pattern",
/* 84*/ "Solid Pattern Tri",
/* 85*/ "Spots",
/* 86*/ "Spots Fade",
/* 87*/ "Glitter",
/* 88*/ "Candle",
/* 89*/ "Fireworks Starburst",
/* 90*/ "Fireworks 1D",
/* 91*/ "Bouncing Balls",
/* 92*/ "Sinelon",
/* 93*/ "Sinelon Dual",
/* 94*/ "Sinelon Rainbow",
/* 95*/ "Popcorn",
/* 96*/ "Drip",
/* 97*/ "Plasma",
/* 98*/ "Percent",
/* 99*/ "Ripple Rainbow",
/*100*/ "Heartbeat",
/*101*/ "Pacifica",
/*102*/ "Candle Multi",
/*103*/ "Solid Glitter",
/*104*/ "Sunrise",
/*105*/ "Phased",
/*106*/ "Twinkleup",
/*107*/ "Noise Pal",
/*108*/ "Sine",
/*109*/ "Phased Noise",
/*110*/ "Flow",
/*111*/ "Chunchun",
/*112*/ "Dancing Shadows",
/*113*/ "Washing Machine",
/*114*/ "Candy Cane",
/*115*/ "Blends",
/*116*/ "TV Simulator",
/*117*/ "Dynamic Smooth"
};

// ── WLED palette name table (WLED 0.14) ──────────────────────────────────────

const std::string WLEDProxyPlugin::PALETTE_NAMES[WLED_MAX_PALETTES] = {
/*  0*/ "Default",
/*  1*/ "* Random Cycle",
/*  2*/ "* Color 1",
/*  3*/ "* Colors 1&2",
/*  4*/ "* Color Gradient",
/*  5*/ "* Colors Only",
/*  6*/ "Party",
/*  7*/ "Cloud",
/*  8*/ "Lava",
/*  9*/ "Ocean",
/* 10*/ "Forest",
/* 11*/ "Rainbow",
/* 12*/ "Rainbow Bands",
/* 13*/ "Sunset",
/* 14*/ "Rivendell",
/* 15*/ "Breeze",
/* 16*/ "Red & Blue",
/* 17*/ "Yellowout",
/* 18*/ "Analogous",
/* 19*/ "Splash",
/* 20*/ "Pastel",
/* 21*/ "Sunset 2",
/* 22*/ "Beach",
/* 23*/ "Vintage",
/* 24*/ "Departure",
/* 25*/ "Landscape",
/* 26*/ "Beech",
/* 27*/ "Sherbet",
/* 28*/ "Hult",
/* 29*/ "Hult 64",
/* 30*/ "Drywet",
/* 31*/ "Jul",
/* 32*/ "Grintage",
/* 33*/ "Rewhi",
/* 34*/ "Tertiary",
/* 35*/ "Fire",
/* 36*/ "Icefire",
/* 37*/ "Cyane",
/* 38*/ "Light Pink",
/* 39*/ "Autumn",
/* 40*/ "Magenta",
/* 41*/ "Magred",
/* 42*/ "Yelmag",
/* 43*/ "Yelblu",
/* 44*/ "Orange & Teal",
/* 45*/ "Tiamat",
/* 46*/ "April Night",
/* 47*/ "Orangery",
/* 48*/ "C9",
/* 49*/ "Sakura",
/* 50*/ "Aurora",
/* 51*/ "Atlantica",
/* 52*/ "C9 2",
/* 53*/ "C9 New",
/* 54*/ "Temperature",
/* 55*/ "Aurora 2",
/* 56*/ "Retro Clown",
/* 57*/ "Candy",
/* 58*/ "Toxy Reaf",
/* 59*/ "Fairy Reaf",
/* 60*/ "Semi Blue",
/* 61*/ "Pink Candy",
/* 62*/ "Red Reaf",
/* 63*/ "Aqua Flash",
/* 64*/ "Yelblu Hot",
/* 65*/ "Lite Light",
/* 66*/ "Red Flash",
/* 67*/ "Blink Red",
/* 68*/ "Red Shift",
/* 69*/ "Red Tide",
/* 70*/ "Candy2"
};

// ── WLEDSegmentState serialization ──────────────────────────────────────────

Json::Value WLEDSegmentState::toJson() const {
    Json::Value v;
    v["id"]  = id;
    v["start"] = start;
    v["stop"]  = stop;
    v["len"]   = len;
    v["fx"]    = fx;
    v["sx"]    = sx;
    v["ix"]    = ix;
    v["pal"]   = pal;
    v["sel"]   = sel;
    v["rev"]   = rev;
    v["on"]    = on;
    v["bri"]   = bri;
    v["grp"]   = 1;
    v["spc"]   = 0;

    Json::Value cols(Json::arrayValue);
    for (int i = 0; i < 3; i++) {
        Json::Value c(Json::arrayValue);
        c.append(col[i][0]);
        c.append(col[i][1]);
        c.append(col[i][2]);
        cols.append(c);
    }
    v["col"] = cols;
    return v;
}

void WLEDSegmentState::fromJson(const Json::Value& v) {
    if (v.isMember("id"))  id  = v["id"].asInt();
    if (v.isMember("fx"))  fx  = v["fx"].asInt();
    if (v.isMember("sx"))  sx  = v["sx"].asInt();
    if (v.isMember("ix"))  ix  = v["ix"].asInt();
    if (v.isMember("pal")) pal = v["pal"].asInt();
    if (v.isMember("sel")) sel = v["sel"].asBool();
    if (v.isMember("rev")) rev = v["rev"].asBool();
    if (v.isMember("on"))  on  = v["on"].asBool();
    if (v.isMember("bri")) bri = v["bri"].asInt();

    if (v.isMember("col") && v["col"].isArray()) {
        const auto& ca = v["col"];
        for (int i = 0; i < 3 && i < (int)ca.size(); i++) {
            if (ca[i].isArray() && ca[i].size() >= 3) {
                col[i][0] = ca[i][0].asInt();
                col[i][1] = ca[i][1].asInt();
                col[i][2] = ca[i][2].asInt();
            }
        }
    }
}

// ── WLEDState serialization ──────────────────────────────────────────────────

Json::Value WLEDState::toJson() const {
    Json::Value v;
    v["on"]         = on;
    v["bri"]        = bri;
    v["transition"] = transition;
    v["ps"]         = ps;
    v["pl"]         = -1;
    v["mainseg"]    = mainseg;
    v["lor"]        = 0;

    Json::Value segs(Json::arrayValue);
    segs.append(seg.toJson());
    v["seg"] = segs;

    Json::Value nl;
    nl["on"]   = false;
    nl["dur"]  = 60;
    nl["tbri"] = 0;
    nl["fade"] = true;
    nl["mode"] = 1;
    v["nl"] = nl;

    Json::Value udpn;
    udpn["send"] = false;
    udpn["recv"] = false;
    v["udpn"] = udpn;

    return v;
}

void WLEDState::fromJson(const Json::Value& v) {
    if (v.isMember("on"))         on         = v["on"].asBool();
    if (v.isMember("bri"))        bri        = v["bri"].asInt();
    if (v.isMember("transition")) transition = v["transition"].asInt();
    if (v.isMember("ps"))         ps         = v["ps"].asInt();
    if (v.isMember("mainseg"))    mainseg    = v["mainseg"].asInt();

    if (v.isMember("seg") && v["seg"].isArray() && !v["seg"].empty()) {
        seg.fromJson(v["seg"][0]);
    }
}

// ── Constructor / Destructor ─────────────────────────────────────────────────

WLEDProxyPlugin::WLEDProxyPlugin()
    : FPPPlugin("fpp-WLEDProxy"),
      m_overlayModelName("All Pixels"),
      m_ledCount(300),
      m_deviceName("FPP WLED"),
      m_enableUdpDiscovery(true)
{
    LogInfo(VB_PLUGIN, "WLEDProxyPlugin: starting up\n");
    loadState();
    registerMdns();
    if (m_enableUdpDiscovery) {
        startUdpDiscovery();
    }
}

WLEDProxyPlugin::~WLEDProxyPlugin() {
    LogInfo(VB_PLUGIN, "WLEDProxyPlugin: shutting down\n");
    stopUdpDiscovery();
    unregisterMdns();
    saveState();
}

// ── FPPPlugin overrides ──────────────────────────────────────────────────────

void WLEDProxyPlugin::registerApis(httpserver::webserver* server) {
    // HTTP APIs are handled by the PHP layer (www/wled_api.php) via lighttpd routing.
    // The C++ plugin focuses on UDP discovery, mDNS, and state persistence.
    // This stub is here for FPP plugin compatibility but performs no action.
}

// ── Public API helpers ───────────────────────────────────────────────────────

std::string WLEDProxyPlugin::getStateJson() {
    std::lock_guard<std::mutex> lock(m_stateMutex);
    Json::StreamWriterBuilder wrt;
    wrt["indentation"] = "";
    return Json::writeString(wrt, m_state.toJson());
}

std::string WLEDProxyPlugin::getInfoJson() {
    return buildInfoJson();
}

std::string WLEDProxyPlugin::applyStateUpdate(const Json::Value& update) {
    std::lock_guard<std::mutex> lock(m_stateMutex);

    // Apply top-level fields
    if (update.isMember("on"))         m_state.on  = update["on"].asBool();
    if (update.isMember("bri"))        m_state.bri = update["bri"].asInt();
    if (update.isMember("transition")) m_state.transition = update["transition"].asInt();

    // Apply segment fields (we only support a single segment — seg[0])
    if (update.isMember("seg") && update["seg"].isArray()) {
        for (const auto& segUpdate : update["seg"]) {
            // Ignore segments with id != 0 for now
            int sid = segUpdate.isMember("id") ? segUpdate["id"].asInt() : 0;
            if (sid == 0) {
                m_state.seg.fromJson(segUpdate);
            }
        }
    }

    // Persist and apply
    saveState();
    applyEffectToModel(m_state.seg, m_state.bri, m_state.on);

    Json::StreamWriterBuilder wrt;
    wrt["indentation"] = "";
    return Json::writeString(wrt, m_state.toJson());
}

std::string WLEDProxyPlugin::getEffectsJson() {
    Json::Value arr(Json::arrayValue);
    for (int i = 0; i < WLED_MAX_EFFECTS; i++) {
        arr.append(EFFECT_NAMES[i]);
    }
    Json::StreamWriterBuilder wrt;
    wrt["indentation"] = "";
    return Json::writeString(wrt, arr);
}

std::string WLEDProxyPlugin::getPalettesJson() {
    Json::Value arr(Json::arrayValue);
    for (int i = 0; i < WLED_MAX_PALETTES; i++) {
        arr.append(PALETTE_NAMES[i]);
    }
    Json::StreamWriterBuilder wrt;
    wrt["indentation"] = "";
    return Json::writeString(wrt, arr);
}

// ── State persistence ────────────────────────────────────────────────────────

void WLEDProxyPlugin::loadState() {
    std::ifstream f(STATE_FILE_PATH);
    if (!f.is_open()) return;

    Json::Value v;
    Json::CharReaderBuilder rdr;
    std::string errs;
    if (Json::parseFromStream(rdr, f, &v, &errs)) {
        std::lock_guard<std::mutex> lock(m_stateMutex);
        m_state.fromJson(v);
        LogInfo(VB_PLUGIN, "WLEDProxy: state loaded from %s\n", STATE_FILE_PATH);
    } else {
        LogErr(VB_PLUGIN, "WLEDProxy: failed to load state: %s\n", errs.c_str());
    }
}

void WLEDProxyPlugin::saveState() {
    // Caller must hold m_stateMutex
    Json::StreamWriterBuilder wrt;
    wrt["indentation"] = "  ";
    std::string out = Json::writeString(wrt, m_state.toJson());

    std::ofstream f(STATE_FILE_PATH);
    if (f.is_open()) {
        f << out;
        LogInfo(VB_PLUGIN, "WLEDProxy: state saved\n");
    } else {
        LogErr(VB_PLUGIN, "WLEDProxy: cannot write state to %s: %s\n",
               STATE_FILE_PATH, strerror(errno));
    }
}

// ── FPP overlay model control ─────────────────────────────────────────────────
/*
 * This section calls FPP's internal REST API (on localhost:80) to control
 * the configured Pixel Overlay Model.
 *
 * FPP Overlay Model Effect API (adjust path if your FPP version differs):
 *
 *   Enable model:
 *     POST /api/overlays/model/{name}/state
 *     Body: {"State":1}
 *
 *   Start a WLED effect:
 *     POST /api/overlays/model/{name}/effect/start
 *     Body: {
 *       "effectType": "WLED",
 *       "effectName": "<WLED effect name>",
 *       "speed":      0-255,
 *       "intensity":  0-255,
 *       "palette":    "<palette name>",
 *       "color1": "RRGGBB",
 *       "color2": "RRGGBB",
 *       "color3": "RRGGBB",
 *       "autoResetAfterTimeout": false
 *     }
 *
 *   Stop effects on model:
 *     POST /api/overlays/model/{name}/effect/stop
 *
 *   Fill with solid color:
 *     POST /api/overlays/model/{name}/fill
 *     Body: {"r":R,"g":G,"b":B}
 *
 * NOTE: Some versions of FPP use the Command API instead:
 *   GET /api/command/Overlay%20Model%20Effect/Start/{modelName}/{effectName}/{speed}/{intensity}
 */

bool WLEDProxyPlugin::applyEffectToModel(const WLEDSegmentState& seg, int globalBri, bool on) {
    // Scale brightness: WLED uses 0-255 for both global and segment bri.
    // FPP accepts 0-100 for some params, so we scale where needed.
    const int effectBri = on ? (int)((long)globalBri * seg.bri / 255) : 0;

    // Build color strings (6-char hex)
    char color1[7], color2[7], color3[7];
    snprintf(color1, sizeof(color1), "%02X%02X%02X",
             (seg.col[0][0] * effectBri) / 255,
             (seg.col[0][1] * effectBri) / 255,
             (seg.col[0][2] * effectBri) / 255);
    snprintf(color2, sizeof(color2), "%02X%02X%02X",
             (seg.col[1][0] * effectBri) / 255,
             (seg.col[1][1] * effectBri) / 255,
             (seg.col[1][2] * effectBri) / 255);
    snprintf(color3, sizeof(color3), "%02X%02X%02X",
             (seg.col[2][0] * effectBri) / 255,
             (seg.col[2][1] * effectBri) / 255,
             (seg.col[2][2] * effectBri) / 255);

    const std::string effectName = (seg.fx >= 0 && seg.fx < WLED_MAX_EFFECTS)
                                   ? EFFECT_NAMES[seg.fx] : "Solid";

    const std::string paletteName = (seg.pal >= 0 && seg.pal < WLED_MAX_PALETTES)
                                    ? PALETTE_NAMES[seg.pal] : "Default";

    // ── Step 1: enable the overlay model ──────────────────────────────────────
    {
        std::string path = "/api/overlays/model/" + m_overlayModelName + "/state";
        std::string body = "{\"State\":1}";
        std::string resp;
        if (!callFppApi("POST", path, body, resp)) {
            LogErr(VB_PLUGIN, "WLEDProxy: failed to enable overlay model '%s'\n",
                   m_overlayModelName.c_str());
            // Non-fatal: continue and attempt the effect anyway
        }
    }

    // ── Step 2: handle "off" state ────────────────────────────────────────────
    if (!on || effectBri == 0) {
        std::string path = "/api/overlays/model/" + m_overlayModelName + "/fill";
        std::string body = "{\"r\":0,\"g\":0,\"b\":0}";
        std::string resp;
        return callFppApi("POST", path, body, resp);
    }

    // ── Step 3: solid color (effect 0) ────────────────────────────────────────
    if (seg.fx == 0) {
        std::string path = "/api/overlays/model/" + m_overlayModelName + "/fill";
        char body[64];
        snprintf(body, sizeof(body), "{\"r\":%d,\"g\":%d,\"b\":%d}",
                 (seg.col[0][0] * effectBri) / 255,
                 (seg.col[0][1] * effectBri) / 255,
                 (seg.col[0][2] * effectBri) / 255);
        std::string resp;
        return callFppApi("POST", path, body, resp);
    }

    // ── Step 4: animated WLED effect ─────────────────────────────────────────
    {
        char body[512];
        snprintf(body, sizeof(body),
            "{"
            "\"effectType\":\"WLED\","
            "\"effectName\":\"%s\","
            "\"speed\":%d,"
            "\"intensity\":%d,"
            "\"palette\":\"%s\","
            "\"color1\":\"%s\","
            "\"color2\":\"%s\","
            "\"color3\":\"%s\","
            "\"autoResetAfterTimeout\":false"
            "}",
            effectName.c_str(),
            seg.sx,
            seg.ix,
            paletteName.c_str(),
            color1, color2, color3);

        std::string path = "/api/overlays/model/" + m_overlayModelName + "/effect/start";
        std::string resp;
        bool ok = callFppApi("POST", path, body, resp);
        if (!ok) {
            LogErr(VB_PLUGIN, "WLEDProxy: effect '%s' failed on model '%s'\n",
                   effectName.c_str(), m_overlayModelName.c_str());
        }
        return ok;
    }
}

bool WLEDProxyPlugin::callFppApi(const std::string& method,
                                  const std::string& path,
                                  const std::string& body,
                                  std::string& response) {
    // Use the fpp command-line tool (fpp -c) or curl to hit the local FPP API.
    // We use popen here for simplicity; a production version should use
    // libcurl or FPP's internal HTTP client directly.
    char cmd[2048];
    if (method == "POST") {
        snprintf(cmd, sizeof(cmd),
            "curl -s -X POST "
            "-H 'Content-Type: application/json' "
            "-d '%s' "
            "'http://localhost%s' 2>/dev/null",
            body.c_str(), path.c_str());
    } else {
        snprintf(cmd, sizeof(cmd),
            "curl -s 'http://localhost%s' 2>/dev/null",
            path.c_str());
    }

    FILE* pipe = popen(cmd, "r");
    if (!pipe) {
        LogErr(VB_PLUGIN, "WLEDProxy: popen failed for %s %s\n", method.c_str(), path.c_str());
        return false;
    }

    char buf[4096];
    response.clear();
    while (fgets(buf, sizeof(buf), pipe)) {
        response += buf;
    }
    int rc = pclose(pipe);
    return (rc == 0);
}

// ── mDNS registration ────────────────────────────────────────────────────────

void WLEDProxyPlugin::registerMdns() {
    // Advertise as _wled._tcp on port 80 using avahi-publish-service.
    // This allows WLED apps to discover FPP via mDNS (Bonjour/Zeroconf).
    //
    // The service TXT records mirror what real WLED devices advertise.
    // Adjust the txt records based on what your target WLED app expects.

    char cmd[1024];
    snprintf(cmd, sizeof(cmd),
        "avahi-publish-service '%s' _wled._tcp 80 "
        "\"mac=%s\" "
        "\"ip=\" "
        "\"version=0.14.0\" "
        "\"build=2110050\" "
        "\"mode=0\" "
        "\"name=%s\" "
        "\"arch=esp32\" "
        "& echo $!",
        m_deviceName.c_str(),
        getMacAddress().c_str(),
        m_deviceName.c_str());

    FILE* pipe = popen(cmd, "r");
    if (pipe) {
        char pidStr[32];
        if (fgets(pidStr, sizeof(pidStr), pipe)) {
            m_avahiPid = (pid_t)atoi(pidStr);
            LogInfo(VB_PLUGIN, "WLEDProxy: mDNS registered as '%s' (avahi pid=%d)\n",
                    m_deviceName.c_str(), m_avahiPid);
        }
        pclose(pipe);
    } else {
        LogErr(VB_PLUGIN, "WLEDProxy: failed to start avahi-publish-service\n");
    }
}

void WLEDProxyPlugin::unregisterMdns() {
    if (m_avahiPid > 0) {
        kill(m_avahiPid, SIGTERM);
        waitpid(m_avahiPid, nullptr, WNOHANG);
        m_avahiPid = -1;
        LogInfo(VB_PLUGIN, "WLEDProxy: mDNS unregistered\n");
    }
}

// ── UDP discovery (WLED protocol, port 21324) ─────────────────────────────────
/*
 * WLED apps send a discovery broadcast to port 21324.
 * The packet payload is typically a JSON object {"v":true} or just the
 * raw byte 0x01 (version query).  We respond with our state JSON so that
 * the app can identify us as a WLED device.
 */

void WLEDProxyPlugin::startUdpDiscovery() {
    m_udpSocket = socket(AF_INET, SOCK_DGRAM, 0);
    if (m_udpSocket < 0) {
        LogErr(VB_PLUGIN, "WLEDProxy: UDP socket creation failed: %s\n", strerror(errno));
        return;
    }

    int yes = 1;
    setsockopt(m_udpSocket, SOL_SOCKET, SO_REUSEADDR, &yes, sizeof(yes));
    setsockopt(m_udpSocket, SOL_SOCKET, SO_BROADCAST, &yes, sizeof(yes));

    struct sockaddr_in addr{};
    addr.sin_family      = AF_INET;
    addr.sin_port        = htons(WLED_UDP_PORT);
    addr.sin_addr.s_addr = INADDR_ANY;

    if (bind(m_udpSocket, (struct sockaddr*)&addr, sizeof(addr)) < 0) {
        LogErr(VB_PLUGIN, "WLEDProxy: UDP bind on port %d failed: %s\n",
               WLED_UDP_PORT, strerror(errno));
        close(m_udpSocket);
        m_udpSocket = -1;
        return;
    }

    m_udpRunning = true;
    m_udpThread  = std::thread(&WLEDProxyPlugin::udpDiscoveryLoop, this);
    LogInfo(VB_PLUGIN, "WLEDProxy: UDP discovery listening on port %d\n", WLED_UDP_PORT);
}

void WLEDProxyPlugin::stopUdpDiscovery() {
    m_udpRunning = false;
    if (m_udpSocket >= 0) {
        shutdown(m_udpSocket, SHUT_RDWR);
        close(m_udpSocket);
        m_udpSocket = -1;
    }
    if (m_udpThread.joinable()) {
        m_udpThread.join();
    }
    LogInfo(VB_PLUGIN, "WLEDProxy: UDP discovery stopped\n");
}

void WLEDProxyPlugin::udpDiscoveryLoop() {
    char recvBuf[1500];
    struct sockaddr_in sender{};
    socklen_t senderLen = sizeof(sender);

    while (m_udpRunning) {
        fd_set rfds;
        FD_ZERO(&rfds);
        FD_SET(m_udpSocket, &rfds);

        struct timeval tv{};
        tv.tv_sec  = 1;
        tv.tv_usec = 0;

        int ret = select(m_udpSocket + 1, &rfds, nullptr, nullptr, &tv);
        if (ret <= 0) continue;  // timeout or error — loop back

        ssize_t n = recvfrom(m_udpSocket, recvBuf, sizeof(recvBuf) - 1, 0,
                             (struct sockaddr*)&sender, &senderLen);
        if (n <= 0) continue;
        recvBuf[n] = '\0';

        // WLED discovery packets:
        //  • Raw byte 0x01 — version/status query
        //  • JSON {"v":true} — version query
        //  • JSON {"o":true} — query for state
        // In all cases we respond with our WLED state JSON.
        bool isDiscovery = false;
        if (n == 1 && (unsigned char)recvBuf[0] == 0x01) {
            isDiscovery = true;
        } else if (n > 1) {
            // Quick JSON check without full parse
            std::string pkt(recvBuf, n);
            if (pkt.find("\"v\"") != std::string::npos ||
                pkt.find("\"o\"") != std::string::npos) {
                isDiscovery = true;
            }
        }

        if (isDiscovery) {
            std::string response = getStateJson();
            sendto(m_udpSocket,
                   response.c_str(), response.size(), 0,
                   (struct sockaddr*)&sender, senderLen);
            LogInfo(VB_PLUGIN, "WLEDProxy: UDP discovery response sent to %s:%d\n",
                    inet_ntoa(sender.sin_addr), ntohs(sender.sin_port));
        }
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

std::string WLEDProxyPlugin::getMacAddress() {
    struct ifaddrs* ifaddr = nullptr;
    getifaddrs(&ifaddr);

    std::string mac = "AA:BB:CC:DD:EE:FF";

    for (struct ifaddrs* ifa = ifaddr; ifa != nullptr; ifa = ifa->ifa_next) {
        if (!ifa->ifa_name) continue;
        std::string name(ifa->ifa_name);
        // Prefer eth0 / wlan0 over lo
        if (name == "lo") continue;

        int sock = socket(AF_INET, SOCK_DGRAM, 0);
        if (sock < 0) continue;

        struct ifreq ifr{};
        strncpy(ifr.ifr_name, ifa->ifa_name, IFNAMSIZ - 1);
        if (ioctl(sock, SIOCGIFHWADDR, &ifr) == 0) {
            unsigned char* hw = (unsigned char*)ifr.ifr_hwaddr.sa_data;
            char buf[18];
            snprintf(buf, sizeof(buf), "%02X:%02X:%02X:%02X:%02X:%02X",
                     hw[0], hw[1], hw[2], hw[3], hw[4], hw[5]);
            mac = buf;
            close(sock);
            break;
        }
        close(sock);
    }

    if (ifaddr) freeifaddrs(ifaddr);
    return mac;
}

std::string WLEDProxyPlugin::buildInfoJson() {
    std::lock_guard<std::mutex> lock(m_stateMutex);

    Json::Value info;
    info["ver"]   = "0.14.0";
    info["vid"]   = 2110050;
    info["name"]  = m_deviceName;
    info["arch"]  = "esp32";
    info["core"]  = "fpp";
    info["lwip"]  = 0;
    info["mac"]   = getMacAddress();
    info["ip"]    = "";  // populated by client
    info["udpport"] = WLED_UDP_PORT;
    info["live"]  = false;
    info["liveseg"] = -1;
    info["liveip"] = "";
    info["ws"]    = 0;
    info["fxcount"] = WLED_MAX_EFFECTS;
    info["palcount"] = WLED_MAX_PALETTES;

    Json::Value leds;
    leds["count"]  = m_ledCount;
    leds["rgbw"]   = false;
    leds["wleds"]  = 0;
    leds["fps"]    = 30;
    leds["pwr"]    = 0;
    leds["maxpwr"] = 0;
    leds["maxseg"] = 1;
    leds["seglc"]  = Json::Value(Json::arrayValue);
    leds["seglc"].append(m_ledCount);
    info["leds"] = leds;

    info["str"]  = false;
    info["ndc"]  = 0;
    info["node"] = Json::Value(Json::objectValue);

    // Capabilities — tell apps which features we support
    Json::Value opts;
    opts["noact"]    = false;
    opts["ota"]      = false;
    opts["wifi"]     = false;
    opts["blynk"]    = false;
    opts["cronixie"] = false;
    opts["phase"]    = false;
    opts["pwr"]      = false;
    opts["frbck"]    = false;
    opts["rgbwMode"] = 0;
    info["opt"] = opts;

    Json::Value wifi;
    wifi["bssid"]   = "";
    wifi["rssi"]    = -50;
    wifi["signal"]  = 100;
    wifi["channel"] = 0;
    info["wifi"] = wifi;

    Json::StreamWriterBuilder wrt;
    wrt["indentation"] = "";
    return Json::writeString(wrt, info);
}
