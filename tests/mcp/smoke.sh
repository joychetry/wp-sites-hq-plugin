#!/usr/bin/env bash
# Smoke test for the MCP endpoint. Needs Connect to Monks Central on. Run: bash tests/mcp/smoke.sh
. "$(dirname "$0")/lib.sh"
# Approvals have their own suite; skip them here.
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null

check 'initialize'                   "$(rpc initialize '{"protocolVersion":"2025-06-18"}')" '~"protocolVersion":"2025-06-18"'
check 'tools/list has get-site-info' "$(rpc tools/list)" '~"name":"get-site-info"'
check 'read tool call'               "$(call get-site-info)" '~"content"'
check 'bad Origin is 403'            "$(code -u "$AUTH" -H 'Origin: https://evil.example' -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" 403

# 0.1 MCP passwords are confined to the MCP route.
check 'MCP pass on /wp/v2/users/me is 403'  "$(code -u "$AUTH" "$SITE/wp-json/wp/v2/users/me")" 403
check 'MCP pass on ability run is 403'      "$(code -u "$AUTH" "$SITE/wp-json/wp-abilities/v1/abilities/classic-monks/get-site-info/run")" 403
check 'plain app pass still works on REST'  "$(code -u "$PLAIN" "$SITE/wp-json/wp/v2/users/me")" 200
check 'plain app pass cannot use MCP'       "$(code -u "$PLAIN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" 401
check 'MCP pass over XML-RPC is refused'    "$(curl -sk -H 'Content-Type: text/xml' -d "<?xml version=\"1.0\"?><methodCall><methodName>wp.getProfile</methodName><params><param><value>1</value></param><param><value>$LOGIN</value></param><param><value>$MCP_PASS</value></param></params></methodCall>" "$SITE/xmlrpc.php")" '~faultCode'
# A plugin widening application_password_is_api_request (e.g. a GraphQL endpoint) must not open it to MCP passwords.
check 'MCP pass on other API surfaces refused' "$(wpe 'add_filter("application_password_is_api_request", "__return_true"); $u = wp_authenticate_application_password(null, "'"$LOGIN"'", "'"$MCP_PASS"'"); echo is_wp_error($u) ? $u->get_error_code() : "accepted";')" cm_mcp_scope
check 'plain pass there still works'          "$(wpe 'add_filter("application_password_is_api_request", "__return_true"); $u = wp_authenticate_application_password(null, "'"$LOGIN"'", "'"$PLAIN_PASS"'"); echo is_wp_error($u) ? $u->get_error_code() : "accepted";')" accepted

# 0.6 X-CM-Authorization fallback.
B64=$(printf '%s' "$AUTH" | base64)
check 'X-CM-Authorization authenticates'    "$(curl -sk -H "X-CM-Authorization: Basic $B64" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" '~"result"'
check 'malformed X-CM-Authorization ignored' "$(code -H 'X-CM-Authorization: Basic !!!' -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" 401
# F-12: the route in a query string must not enable the fallback elsewhere.
PB64=$(printf '%s' "$PLAIN" | base64)
check 'X-CM-Authorization off non-MCP route'  "$(code -H "X-CM-Authorization: Basic $PB64" "$SITE/wp-json/wp/v2/users/me?x=classic-monks/v1/mcp")" 401
check 'X-CM-Authorization off ?rest_route='   "$(code -H "X-CM-Authorization: Basic $PB64" "$SITE/?rest_route=/wp/v2/users/me&x=/classic-monks/v1/mcp")" 401
check 'X-CM-Authorization via ?rest_route=mcp' "$(curl -sk -H "X-CM-Authorization: Basic $B64" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$SITE/?rest_route=/classic-monks/v1/mcp")" '~"result"'

# 0.4 update-post-metadata keeps case and nested arrays.
PID=$(wpe 'echo wp_insert_post(["post_title" => "cm meta test", "post_status" => "draft"]);')
call update-post-metadata "{\"post_id\":$PID,\"key\":\"Mixed_Case\",\"value\":{\"a\":[1,{\"b\":\"x\\\\y\"}]}}" >/dev/null
check 'meta round-trips case, nesting, backslash' "$(wpe "echo wp_json_encode(get_post_meta($PID, 'Mixed_Case', true));")" '{"a":[1,{"b":"x\\y"}]}'
wpe "wp_delete_post($PID, true);" >/dev/null

done_checks
