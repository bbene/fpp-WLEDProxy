# WLED API Schema Reference

This document defines the complete required schema for WLED API responses, based on the python-wled library (v0.14.0+).

## /json Response (Full Device State)

```json
{
  "state": { ... },
  "info": { ... },
  "effects": [...],
  "palettes": [...]
}
```

## Info Object Schema (Required Fields)

Based on wled.models.Info dataclass, ALL of these fields are required:

```json
{
  "ver": "0.14.0",
  "vid": 2110050,
  "leds": {
    "count": number,
    "rgbw": boolean,
    "wleds": number,
    "fps": number,
    "pwr": number,
    "maxpwr": number,
    "maxseg": number
  },
  "str": boolean,
  "name": string,
  "udpport": number,
  "live": boolean,
  "liveseg": number,
  "liveip": string,
  "ws": number,
  "fxcount": number,
  "palcount": number,
  "wifi": {
    "bssid": string,
    "rssi": number,
    "signal": number,
    "channel": number
  },
  "arch": string,
  "core": string,
  "lwip": number,
  "mac": string,
  "ip": string,
  "filesystem": {
    "u": number,
    "t": number
  }
}
```

**Critical**: The `filesystem` object is required. It represents:
- `u`: used space (in bytes)
- `t`: total space (in bytes)

## State Object Schema

```json
{
  "on": boolean,
  "bri": number (0-255),
  "transition": number (milliseconds, in 100ms increments),
  "ps": number,
  "mainseg": number,
  "seg": [
    {
      "id": number,
      "start": number,
      "stop": number,
      "len": number,
      "grp": number,
      "spc": number,
      "fx": number,
      "sx": number,
      "ix": number,
      "pal": number,
      "sel": boolean,
      "rev": boolean,
      "on": boolean,
      "bri": number (0-255),
      "col": [
        [r, g, b],
        [r, g, b],
        [r, g, b]
      ]
    }
  ]
}
```

## Presets Object Schema (/presets.json)

Must be an OBJECT (not array), with preset IDs as keys:

```json
{
  "0": {
    "n": "Preset Name",
    "p": number (palette index),
    "ql": number (255 usually)
  },
  "1": { ... }
}
```

## Required Endpoints

### Core
- `GET /json` - Full response with state, info, effects, palettes
- `GET /json/state` - State only
- `POST /json/state` - Update state
- `GET /json/info` - Info only
- `GET /json/effects` - Effects array
- `GET /json/palettes` - Palettes array
- `GET /json/fxdata` - Effect metadata with flags
- `GET /json/si` - State + Info combined

### Presets & Config
- `GET /presets.json` - Saved presets (MUST be object, not array)
- `GET /json/cfg` - Device configuration

### Alternate Paths (for compatibility)
- `GET /api/json` - Alternate to /json
- `GET /info.json` - Alternate to /json/info
- `GET /state.json` - Alternate to /json/state
- `GET /palettes.json` - Alternate to /json/palettes

### Legacy
- `GET /win` - WLED HTTP Request API

## Known Pitfalls

1. **Presets must be an object**: Home Assistant's wled library calls `.items()` on presets, which only works on dicts/objects, not arrays.
2. **Filesystem field is required**: The Info object MUST include the `filesystem` field with `u` and `t` properties.
3. **ProxyPass path forwarding**: Apache's ProxyPass must include the target path (e.g., `ProxyPass /json http://localhost:9000/json`), not just the port.
4. **All Info fields are required**: Even if values are dummy/default, all fields listed in the Info schema must be present.

