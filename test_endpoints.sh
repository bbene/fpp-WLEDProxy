#!/bin/bash
# ─ Test WLED API Endpoints ────────────────────────────────────────────────────
#
# This script tests all WLED API endpoints against a running WLED API server.
# Usage: ./test_endpoints.sh <host:port>
# Example: ./test_endpoints.sh localhost:9000
#
# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

HOST="${1:-localhost:9000}"
BASE_URL="http://$HOST"

echo "Testing WLED API endpoints on $BASE_URL"
echo "========================================"
echo ""

# Function to test an endpoint
test_endpoint() {
    local method=$1
    local path=$2
    local description=$3

    echo -n "[$method] $path ... "

    if [ "$method" = "GET" ]; then
        response=$(curl -s -w "\n%{http_code}" "$BASE_URL$path")
    else
        response=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL$path" -H "Content-Type: application/json" -d '{}')
    fi

    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | head -n-1)

    if [ "$http_code" = "200" ] || [ "$http_code" = "204" ]; then
        echo -e "${GREEN}✓ $http_code${NC} $description"
        # Show first 100 chars of response
        if [ ! -z "$body" ]; then
            echo "  Response: ${body:0:100}..."
        fi
    else
        echo -e "${RED}✗ $http_code${NC} $description"
        echo "  Response: $body"
    fi
    echo ""
}

# Test core endpoints
echo "Core Endpoints:"
test_endpoint "GET" "/json" "Full state + info + effects + palettes"
test_endpoint "GET" "/json/state" "Current state"
test_endpoint "GET" "/json/info" "Device info"
test_endpoint "GET" "/json/effects" "Effect list"
test_endpoint "GET" "/json/palettes" "Palette list"
test_endpoint "GET" "/json/fxdata" "Effect metadata (WLED v0.14+)"
test_endpoint "GET" "/json/cfg" "Device configuration"
test_endpoint "GET" "/json/nodes" "Multi-device support"
test_endpoint "GET" "/json/si" "State + info combined"

echo ""
echo "Alternate Paths:"
test_endpoint "GET" "/api/json" "Alternate path for /json"
test_endpoint "GET" "/json/eff" "Short alias for effects"
test_endpoint "GET" "/json/pal" "Short alias for palettes"

echo ""
echo "POST Endpoints:"
test_endpoint "POST" "/json/state" "Update state"
test_endpoint "POST" "/json/si" "Update state+info combined"
test_endpoint "POST" "/json" "Update via full endpoint"

echo ""
echo "Legacy HTTP API:"
test_endpoint "GET" "/win" "WLED HTTP Request API"
test_endpoint "POST" "/win" "WLED HTTP Request API (POST)"

echo ""
echo "========================================"
echo "Testing complete!"
