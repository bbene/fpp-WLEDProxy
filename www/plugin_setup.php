<?php
/**
 * FPP WLED API Proxy — Plugin Settings Page
 *
 * Content block included by FPP's plugin.php wrapper.
 * Accessed via: /plugin.php?plugin=fpp-WLEDProxy&page=plugin_setup.php
 */

// Redirect to wrapper if accessed directly (not included by plugin.php)
// Check for FPP's common.php which would have been required by plugin.php
if (!function_exists('LoadPluginSettings')) {
    header('Location: /plugin.php?plugin=fpp-WLEDProxy&page=plugin_setup.php', true, 302);
    exit;
}

define('CONFIG_FILE', '/home/fpp/media/config/plugin.fpp-WLEDProxy.json');

// ── Load config ───────────────────────────────────────────────────────────────
$defaults = [
    'OverlayModelNames'  => ['All Pixels'],
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
    // Handle multiple model selection (array of checkboxes)
    $selectedModels = [];
    if (!empty($_POST['OverlayModelNames']) && is_array($_POST['OverlayModelNames'])) {
        $selectedModels = array_map('trim', $_POST['OverlayModelNames']);
        $selectedModels = array_filter($selectedModels);
    }
    $cfg['OverlayModelNames']  = !empty($selectedModels) ? $selectedModels : $defaults['OverlayModelNames'];
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

<p>Makes FPP discoverable and controllable by any WLED-compatible app
   (Lightbow, WLED Wiz, Home Assistant, etc.) by emulating the WLED JSON API.</p>

<?php if ($saved): ?>
<div class="alert-ok">✅ Settings saved. fppd reload requested.</div>
<?php elseif ($saveErr): ?>
<div class="alert-err">❌ <?= htmlspecialchars($saveErr) ?></div>
<?php endif; ?>

<!-- ── Settings form ─────────────────────────────────────────────────────── -->
<div class="row"><div class="col-lg-6">
    <h2>Plugin Settings</h2>
    <form method="POST">
        <input type="hidden" name="action" value="save">

        <div class="mb-3">
        <label class="form-label">Pixel Overlay Models (select one or more)</label>
        <?php if (!empty($overlayModels)): ?>
        <div style="border: 1px solid #ddd; padding: 10px; border-radius: 4px; max-height: 250px; overflow-y: auto;">
            <?php foreach ($overlayModels as $model): ?>
            <div style="margin: 0; padding: 0; margin-bottom: 8px;">
                <input type="checkbox" id="model_<?= htmlspecialchars($model) ?>"
                       name="OverlayModelNames[]" value="<?= htmlspecialchars($model) ?>"
                       class="form-check-input" style="margin-right: 8px;"
                       <?= in_array($model, $cfg['OverlayModelNames'] ?? []) ? 'checked' : '' ?>>
                <label class="form-check-label" for="model_<?= htmlspecialchars($model) ?>" style="display: inline; margin: 0;">
                    <?= htmlspecialchars($model) ?>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
        <small class="form-text d-block mt-2">WLED effects will be applied to all selected models simultaneously.
               Create models in <em>Content Setup → Pixel Overlay Models</em>.</small>
        <?php else: ?>
        <input type="text" id="OverlayModelNames" name="OverlayModelNames[]" class="form-control"
               value="<?= htmlspecialchars($cfg['OverlayModelNames'][0] ?? '') ?>"
               placeholder="Model name (no models fetched from FPP API)">
        <small class="form-text">Could not fetch models from FPP API. Enter manually if needed.</small>
        <?php endif; ?>
        </div>

        <div class="mb-3">
        <label for="LEDCount" class="form-label">LED / Pixel Count</label>
        <input type="number" id="LEDCount" name="LEDCount" class="form-control"
               value="<?= (int)$cfg['LEDCount'] ?>" min="1" max="16384">
        <small class="form-text">Number of pixels in the overlay model. Reported to WLED apps
               so they can display segment sliders correctly.</small>
        </div>

        <div class="mb-3">
        <label for="DeviceName" class="form-label">Device Name (shown in WLED apps)</label>
        <input type="text" id="DeviceName" name="DeviceName" class="form-control"
               value="<?= htmlspecialchars($cfg['DeviceName']) ?>" maxlength="32">
        <small class="form-text">Name advertised via mDNS and returned in <code>/json/info</code>.</small>
        </div>

        <div class="mb-3 form-check">
            <input type="checkbox" id="EnableUDPDiscovery" name="EnableUDPDiscovery" class="form-check-input"
                   <?= $cfg['EnableUDPDiscovery'] ? 'checked' : '' ?>>
            <label for="EnableUDPDiscovery" class="form-check-label">
                Enable UDP discovery (port 21324)</label>
            <small class="form-text d-block mt-2">
                Allows WLED apps that use UDP broadcast to find FPP automatically.
                Requires the C++ plugin to be compiled and loaded by fppd.
            </small>
        </div>

        <button type="submit" class="btn btn-primary">💾 Save Settings</button>
    </form>
</div></div>

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
