#!/usr/bin/env bash
# A Classic Monks build that still carries its own connector (simulated: CM_FUNCTIONS_DIR with Free's guard file)
# serves the site, so the shared connector stands down: no MCP or OAuth routes, abilities or guard of its own, and the
# screen says who manages the connection. Run: bash tests/mcp/standdown.sh
. "$(dirname "$0")/lib.sh"
MU="$SITE_ROOT/wp-content/mu-plugins/zz-cm-fake-legacy.php"
FAKE="$SITE_ROOT/wp-content/cm-fake-legacy"
mkdir -p "$(dirname "$MU")" "$FAKE/functions/monks-central/mcp"
touch "$FAKE/functions/monks-central/mcp/mcp-guard.php"
JAR=$(mktemp)
trap 'rm -rf "$MU" "$FAKE" "$JAR"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
LOG="$SITE_ROOT/wp-content/debug.log"
LINES=$(wc -l <"$LOG" 2>/dev/null || echo 0)
login "$JAR"

check 'before: the connector serves MCP'    "$(wpe 'echo class_exists("CM_MCP_Server") ? "on" : "none";')" on
echo "<?php define( 'CM_FUNCTIONS_DIR', '$FAKE/functions/' ); // Stand-in for a Classic Monks build with its own connector (standdown.sh)." >"$MU"
check 'no connector MCP classes'            "$(wpe 'echo class_exists("CM_MCP_Server") || class_exists("CM_MCP_OAuth") || class_exists("CM_AI_Activity_Log") ? "loaded" : "none";')" none
check 'no classic-monks abilities'          "$(wpe 'echo count(array_filter(array_keys(wp_get_abilities()), function ($n) { return 0 === strpos($n, "classic-monks/"); }));')" 0
check 'no guard of its own'                 "$(wpe 'echo function_exists("cm_mcp_confine_credentials") ? "loaded" : "none";')" none
check 'no MCP route'                        "$(code -u "$AUTH" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" 404
check 'no OAuth routes'                     "$(code -X POST "$SITE/wp-json/classic-monks/v1/oauth/register")" 404
check 'screen says Classic Monks serves'    "$(mc_rest "$JAR" GET '' | cut -d' ' -f2- | jq -c '[.state.legacy, .state.enabled, .connections]')" '[true,false,[]]'
check 'settings page still renders'         "$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' "$MC_PAGE")" 200
check 'site still renders'                  "$(code "$SITE/")" 200
check 'nothing new in debug.log'            "$(tail -n +$((LINES + 1)) "$LOG" 2>/dev/null | grep -c 'Cannot redeclare\|Fatal')" 0
rm -f "$MU"
check 'after: the connector serves again'   "$(code -u "$AUTH" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" 200

done_checks
