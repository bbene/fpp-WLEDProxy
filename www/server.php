<?php
/**
 * FPP WLED API Proxy — Standalone HTTP Server
 *
 * Simple PHP HTTP server that listens on port 9000 and routes all requests
 * to wled_api.php for WLED API handling.
 *
 * Runs as a systemd service (fpp-wled-proxy.service).
 * Start:  systemctl start fpp-wled-proxy
 * Stop:   systemctl stop fpp-wled-proxy
 * Status: systemctl status fpp-wled-proxy
 *
 * Log:    journalctl -u fpp-wled-proxy -f
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Configuration
$PORT = 9000;
$HOST = '0.0.0.0';
$MAX_CLIENTS = 10;

// Get the plugin directory
$pluginDir = dirname(__DIR__);
$apiHandler = $pluginDir . '/www/wled_api.php';

if (!file_exists($apiHandler)) {
    fwrite(STDERR, "ERROR: wled_api.php not found at {$apiHandler}\n");
    exit(1);
}

// Create and bind socket
$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if (!$socket) {
    fwrite(STDERR, "ERROR: Failed to create socket: " . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}

socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);

if (!socket_bind($socket, $HOST, $PORT)) {
    fwrite(STDERR, "ERROR: Failed to bind to {$HOST}:{$PORT}: " . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}

if (!socket_listen($socket, $MAX_CLIENTS)) {
    fwrite(STDERR, "ERROR: Failed to listen: " . socket_strerror(socket_last_error()) . "\n");
    exit(1);
}

fwrite(STDOUT, "WLED API Server listening on {$HOST}:{$PORT}\n");
fwrite(STDOUT, "Logging to syslog (journalctl -u fpp-wled-proxy -f)\n");
fwrite(STDOUT, "Press Ctrl+C to stop\n");

// Handle signals gracefully
$shutdown = false;
pcntl_signal(SIGTERM, function() use (&$shutdown) { $shutdown = true; });
pcntl_signal(SIGINT, function() use (&$shutdown) { $shutdown = true; });

// Main server loop
while (!$shutdown) {
    // Accept connection with timeout
    socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    $client = @socket_accept($socket);

    if ($client === false) {
        pcntl_signal_dispatch();
        continue;
    }

    // Read HTTP request
    $request = '';
    while (($chunk = @socket_read($client, 2048, PHP_NORMAL_READ)) && $chunk !== '') {
        $request .= $chunk;
        if (strpos($request, "\r\n\r\n") !== false) break;
    }

    if (!$request) {
        socket_close($client);
        continue;
    }

    // Parse request line
    $lines = explode("\r\n", $request);
    $requestLine = $lines[0];
    $parts = explode(' ', $requestLine);

    if (count($parts) < 3) {
        socket_write($client, "HTTP/1.1 400 Bad Request\r\n\r\n");
        socket_close($client);
        continue;
    }

    $method = $parts[0];
    $uri = $parts[1];
    $version = $parts[2];

    // Parse headers
    $headers = [];
    for ($i = 1; $i < count($lines) && $lines[$i] !== ''; $i++) {
        if (strpos($lines[$i], ':') !== false) {
            [$key, $val] = explode(':', $lines[$i], 2);
            $headers[trim(strtolower($key))] = trim($val);
        }
    }

    // Parse URL
    $url_parts = parse_url($uri);
    $path = $url_parts['path'] ?? '/';
    $query_string = $url_parts['query'] ?? '';

    // Extract POST body if present
    $body = '';
    if ($method === 'POST' && isset($headers['content-length'])) {
        $bodyLen = (int)$headers['content-length'];
        $bodyLen = min($bodyLen, 65536); // Limit to 64KB
        $body = @socket_read($client, $bodyLen, PHP_BINARY_READ);
    }

    // Set up PHP environment to emulate HTTP request
    $_SERVER = [
        'REQUEST_METHOD'  => $method,
        'REQUEST_URI'     => $path . ($query_string ? "?{$query_string}" : ''),
        'SCRIPT_NAME'     => '/wled_api.php',
        'SCRIPT_FILENAME' => $apiHandler,
        'QUERY_STRING'    => $query_string,
        'SERVER_NAME'     => gethostname(),
        'SERVER_ADDR'     => $HOST,
        'SERVER_PORT'     => $PORT,
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'HTTP_HOST'       => $headers['host'] ?? (gethostname() . ':' . $PORT),
        'REMOTE_ADDR'     => '127.0.0.1',
        'REMOTE_PORT'     => 0,
    ];

    // Add other headers to $_SERVER
    foreach ($headers as $key => $value) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        if (!isset($_SERVER[$key])) {
            $_SERVER[$key] = $value;
        }
    }

    // Parse query string into $_GET
    $_GET = [];
    if ($query_string) {
        parse_str($query_string, $_GET);
    }

    // Parse POST data into $_POST
    $_POST = [];
    $_REQUEST = [];
    if ($method === 'POST') {
        if (isset($headers['content-type'])) {
            $contentType = explode(';', $headers['content-type'])[0];
            if ($contentType === 'application/x-www-form-urlencoded') {
                parse_str($body, $_POST);
            } elseif ($contentType === 'application/json') {
                $_POST = json_decode($body, true) ?? [];
            }
        }
        $_REQUEST = array_merge($_GET, $_POST);
    } else {
        $_REQUEST = $_GET;
    }

    // Capture output
    ob_start();
    $statusCode = 200;

    try {
        // Include and execute the API handler
        include $apiHandler;
        $statusCode = http_response_code() ?? 200;
    } catch (Exception $e) {
        $statusCode = 500;
        echo json_encode(['error' => $e->getMessage()]);
    }

    $output = ob_get_clean();

    // Build HTTP response
    $response = "HTTP/1.1 {$statusCode} " . getStatusText($statusCode) . "\r\n";

    // Add default headers
    $response .= "Server: FPP-WLED-Proxy/1.0\r\n";
    $response .= "Content-Type: application/json\r\n";
    $response .= "Content-Length: " . strlen($output) . "\r\n";
    $response .= "Connection: close\r\n";
    $response .= "Access-Control-Allow-Origin: *\r\n";
    $response .= "Access-Control-Allow-Methods: GET, POST, OPTIONS\r\n";
    $response .= "\r\n";
    $response .= $output;

    // Send response
    socket_write($client, $response);
    socket_close($client);
}

socket_close($socket);
syslog(LOG_INFO, "WLED API Server stopped");
exit(0);

// Helper function
function getStatusText($code) {
    $statuses = [
        200 => 'OK',
        400 => 'Bad Request',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error',
    ];
    return $statuses[$code] ?? 'Unknown';
}
