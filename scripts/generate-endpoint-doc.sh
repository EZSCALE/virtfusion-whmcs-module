#!/bin/bash
# Generate API endpoint documentation from PHP source files
# Usage: bash scripts/generate-endpoint-doc.sh > docs/API-ENDPOINTS.md

MODULE_DIR="modules/servers/VirtFusionDirect"

echo "# VirtFusion WHMCS Module — API Endpoints"
echo ""
echo "Auto-generated from source code. Do not edit manually."
echo ""
echo "| Endpoint Pattern | HTTP Method | PHP File | Function |"
echo "|---|---|---|---|"

# Extract API URL patterns from PHP files
grep -rn "->get\|->post\|->put\|->patch\|->delete" "$MODULE_DIR/lib/" 2>/dev/null | \
  grep -oP "(?<=>)(get|post|put|patch|delete)\(.*?'[^']*'" | \
  while IFS= read -r line; do
    method=$(echo "$line" | grep -oP "^(get|post|put|patch|delete)" | tr '[:lower:]' '[:upper:]')
    url=$(echo "$line" | grep -oP "'[^']*'" | tr -d "'")
    echo "| \`$url\` | $method | - | - |"
  done

echo ""
echo "## Client Endpoints (client.php)"
echo ""
echo "| Action | Description |"
echo "|---|---|"

grep -n "case '" "$MODULE_DIR/client.php" 2>/dev/null | \
  while IFS= read -r line; do
    action=$(echo "$line" | grep -oP "case '\K[^']+")
    echo "| \`$action\` | - |"
  done
