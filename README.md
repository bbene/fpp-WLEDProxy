# FPP WLED API Proxy

Makes Falcon Pi Player (FPP) discoverable and controllable by any WLED-compatible app by emulating the WLED JSON API.  Apps like **Lightbow**, **WLED Wiz**, **Home Assistant** (WLED integration), and any controller that speaks the WLED protocol can discover FPP on your network and control its pixel overlay model effects — just like it were real WLED hardware.

---

## How it works

FPP already has WLED effects built into its Pixel Overlay Model system.  This plugin exposes those effects through the standard WLED API so you don't need a separate WLED device.

```
WLED App  ──GET /json/state──▶  FPP (lighttpd + PHP)
           ◀──  state JSON  ──

WLED App  ──POST /json/state { fx:27, sx:200 }──▶  PHP handler
           ◀── updated state ──                      │
                                                     ▼
                                     FPP Overlay Model API
                                     POST /api/overlays/model/{name}/effect/start
                                     { effectType:"WLED", effectName:"Glitter", ... }
                                                     │
                                                     ▼
                                         FPP plays WLED effect 🎆
```

Discovery is handled by:
- **mDNS** (`_wled._tcp`) via `avahi-publish-service` — so apps find FPP automatically
- **UDP port 21324** via the C++ plugin loaded into fppd

---

## Architecture

| Component | File | Role |
|-----------|------|------|
| C++ plugin (.so) | `src/WLEDProxyPlugin.cpp` | UDP discovery (port 21324), mDNS, state persistence |
| PHP API handler | `www/wled_api.php` | Implements all WLED HTTP endpoints |
| Plugin settings UI | `www/plugin_setup.php` | FPP settings page for configuring the plugin |
| lighttpd config | `conf/88-wled-proxy.conf` | Routes `/json/*` and `/win` to PHP handler |
| Install script | `fpp_install.sh` | Compiles .so, installs lighttpd config, writes defaults |

---

## Supported WLED API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/json` | Full WLED response: state + info + effects + palettes |
| GET | `/json/state` | Current WLED state |
| POST | `/json/state` | Update state / change effect |
| GET | `/json/info` | Device info (name, LED count, MAC address…) |
| GET | `/json/si` | State + info combined |
| GET | `/json/effects` | Array of 118 WLED effect names |
| GET | `/json/palettes` | Array of 71 palette names |
| GET/POST | `/win` | WLED HTTP Request API (legacy / simple integrations) |

---

## Prerequisites

- FPP 6.0 or later (tested on FPP 7.x / 9.x)
- At least one **Pixel Overlay Model** configured in FPP
  (`Content Setup → Pixel Overlay Models`)
- `libjsoncpp-dev`, `avahi-utils`, `libcurl4-openssl-dev` (installed automatically by `fpp_install.sh`)
- `g++` with C++17 support

---

## Installation

### From the FPP Plugin Manager (recommended)

1. In FPP, go to **Content Setup → Plugins → Install Plugin**.
2. Enter the repository URL and click **Install**.
3. FPP will run `fpp_install.sh` automatically to compile and configure the plugin.
4. Restart fppd or reboot when prompted.

### Manual installation

```bash
# Clone into FPP's plugin directory
cd /home/fpp/media/plugins
git clone https://github.com/your-repo/fpp-WLEDProxy.git

# Run the install script
cd fpp-WLEDProxy
sudo bash fpp_install.sh

# Restart fppd
sudo systemctl restart fppd
```

---

## Configuration

1. Go to **Content Setup → Plugins → WLED API Proxy → Settings**
   (or navigate to `http://fpp.local/plugin/fpp-WLEDProxy/plugin_setup.php`)

2. Set:
   - **Pixel Overlay Model** — the model WLED effects will run on
   - **LED Count** — number of pixels (reported to WLED apps for segment UI)
   - **Device Name** — name shown in WLED app discovery lists
   - **UDP Discovery** — enable/disable UDP port 21324 listener

3. Click **Save Settings**.

---

## Quick-start test

```bash
# From any machine on your network — check FPP looks like WLED:
curl http://fpp.local/json/info | python3 -m json.tool

# Trigger the "Fireworks" effect (effect ID 42) at full speed:
curl -X POST http://fpp.local/json/state \
  -H 'Content-Type: application/json' \
  -d '{"on":true,"bri":255,"seg":[{"fx":42,"sx":255,"ix":200,"pal":6}]}'

# Turn off via the HTTP API:
curl "http://fpp.local/win?off"

# Set solid red:
curl "http://fpp.local/win?col=FF0000&bri=200"
```

---

## WLED Effect List (118 effects)

Effects map 1:1 to WLED 0.14 effect IDs and names, which FPP's overlay model
effect engine understands natively.  The full list is in `data/effects.json`.

Selected highlights:

| ID | Name | ID | Name |
|----|------|----|------|
| 0  | Solid | 42 | Fireworks |
| 1  | Blink | 45 | Fire Flicker |
| 2  | Breathe | 57 | Lightning |
| 9  | Rainbow | 66 | Fire 2012 |
| 27 | Android | 76 | Meteor |
| 28 | Chase | 87 | Glitter |
| 40 | Scanner | 88 | Candle |

---

## FPP Overlay Model API notes

The plugin calls FPP's internal API to apply effects.  If your FPP version
uses a different API path or body format, edit the `applyStateToFPP()` function
in `www/wled_api.php` (and `applyEffectToModel()` in `src/WLEDProxyPlugin.cpp`).

Known FPP API paths (FPP 6.x – 9.x):

```
POST /api/overlays/model/{name}/state           {"State":1}
POST /api/overlays/model/{name}/effect/start    {effectType,effectName,speed,intensity,...}
POST /api/overlays/model/{name}/effect/stop
POST /api/overlays/model/{name}/fill            {"r":R,"g":G,"b":B}
```

---

## Troubleshooting

**WLED app can't find FPP**
- Confirm `avahi-daemon` is running: `systemctl status avahi-daemon`
- Test mDNS: `avahi-browse -at | grep wled`
- Try entering FPP's IP manually in the WLED app

**`/json/state` returns 404**
- Check lighttpd config was installed: `ls /etc/lighttpd/conf-enabled/88-wled-proxy.conf`
- Test manually: `lighttpd -t -f /etc/lighttpd/lighttpd.conf`
- Check plugin symlink: `ls -la /opt/fpp/www/plugin/fpp-WLEDProxy/`

**Effects aren't playing on FPP**
- Confirm your Pixel Overlay Model is created and the name matches exactly what's configured in the plugin
- Test the FPP API directly: `curl -X POST http://localhost/api/overlays/model/YOUR_MODEL/state -d '{"State":1}'`
- Check FPP logs: `journalctl -u fppd -f`

**C++ plugin not loading**
- Check compilation: `ls -la /home/fpp/media/plugins/fpp-WLEDProxy/src/WLEDProxyPlugin.so`
- Run `make` manually in the plugin directory
- Ensure `libjsoncpp-dev` is installed: `apt list --installed | grep jsoncpp`

---

## License

MIT — see LICENSE file.
