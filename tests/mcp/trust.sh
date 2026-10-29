#!/usr/bin/env bash
# Activity log, scopes, error contract. Run: bash tests/mcp/trust.sh
. "$(dirname "$0")/lib.sh"
# Approvals have their own suite; skip them here.
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null

setscope() { wpe "\$s = get_user_meta(1, 'cm_mcp_scopes', true) ?: []; \$s['$MCP_UUID'] = '$1'; update_user_meta(1, 'cm_mcp_scopes', \$s);" >/dev/null; }
lastlog() { wpe "global \$wpdb; echo wp_json_encode(\$wpdb->get_row(\"SELECT source, connection_uuid, ability, status, input_json, result_summary FROM {\$wpdb->prefix}cm_ai_log ORDER BY id DESC LIMIT 1\"));"; }

PID=$(wpe 'echo wp_insert_post(["post_title" => "cm trust test", "post_status" => "draft"]);')

# 1.3 scopes
# An MCP password with no saved scope (core's REST route takes any app_id) is read-only.
wpe "\$s = get_user_meta(1, 'cm_mcp_scopes', true); unset(\$s['$MCP_UUID']); update_user_meta(1, 'cm_mcp_scopes', \$s);" >/dev/null
check 'no saved scope means read-only'    "$(call update-post-metadata "{\"post_id\":$PID,\"key\":\"k\",\"value\":\"v\"}")" '~"cause":"scope"'
setscope read
check 'read scope hides write tools'      "$(rpc tools/list)" '!~"name":"update-post"'
check 'read scope keeps read tools'       "$(rpc tools/list)" '~"name":"get-posts"'
R=$(call update-post-metadata "{\"post_id\":$PID,\"key\":\"k\",\"value\":\"v\"}")
check 'read scope refuses a write'        "$R" '~"cause":"scope"'
check 'scope error says no retry'         "$R" '~"retry_ok":false'
check 'scope error has structuredContent' "$R" '~"structuredContent"'
check 'denied call is logged'             "$(lastlog)" '~"status":"denied"'

setscope content
W=$(call update-post-metadata "{\"post_id\":$PID,\"key\":\"api_token\",\"value\":\"ghp_abcdefghijklmnopqrstuvwxyz\"}")
check "content scope allows content write" "$W" "!~isError"
L=$(lastlog)
check 'write logged with source mcp'      "$L" '~"source":"mcp"'
check 'write logged with connection uuid' "$L" "~\"connection_uuid\":\"$MCP_UUID\""
check 'write logged ok'                   "$L" '~"status":"ok"'
check 'token in input is redacted'        "$L" '!~ghp_abc'
# H-2: CM tokens, Bearer, PEM, app passwords and Google keys inside a value; "author" keys survive.
TOK=cmat_$(printf 'ab%.0s' {1..32})
call update-post-metadata "{\"post_id\":$PID,\"key\":\"zz_note\",\"value\":\"$TOK Bearer zzBearerVal AIza$(printf 'Q%.0s' {1..35}) abCD 12ef GHij 34kl MNop 56qr -----BEGIN PRIVATE KEY-----zzPem-----END PRIVATE KEY-----\"}" >/dev/null
L=$(lastlog)
for s in "$TOK" zzBearerVal AIzaQQ '56qr' zzPem; do check "value redacted: ${s:0:12}" "$L" "!~$s"; done
check 'author key survives redaction' "$(wpe "echo wp_json_encode(CM_AI_Activity_Log::redact(['post_author' => 7, 'auth_key' => 's']));")" '{"post_author":7,"auth_key":"[redacted]"}'
check 'content scope refuses a site tool'   "$(call deactivate-plugin '{"slug":"woocommerce"}')" '~"cause":"scope"'

setscope full
check 'validation error cause'            "$(call update-post-metadata '{"post_id":"x"}')" '~"cause":"validation"'
check 'validation failure logged as error' "$(lastlog)" '~"status":"error"'

# Reads are not logged by default.
BEFORE=$(lastlog); call get-site-info >/dev/null
check 'reads are not logged' "$(lastlog)" "$BEFORE"

# 1.6 401 diagnostics
check '401 explains a non-MCP password' "$(curl -sk -u "$PLAIN" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" '~Connect over OAuth instead'
check '401 explains missing credentials' "$(curl -sk -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" '~X-CM-Authorization'

wpe "wp_delete_post($PID, true);" >/dev/null
done_checks
