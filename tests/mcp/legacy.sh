#!/usr/bin/env bash
# WordPress below 7.1 (ci.sh with CM_WP_VERSION=7.0.x): the plugin still runs, the connector stays off even with its
# option on, MCP credentials stay confined and the screen explains the floor. Run: CM_WP_VERSION=7.0.7 bash tests/mcp/ci.sh
. "$(dirname "$0")/lib.sh"
JAR=$(mktemp); trap 'rm -f "$JAR"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
[[ $(wpe 'echo version_compare(get_bloginfo("version"), "7.1", "<") ? "old" : "new";') == old ]] || { echo "FAIL legacy.sh needs WordPress below 7.1"; exit 1; }
login "$JAR"

check 'option on'                          "$(wpe 'echo get_option("cm_mcp_enabled");')" 1
check 'connector not loaded'               "$(wpe 'echo class_exists("CM_MCP_Server") ? "loaded" : "off";')" off
check 'no MCP route'                       "$(code -u "$AUTH" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" 404
check 'MCP password still confined'        "$(code -u "$AUTH" "$SITE/wp-json/wp/v2/users/me")" 403
PAGE=$(curl -sk -b "$JAR" "$MC_PAGE")
check 'screen data: not supported'         "$PAGE" '~"supported":false'
check 'screen data: switch off'            "$PAGE" '~"enabled":false'
check 'cannot be switched on'              "$(mc_rest "$JAR" POST '' '{"enabled":true}' | cut -d' ' -f1)" 400

done_checks
