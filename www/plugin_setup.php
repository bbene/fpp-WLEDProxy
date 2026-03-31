<?php
/**
 * FPP WLED API Proxy — Plugin Settings Page
 *
 * Content block included by FPP's plugin.php wrapper.
 * Accessed via: /plugin.php?plugin=fpp-WLEDProxy&page=plugin_setup.php
 */

define('CONFIG_FILE', '/home/fpp/media/config/plugin.fpp-WLEDProxy.json');

// ── Load config ───────────────────────────────────────────────────────────────
$defaults = [
    'OverlayModelName'   => 'All Pixels',
    'LEDCount'           => 300,
    'DeviceName'         => 'FPP WLED',
    'EnableUDPDiscovery' => true,
];
$cfg = $defaults;
if (file_exists(CONFIG_FILE)) {
    $raw = file_get_contents(CONFIG_FILE);
    $loaded = json_decode($raw, true);
    if (is_array($loaded)) $cfg = array_merge($defaults, $loaded);
}

// ── Handle save ───────────────────────────────────────────────────────────────
$saved   = false;
$saveErr = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $cfg['OverlayModelName']   = trim($_POST['OverlayModelName'] ?? $defaults['OverlayModelName']);
    $cfg['LEDCount']           = max(1, (int)($_POST['LEDCount'] ?? $defaults['LEDCount']));
    $cfg['DeviceName']         = trim($_POST['DeviceName'] ?? $defaults['DeviceName']);
    $cfg['EnableUDPDiscovery'] = isset($_POST['EnableUDPDiscovery']);

    if (file_put_contents(CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT)) !== false) {
        $saved = true;
        // Restart fppd is ideal; at minimum refresh state
        exec('sudo systemctl try-reload-or-restart fppd 2>/dev/null &');
    } else {
        $saveErr = 'Could not write config file: ' . CONFIG_FILE;
    }
}

// ── Fetch available Pixel Overlay Models from FPP API ─────────────────────────
$overlayModels = [];
$modelsRaw = @file_get_contents('http://localhost/api/overlays/models');
if ($modelsRaw !== false) {
    $modelsData = json_decode($modelsRaw, true);
    if (is_array($modelsData)) {
        // FPP returns an array of model objects or just names depending on version
        foreach ($modelsData as $m) {
            if (is_string($m)) {
                $overlayModels[] = $m;
            } elseif (is_array($m) && isset($m['Name'])) {
                $overlayModels[] = $m['Name'];
            }
        }
    }
}

// ── Current WLED state summary ─────────────────────────────────────────────────
$stateFile = '/home/fpp/media/config/wled_proxy_state.json';
$state = null;
if (file_exists($stateFile)) {
    $state = json_decode(file_get_contents($stateFile), true);
}

?>

<h1>🌈 FPP WLED API Proxy</h1>
<p>Makes FPP discoverable and controllable by any WLED-compatible app
   (Lightbow, WLED Wiz, Home Assistant, etc.) by emulating the WLED JSON API.</p>

<?php if ($saved): ?>
<div class="alert-ok">✅ Settings saved. fppd reload requested.</div>
<?php elseif ($saveErr): ?>
<div class="alert-err">❌ <?= htmlspecialchars($saveErr) ?></div>
<?php endif; ?>

<!-- ── Settings form ─────────────────────────────────────────────────────── -->
<div class="card">
    <h2>Plugin Settings</h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">

        <label for="OverlayModelName">Pixel Overlay Model</label>
        <?php if (!empty($overlayModels)): ?>
        <select id="OverlayModelName" name="OverlayModelName">
            <?php foreach ($overlayModels as $model): ?>
            <option value="<?= htmlspecialchars($model) ?>"
                <?= $model === $cfg['OverlayModelName'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($model) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" id="OverlayModelName" name="OverlayModelName"
               value="<?= htmlspecialchars($cfg['OverlayModelName']) ?>">
        <?php endif; ?>
        <small>The FPP Pixel Overlay Model that WLED effects will be applied to.
               Create models in <em>Content Setup → Pixel Overlay Models</em>.</small>

        <label for="LEDCount">LED / Pixel Count</label>
        <input type="number" id="LEDCount" name="LEDCount"
               value="<?= (int)$cfg['LEDCount'] ?>" min="1" max="16384">
        <small>Number of pixels in the overlay model. Reported to WLED apps
               so they can display segment sliders correctly.</small>

        <label for="DeviceName">Device Name (shown in WLED apps)</label>
        <input type="text" id="DeviceName" name="DeviceName"
               value="<?= htmlspecialchars($cfg['DeviceName']) ?>" maxlength="32">
        <small>Name advertised via mDNS and returned in <code>/json/info</code>.</small>

        <div class="checkbox-row">
            <input type="checkbox" id="EnableUDPDiscovery" name="EnableUDPDiscovery"
                   <?= $cfg['EnableUDPDiscovery'] ? 'checked' : '' ?>>
            <label for="EnableUDPDiscovery" style="margin:0">
                Enable UDP discovery (port 21324)</label>
        </div>
        <small style="margin-left:24px">
            Allows WLED apps that use UDP broadcast to find FPP automatically.
            Requires the C++ plugin to be compiled and loaded by fppd.
        </small>

        <br>
        <input type="submit" value="💾 Save Settings">
    </form>
</div>

<!-- ── Current state ─────────────────────────────────────────────────────── -->
<div class="card">
    <h2>Current WLED State</h2>
    <?php if ($state): ?>
    <div class="state-row">
        <span class="state-badge <?= $state['on'] ? 'on' : 'off' ?>">
            <?= $state['on'] ? '● ON' : '○ OFF' ?>
        </span>
        <span class="state-badge">Brightness: <?= (int)($state['bri'] ?? 255) ?>/255</span>
        <?php
        $WLED_EFFECTS = json_decode(file_get_contents(__DIR__ . '/data/effects.json') ?: '[]', true) ?? [];
        $fx = (int)($state['seg'][0]['fx'] ?? 0);
        $fxName = $WLED_EFFECTS[$fx] ?? "Effect #{$fx}";
        ?>
        <span class="state-badge">Effect: <?= htmlspecialchars($fxName) ?> (#<?= $fx ?>)</span>
        <span class="state-badge">Speed: <?= (int)($state['seg'][0]['sx'] ?? 128) ?></span>
        <span class="state-badge">Intensity: <?= (int)($state['seg'][0]['ix'] ?? 128) ?></span>
    </div>
    <?php else: ?>
    <p><em>No state file found. The plugin will create one on first use.</em></p>
    <?php endif; ?>
</div>

<!-- ── API reference ──────────────────────────────────────────────────────── -->
<div class="card">
    <h2>Available WLED API Endpoints</h2>
    <table>
        <tr><th>Method</th><th>Path</th><th>Description</th></tr>
        <tr><td>GET</td>  <td><code>/json</code></td>           <td>Full state + info + effects + palettes</td></tr>
        <tr><td>GET</td>  <td><code>/json/state</code></td>     <td>Current WLED state</td></tr>
        <tr><td>POST</td> <td><code>/json/state</code></td>     <td>Update state / trigger effect</td></tr>
        <tr><td>GET</td>  <td><code>/json/info</code></td>      <td>Device info (name, LED count, MAC…)</td></tr>
        <tr><td>GET</td>  <td><code>/json/si</code></td>        <td>State + info combined</td></tr>
        <tr><td>GET</td>  <td><code>/json/effects</code></td>   <td>Array of 118 effect names</td></tr>
        <tr><td>GET</td>  <td><code>/json/palettes</code></td>  <td>Array of 71 palette names</td></tr>
        <tr><td>GET/POST</td><td><code>/win</code></td>         <td>WLED HTTP Request API (legacy)</td></tr>
    </table>
    <p style="margin-top:10px;font-size:0.9em">
        Internal fppd routes (for automation):
        <code>/fpp/api/plugin/fpp-WLEDProxy/state</code>,
        <code>/fpp/api/plugin/fpp-WLEDProxy/info</code>,
        <code>/fpp/api/plugin/fpp-WLEDProxy/effects</code>,
        <code>/fpp/api/plugin/fpp-WLEDProxy/palettes</code>
    </p>
</div>

<!-- ── Discovery status ───────────────────────────────────────────────────── -->
<div class="card">
    <h2>Discovery</h2>
    <p>
        <strong>mDNS (Bonjour):</strong>
        FPP is advertised as <code><?= htmlspecialchars($cfg['DeviceName']) ?></code>
        on service type <code>_wled._tcp</code> port <code>9000</code>.
        WLED apps that use mDNS discovery should find FPP automatically
        without any manual IP entry.
    </p>
    <p>
        <strong>UDP (port 21324):</strong>
        <?= $cfg['EnableUDPDiscovery'] ? '✅ Enabled (C++ plugin must be loaded by fppd)' : '⬜ Disabled' ?>
    </p>
    <p>
        <strong>Manual:</strong> Point any WLED app at
        <code>http://<?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'fpp.local') ?>:9000/</code>
        (port 9000, no path prefix needed).
    </p>
</div>
