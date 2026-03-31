# Home Assistant Integration Debugging Guide

## Problem
Home Assistant's WLED integration returns 404 errors when trying to connect to the FPP WLED API Proxy, even though:
- Direct curl requests work fine
- The PHP server is running and responding to requests
- Individual endpoints are accessible

## Diagnostic Steps

### 1. Test All Endpoints Locally

Run the endpoint testing script to verify all WLED API endpoints are working:

```bash
./test_endpoints.sh localhost:9000
```

This will test all core endpoints and show which ones are responding correctly.

### 2. Check Server Logs in Real-Time

While attempting to connect from Home Assistant, monitor the server logs:

```bash
# On the FPP host, watch the systemd service logs
ssh pi@<fpp-ip>
sudo journalctl -u fpp-wled-proxy -f
```

This will show:
- Exact request paths being made
- HTTP methods
- Response codes
- Error messages

### 3. Enable Verbose Logging

Edit `/home/fpp/media/plugins/fpp-WLEDProxy/www/wled_api.php` and check the debug logging section (around line 434):

```php
error_log("[WLED] REQUEST_URI=" . ($_SERVER['REQUEST_URI'] ?? 'NULL') .
          ", _path=" . ($_GET['_path'] ?? 'NULL') .
          ", final path=" . $path);
```

This logs all requests with detailed path information.

### 4. Test with curl from FPP Host

SSH into the FPP host and test the API locally:

```bash
curl -v http://localhost:9000/json
curl -v http://localhost:9000/json/state
curl -v http://localhost:9000/json/info
curl -v http://localhost:9000/json/fxdata
```

Each should return 200 status with JSON body.

### 5. Test from Network (Like Home Assistant Would)

If FPP's IP is 192.168.1.154:

```bash
curl -v http://192.168.1.154:9000/json
curl -v http://192.168.1.154:9000/json/state
curl -v http://192.168.1.154:9000/json/effects
```

If these work but Home Assistant doesn't, the issue might be:
- Home Assistant using a different path or method
- Home Assistant expecting additional response fields
- Network routing or firewall issue specific to Home Assistant's library

### 6. Check Apache Proxy (if Port 80 is Used)

If Home Assistant is accessing via port 80 (not 9000), verify Apache proxy rules are in place:

```bash
sudo grep -A 5 "WLED\|:9000" /etc/apache2/sites-available/000-default.conf
```

Should show rewrite rules like:
```apache
RewriteRule ^/json(.*)$ http://localhost:9000/json$1 [P,QSA,L]
RewriteRule ^/win(.*)$ http://localhost:9000/win$1 [P,QSA,L]
```

### 7. Test Home Assistant Library Directly

Create a test Python script to debug what the Home Assistant library is looking for:

```python
import asyncio
from wled import WLED

async def test():
    async with WLED("192.168.1.154", port=9000) as wled:
        try:
            info = await wled.async_get_info()
            print("INFO:", info)
        except Exception as e:
            print("ERROR:", e)
            print("ERROR TYPE:", type(e))

asyncio.run(test())
```

The error message will show exactly which endpoint is failing.

## Common Issues & Solutions

### Issue: 404 on specific endpoints only

**Possible cause:** Missing endpoint handler in wled_api.php
**Solution:** Check the routing section (line 462+) to verify the endpoint is listed

### Issue: 404 on all endpoints

**Possible cause:** Router is not routing requests to wled_api.php
**Solution:** Check router.php is being used by systemd service

### Issue: Curl works, Home Assistant doesn't

**Possible cause:** Home Assistant library making different requests or expecting different response format
**Solution:** Enable detailed logging and compare curl vs. Home Assistant requests

### Issue: Port 80 not working but port 9000 works

**Possible cause:** Apache proxy not configured or not reloading
**Solution:**
```bash
sudo a2enmod rewrite  # Enable mod_rewrite if not enabled
sudo apache2ctl configtest  # Check config syntax
sudo systemctl reload apache2
```

## Response Format Verification

Verify responses match WLED format with:

```bash
curl http://192.168.1.154:9000/json | jq . | head -20
```

Should include fields like:
- `state` (object with on, bri, seg, etc.)
- `info` (object with name, leds, version, etc.)
- `effects` (array of effect names)
- `palettes` (array of palette names)

## Next Steps

1. Run `./test_endpoints.sh` to verify all endpoints return 200
2. Check `journalctl -u fpp-wled-proxy -f` logs while Home Assistant connects
3. Compare the request path/method in logs with what endpoint handler is expecting
4. Identify the specific endpoint that's returning 404
5. Check if that endpoint is implemented in wled_api.php
6. If missing, add it or check for typos in the handler condition
