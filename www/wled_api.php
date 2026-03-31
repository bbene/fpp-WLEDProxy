<?php
/**
 * FPP WLED API Proxy — HTTP Handler
 *
 * Implements the WLED JSON API and HTTP Request API so that any
 * WLED-compatible app (Lightbow, WLED Wiz, Home Assistant, etc.)
 * can control FPP's pixel overlay model effects.
 *
 * Endpoints handled (all routed here by lighttpd via 88-wled-proxy.conf):
 *   GET  /json             → combined state + info (like real WLED)
 *   GET  /json/state       → current WLED state object
 *   POST /json/state       → update state / trigger effect
 *   GET  /json/info        → device info
 *   GET  /json/si          → state + info combined
 *   GET  /json/effects     → array of effect names
 *   GET  /json/palettes    → array of palette names
 *   GET  /json/nodes       → empty nodes list (multi-device not supported)
 *   GET  /win              → WLED HTTP request API (legacy / simple apps)
 *   POST /win              → same
 *
 * State is persisted to STATE_FILE and FPP's overlay API is called to
 * apply changes.  The C++ plugin (loaded by fppd) handles UDP discovery
 * and mDNS — this PHP file only handles HTTP.
 */

// ── Configuration (read from FPP plugin config) ───────────────────────────────

define('STATE_FILE',  '/home/fpp/media/config/wled_proxy_state.json');
define('CONFIG_FILE', '/home/fpp/media/config/plugin.fpp-WLEDProxy.json');
define('FPP_API_BASE', 'http://localhost');   // FPP internal API base URL

// ── Load plugin config ────────────────────────────────────────────────────────

function loadConfig(): array {
    $defaults = [
        'OverlayModelNames'  => ['All Pixels'],
        'LEDCount'           => 300,  // Fallback if not calculated
        'DeviceName'         => 'FPP WLED',
        'EnableUDPDiscovery' => true,
    ];
    if (!file_exists(CONFIG_FILE)) return $defaults;
    $raw = file_get_contents(CONFIG_FILE);
    $cfg = json_decode($raw, true);
    if (!is_array($cfg)) return $defaults;

    // Handle legacy single model name by converting to array
    if (isset($cfg['OverlayModelName']) && !isset($cfg['OverlayModelNames'])) {
        $cfg['OverlayModelNames'] = [$cfg['OverlayModelName']];
        unset($cfg['OverlayModelName']);
    }

    $merged = array_merge($defaults, $cfg);

    // Ensure LEDCount is always set (from config or fallback)
    if (empty($merged['LEDCount']) || $merged['LEDCount'] < 1) {
        $merged['LEDCount'] = 300;
    }

    return $merged;
}

$cfg = loadConfig();

// ── WLED effect and palette name tables ───────────────────────────────────────

$WLED_EFFECTS = [
    'Solid','Blink','Breathe','Wipe','Wipe Random','Random Colors','Sweep',
    'Dynamic','Colorloop','Rainbow','Scan','Scan Dual','Fade','Theater',
    'Theater Rainbow','Running','Saw','Twinkle','Dissolve','Dissolve Rnd',
    'Sparkle','Sparkle Dark','Sparkle+','Strobe','Strobe Rainbow',
    'Strobe Mega','Blink Rainbow','Android','Chase','Chase Random',
    'Chase Rainbow','Chase Flash','Chase Flash Rnd','Rainbow Runner',
    'Colorful','Traffic Light','Sweep Random','Running 2','Red & Blue',
    'Stream','Scanner','Lighthouse','Fireworks','Rain','Merry Christmas',
    'Fire Flicker','Gradient','Loading','Police','Police All','Two Dots',
    'Two Areas','Circus','Halloween','Tri Chase','Tri Wipe','Tri Fade',
    'Lightning','ICU','Multi Comet','Scanner Dual','Stream 2','Oscillate',
    'Pride 2015','Juggle','Palette','Fire 2012','Colorwaves','Bpm',
    'Fill Noise','Noise 1','Noise 2','Noise 3','Noise 4','Colortwinkles',
    'Lake','Meteor','Meteor Smooth','Railway','Ripple','Twinklefox',
    'Twinklecat','Halloween Eyes','Solid Pattern','Solid Pattern Tri',
    'Spots','Spots Fade','Glitter','Candle','Fireworks Starburst',
    'Fireworks 1D','Bouncing Balls','Sinelon','Sinelon Dual',
    'Sinelon Rainbow','Popcorn','Drip','Plasma','Percent',
    'Ripple Rainbow','Heartbeat','Pacifica','Candle Multi','Solid Glitter',
    'Sunrise','Phased','Twinkleup','Noise Pal','Sine','Phased Noise',
    'Flow','Chunchun','Dancing Shadows','Washing Machine','Candy Cane',
    'Blends','TV Simulator','Dynamic Smooth',
];

$WLED_PALETTES = [
    'Default','* Random Cycle','* Color 1','* Colors 1&2','* Color Gradient',
    '* Colors Only','Party','Cloud','Lava','Ocean','Forest','Rainbow',
    'Rainbow Bands','Sunset','Rivendell','Breeze','Red & Blue','Yellowout',
    'Analogous','Splash','Pastel','Sunset 2','Beach','Vintage','Departure',
    'Landscape','Beech','Sherbet','Hult','Hult 64','Drywet','Jul',
    'Grintage','Rewhi','Tertiary','Fire','Icefire','Cyane','Light Pink',
    'Autumn','Magenta','Magred','Yelmag','Yelblu','Orange & Teal',
    'Tiamat','April Night','Orangery','C9','Sakura','Aurora','Atlantica',
    'C9 2','C9 New','Temperature','Aurora 2','Retro Clown','Candy',
    'Toxy Reaf','Fairy Reaf','Semi Blue','Pink Candy','Red Reaf',
    'Aqua Flash','Yelblu Hot','Lite Light','Red Flash','Blink Red',
    'Red Shift','Red Tide','Candy2',
];

// ── State helpers ─────────────────────────────────────────────────────────────

function buildSegments(array $cfg): array {
    // Build WLED segments - one per selected model
    $segments = [];
    $modelNames = $cfg['OverlayModelNames'] ?? ['All Pixels'];
    if (!is_array($modelNames)) $modelNames = [$modelNames];

    $modelPixelCounts = $cfg['ModelPixelCounts'] ?? [];
    $currentPos = 0;

    foreach ($modelNames as $idx => $modelName) {
        $pixelCount = $modelPixelCounts[$modelName] ?? 300;
        $segments[] = [
            'id'    => $idx,
            'start' => $currentPos,
            'stop'  => $currentPos + $pixelCount,
            'len'   => $pixelCount,
            'grp'   => 1,
            'spc'   => 0,
            'fx'    => 0,
            'sx'    => 128,
            'ix'    => 128,
            'pal'   => 0,
            'sel'   => ($idx === 0),
            'rev'   => false,
            'on'    => true,
            'bri'   => 255,
            'col'   => [[255,0,0],[0,0,0],[0,0,0]],
        ];
        $currentPos += $pixelCount;
    }

    return !empty($segments) ? $segments : [[
        'id'  => 0, 'start' => 0, 'stop' => 300, 'len' => 300,
        'grp' => 1, 'spc'   => 0,
        'fx'  => 0, 'sx'    => 128, 'ix'  => 128, 'pal' => 0,
        'sel' => true, 'rev' => false, 'on' => true, 'bri' => 255,
        'col' => [[255,0,0],[0,0,0],[0,0,0]],
    ]];
}

function loadState(): array {
    $cfg = loadConfig();
    $segments = buildSegments($cfg);

    // Calculate total LED count from all segments
    $totalLeds = 0;
    foreach ($segments as $seg) {
        $totalLeds = max($totalLeds, $seg['stop']);
    }

    $default = [
        'on'         => false,
        'bri'        => 255,
        'transition' => 7,
        'ps'         => -1,
        'mainseg'    => 0,
        'seg'        => $segments,
    ];

    if (!file_exists(STATE_FILE)) {
        // No state file - check FPP for actual effect state
        $default['on'] = isAnyEffectRunning($cfg);
        return $default;
    }

    $raw   = file_get_contents(STATE_FILE);
    $state = json_decode($raw, true);
    if (!is_array($state)) return $default;

    // Preserve segments structure from config
    $state['seg'] = $segments;

    // Sync 'on' state with FPP's actual effect status
    $state['on'] = isAnyEffectRunning($cfg);

    return array_replace_recursive($default, $state);
}

/**
 * Check if any of the selected overlay models have effects running in FPP.
 */
function isAnyEffectRunning(array $cfg): bool {
    $modelNames = $cfg['OverlayModelNames'] ?? ['All Pixels'];
    if (!is_array($modelNames)) $modelNames = [$modelNames];

    foreach ($modelNames as $modelName) {
        $encodedName = rawurlencode($modelName);
        // Try to get model state from FPP
        $modelInfo = callFppApi('GET', "/api/overlays/model/{$encodedName}");
        if (is_array($modelInfo) && isset($modelInfo['effectRunning'])) {
            if ($modelInfo['effectRunning']) {
                return true;  // At least one model has an effect running
            }
        }
    }

    return false;  // No effects running on any model
}

function saveState(array $state): void {
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Merge a partial WLED state update into the current state.
 * Handles both top-level fields (on, bri, transition) and segment array.
 */
function mergeState(array $current, array $update): array {
    foreach (['on', 'bri', 'transition', 'ps', 'mainseg'] as $key) {
        if (array_key_exists($key, $update)) {
            $current[$key] = $update[$key];
        }
    }

    // 'on' can be true/false/string "t" (toggle)
    if (isset($update['on']) && $update['on'] === 't') {
        $current['on'] = !$current['on'];
    }

    // Segment updates
    if (!empty($update['seg']) && is_array($update['seg'])) {
        foreach ($update['seg'] as $segUpdate) {
            $sid = $segUpdate['id'] ?? 0;
            if ($sid === 0) {
                foreach (['fx','sx','ix','pal','sel','rev','on','bri','col','start','stop','len'] as $k) {
                    if (array_key_exists($k, $segUpdate)) {
                        $current['seg'][0][$k] = $segUpdate[$k];
                    }
                }
                // Recalculate len from start/stop if both changed
                if (isset($current['seg'][0]['start'], $current['seg'][0]['stop'])) {
                    $current['seg'][0]['len'] = $current['seg'][0]['stop'] - $current['seg'][0]['start'];
                }
            }
        }
    }

    return $current;
}

// ── FPP API interaction ────────────────────────────────────────────────────────

/**
 * Call FPP's internal REST API via cURL.
 *
 * @param string $method  HTTP method (GET, POST)
 * @param string $path    API path (e.g. /api/overlays/model/All%20Pixels/state)
 * @param array  $body    Associative array to send as JSON body (POST only)
 * @return array|null     Decoded JSON response, or null on failure
 */
function callFppApi(string $method, string $path, array $body = []): ?array {
    $url = FPP_API_BASE . $path;
    $ch  = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code >= 400) return null;
    return json_decode($resp, true);
}

/**
 * Apply the current WLED state to FPP's pixel overlay models.
 *
 * FPP Overlay Model API paths (FPP 6.x – 9.x):
 *   POST /api/overlays/model/{name}/state       — enable/disable model
 *   POST /api/overlays/model/{name}/effect/start — start animated effect
 *   POST /api/overlays/model/{name}/effect/stop  — stop effect
 *   POST /api/overlays/model/{name}/fill         — fill with solid color
 *
 * NOTE: URL-encode the model name for spaces/special characters.
 */
function applyStateToFPP(array $state, array $cfg, array $effects, array $palettes): bool {
    $modelNames = $cfg['OverlayModelNames'] ?? ['All Pixels'];
    if (!is_array($modelNames)) $modelNames = [$modelNames];
    if (empty($modelNames)) $modelNames = ['All Pixels'];

    $on         = (bool)($state['on'] ?? true);
    $globalBri  = (int)($state['bri'] ?? 255);
    $seg        = $state['seg'][0] ?? [];
    $segOn      = (bool)($seg['on'] ?? true);
    $segBri     = (int)($seg['bri'] ?? 255);
    $fx         = (int)($seg['fx']  ?? 0);
    $sx         = (int)($seg['sx']  ?? 128);
    $ix         = (int)($seg['ix']  ?? 128);
    $pal        = (int)($seg['pal'] ?? 0);
    $col        = $seg['col'] ?? [[255,0,0],[0,0,0],[0,0,0]];

    // Effective brightness (0–255)
    $effectiveBri = (int)round($globalBri * $segBri / 255);

    // ── Scale colours by effective brightness ─────────────────────────────────
    function scaledColor(array $rgb, int $bri): array {
        return [
            (int)round($rgb[0] * $bri / 255),
            (int)round($rgb[1] * $bri / 255),
            (int)round($rgb[2] * $bri / 255),
        ];
    }

    $c1 = scaledColor($col[0] ?? [255,0,0], $effectiveBri);
    $c2 = scaledColor($col[1] ?? [0,0,0],   $effectiveBri);
    $c3 = scaledColor($col[2] ?? [0,0,0],   $effectiveBri);

    $colorHex = fn(array $rgb): string =>
        sprintf('%02X%02X%02X', $rgb[0], $rgb[1], $rgb[2]);

    // Apply to all selected models
    $success = true;
    foreach ($modelNames as $modelName) {
        $modelName = trim($modelName);
        if (empty($modelName)) continue;
        $encodedName = rawurlencode($modelName);

        // ── Step 1: Enable the overlay model ───────────────────────────────────
        callFppApi('POST', "/api/overlays/model/{$encodedName}/state", ['State' => 1]);

        // ── Step 2: Handle off / zero-brightness state ─────────────────────────
        if (!$on || !$segOn || $effectiveBri === 0) {
            callFppApi('POST', "/api/overlays/model/{$encodedName}/effect/stop");
            callFppApi('POST', "/api/overlays/model/{$encodedName}/fill",
                       ['r' => 0, 'g' => 0, 'b' => 0]);
            continue;
        }

        // ── Step 3: Solid color (effect 0) ─────────────────────────────────────
        if ($fx === 0) {
            callFppApi('POST', "/api/overlays/model/{$encodedName}/effect/stop");
            $success = (bool)callFppApi('POST', "/api/overlays/model/{$encodedName}/fill",
                ['r' => $c1[0], 'g' => $c1[1], 'b' => $c1[2]]) && $success;
            continue;
        }

        // ── Step 4: Animated WLED effect ───────────────────────────────────────
        $effectName  = $effects[$fx]  ?? 'Solid';
        $paletteName = $palettes[$pal] ?? 'Default';

        $success = (bool)callFppApi('POST', "/api/overlays/model/{$encodedName}/effect/start", [
            'effectType'            => 'WLED',
            'effectName'            => $effectName,
            'speed'                 => $sx,
            'intensity'             => $ix,
            'palette'               => $paletteName,
            'color1'                => $colorHex($c1),
            'color2'                => $colorHex($c2),
            'color3'                => $colorHex($c3),
            'autoResetAfterTimeout' => false,
        ]) && $success;
    }

    return $success;
}

// ── Info JSON builder ─────────────────────────────────────────────────────────

function buildInfoJson(array $cfg, array $state, int $fxcount, int $palcount): array {
    // Retrieve MAC address (best-effort)
    $mac = 'AA:BB:CC:DD:EE:FF';
    if (is_readable('/sys/class/net/eth0/address')) {
        $mac = strtoupper(trim(file_get_contents('/sys/class/net/eth0/address')));
    } elseif (is_readable('/sys/class/net/wlan0/address')) {
        $mac = strtoupper(trim(file_get_contents('/sys/class/net/wlan0/address')));
    }

    // Calculate total LED count from segments
    $totalLeds = 0;
    foreach ($state['seg'] ?? [] as $seg) {
        $totalLeds = max($totalLeds, $seg['stop'] ?? 0);
    }
    if ($totalLeds === 0) $totalLeds = 300; // Fallback

    return [
        'ver'      => '0.14.0',
        'vid'      => 2110050,
        'leds'     => [
            'count'  => $totalLeds,
            'rgbw'   => false,
            'wleds'  => 0,
            'fps'    => 30,
            'pwr'    => 0,
            'maxpwr' => 0,
            'maxseg' => count($state['seg'] ?? []),
        ],
        'str'      => false,
        'name'     => $cfg['DeviceName'],
        'udpport'  => 21324,
        'live'     => false,
        'liveseg'  => -1,
        'liveip'   => '',
        'ws'       => 0,
        'fxcount'  => $fxcount,
        'palcount' => $palcount,
        'wifi'     => ['bssid' => '', 'rssi' => -50, 'signal' => 100, 'channel' => 0],
        'arch'     => 'esp32',
        'core'     => 'fpp',
        'lwip'     => 0,
        'mac'      => $mac,
        'ip'       => $_SERVER['SERVER_ADDR'] ?? '',
    ];
}

// ── Request routing ───────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Apache rewrite passes the original path as _path query parameter
// This ensures we always have the correct path even after rewrites
// e.g. GET /json/info → _path="/json/info"
$requestPath = $_GET['_path'] ?? ($_SERVER['REQUEST_URI'] ?? '/json');
$path       = parse_url($requestPath, PHP_URL_PATH) ?? '/json';
$path       = rtrim($path, '/') ?: '/json';

// DEBUG: Log request info
error_log("[WLED] REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? 'NULL') . 
          ", _path=" . ($_GET['_path'] ?? 'NULL') .
          ", final path=" . $path);
// Set JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle CORS preflight
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ── Parse request body (for POST) ─────────────────────────────────────────────
$inputBody = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $inputBody = $parsed;
        }
    }
}

// ── Route handling ────────────────────────────────────────────────────────────

// GET /win  OR  POST /win  — WLED HTTP Request API (legacy, simple apps)
if ($path === '/win') {
    $state = loadState();

    // Parse query parameters (GET) or body (POST) for /win
    $params = array_merge($_GET, $inputBody);

    // 'on' / 'off' / 'toggle'
    if (isset($params['on']))  $state['on'] = true;
    if (isset($params['off'])) $state['on'] = false;
    if (isset($params['T']))   $state['on'] = !$state['on'];

    // 'bri' — global brightness (0-255)
    if (isset($params['bri'])) {
        $state['bri'] = max(0, min(255, (int)$params['bri']));
    }
    // 'A' — same as 'bri' in WLED HTTP API
    if (isset($params['A'])) {
        $state['bri'] = max(0, min(255, (int)$params['A']));
    }

    // 'fx' — effect index
    if (isset($params['fx'])) {
        $state['seg'][0]['fx'] = max(0, min(count($WLED_EFFECTS) - 1, (int)$params['fx']));
    }
    // 'FX' — same
    if (isset($params['FX'])) {
        $state['seg'][0]['fx'] = max(0, min(count($WLED_EFFECTS) - 1, (int)$params['FX']));
    }

    // 'sx' — speed
    if (isset($params['sx'])) $state['seg'][0]['sx'] = max(0, min(255, (int)$params['sx']));
    if (isset($params['SX'])) $state['seg'][0]['sx'] = max(0, min(255, (int)$params['SX']));

    // 'ix' — intensity
    if (isset($params['ix'])) $state['seg'][0]['ix'] = max(0, min(255, (int)$params['ix']));
    if (isset($params['IX'])) $state['seg'][0]['ix'] = max(0, min(255, (int)$params['IX']));

    // 'pal' — palette index
    if (isset($params['pal'])) $state['seg'][0]['pal'] = max(0, min(count($WLED_PALETTES) - 1, (int)$params['pal']));
    if (isset($params['FP']))  $state['seg'][0]['pal'] = max(0, min(count($WLED_PALETTES) - 1, (int)$params['FP']));

    // 'col' — primary color in hex (e.g. col=FF0000)
    if (isset($params['col'])) {
        $hex = ltrim($params['col'], '#');
        if (strlen($hex) === 6) {
            $state['seg'][0]['col'][0] = [
                hexdec(substr($hex, 0, 2)),
                hexdec(substr($hex, 2, 2)),
                hexdec(substr($hex, 4, 2)),
            ];
        }
    }
    // 'R','G','B' — individual channel override
    if (isset($params['R']) || isset($params['G']) || isset($params['B'])) {
        $state['seg'][0]['col'][0] = [
            isset($params['R']) ? max(0, min(255, (int)$params['R'])) : ($state['seg'][0]['col'][0][0] ?? 255),
            isset($params['G']) ? max(0, min(255, (int)$params['G'])) : ($state['seg'][0]['col'][0][1] ?? 0),
            isset($params['B']) ? max(0, min(255, (int)$params['B'])) : ($state['seg'][0]['col'][0][2] ?? 0),
        ];
    }

    // 'tt' — transition time (in 100ms increments)
    if (isset($params['tt'])) $state['transition'] = max(0, (int)$params['tt']);

    saveState($state);
    applyStateToFPP($state, $cfg, $WLED_EFFECTS, $WLED_PALETTES);

    // /win returns a plain-text "OK" like real WLED
    header('Content-Type: text/plain');
    echo 'OK';
    exit;
}

// All /json/* routes ──────────────────────────────────────────────────────────

$state = loadState();

// GET /json/effects
if ($path === '/json/effects' || $path === '/json/eff') {
    echo json_encode($WLED_EFFECTS);
    exit;
}

// GET /json/palettes
if ($path === '/json/palettes' || $path === '/json/pal') {
    echo json_encode($WLED_PALETTES);
    exit;
}

// GET /json/nodes — multi-device support (not implemented)
if ($path === '/json/nodes') {
    echo json_encode(['nodes' => []]);
    exit;
}

// GET /json/info
if ($path === '/json/info') {
    $state = loadState();
    echo json_encode(buildInfoJson($cfg, $state, count($WLED_EFFECTS), count($WLED_PALETTES)));
    exit;
}

// POST /json/state — update state
if ($path === '/json/state' && $method === 'POST') {
    if (empty($inputBody)) {
        http_response_code(400);
        echo json_encode(['error' => 'No valid JSON body']);
        exit;
    }

    $state = mergeState($state, $inputBody);
    saveState($state);
    applyStateToFPP($state, $cfg, $WLED_EFFECTS, $WLED_PALETTES);

    // WLED returns the new state with v:true if requested
    $response = $state;
    if (!empty($inputBody['v'])) {
        // Include info in the response
        $response['info'] = buildInfoJson($cfg, $state, count($WLED_EFFECTS), count($WLED_PALETTES));
    }
    echo json_encode($response);
    exit;
}

// GET /json/state
if ($path === '/json/state') {
    echo json_encode($state);
    exit;
}

// GET/POST /json/si — state + info combined
if ($path === '/json/si') {
    if ($method === 'POST' && !empty($inputBody)) {
        $state = mergeState($state, $inputBody);
        saveState($state);
        applyStateToFPP($state, $cfg, $WLED_EFFECTS, $WLED_PALETTES);
    }
    echo json_encode([
        'state' => $state,
        'info'  => buildInfoJson($cfg, $state, count($WLED_EFFECTS), count($WLED_PALETTES)),
    ]);
    exit;
}

// GET /json — combined state + info (default WLED response)
if ($path === '/json' || str_starts_with($path, '/json')) {
    if ($method === 'POST' && !empty($inputBody)) {
        $state = mergeState($state, $inputBody);
        saveState($state);
        applyStateToFPP($state, $cfg, $WLED_EFFECTS, $WLED_PALETTES);
    }

    echo json_encode([
        'state'    => $state,
        'info'     => buildInfoJson($cfg, $state, count($WLED_EFFECTS), count($WLED_PALETTES)),
        'effects'  => $WLED_EFFECTS,
        'palettes' => $WLED_PALETTES,
    ]);
    exit;
}

// ── Fallback ──────────────────────────────────────────────────────────────────
error_log("[WLED 404] No route matched path='$path' (REQUEST_URI=" . $_SERVER['REQUEST_URI'] . ")");
http_response_code(404);
echo json_encode(['error' => 'Unknown WLED API path: ' . $path]);
