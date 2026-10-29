#!/usr/bin/env bash
# list-pending-approvals. Run: bash tests/mcp/pending-approvals.sh
. "$(dirname "$0")/lib.sh"

setscope() { wpe "\$s = get_user_meta(1, 'cm_mcp_scopes', true) ?: []; \$s['$MCP_UUID'] = '$1'; update_user_meta(1, 'cm_mcp_scopes', \$s);" >/dev/null; }
opid() { sed -E 's/.*"op_id":([0-9]+).*/\1/' <<<"$1"; }
mine() { call list-pending-approvals | jq -c ".result.structuredContent.approvals | map(select(.op_id == $1))$2"; }

P=$(wpe 'echo wp_insert_post(["post_title" => "cm pending", "post_status" => "draft"]);')
OP=$(opid "$(call update-post "{\"post_id\":$P,\"title\":\"cm pending renamed\"}")")
L=$(call list-pending-approvals)
check 'count matches the list'          "$(jq '.result.structuredContent | .count == (.approvals | length)' <<<"$L")" true
check 'request is listed'               "$(mine "$OP" " | length")" 1
check 'tool, connection and user'       "$(mine "$OP" '[0] | [.tool, .connection, .user]')" "[\"update-post\",\"$MCP_UUID\",\"$LOGIN\"]"
check 'arguments are shown'             "$(mine "$OP" '[0].input')" "{\"post_id\":$P,\"title\":\"cm pending renamed\"}"
check 'preview is shown'                "$(mine "$OP" '[0].preview.title')" '"cm pending"'
check 'approve_url points at the request' "$(mine "$OP" '[0].approve_url')" "~cm-ai-approve&op=$OP\""
check 'expires_in counts down'          "$(mine "$OP" '[0].expires_in > 0')" true
check 'listing decided nothing'         "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT status FROM {\$wpdb->prefix}cm_ai_log WHERE id = $OP\");")" pending

check 'no tool decides approvals'       "$(rpc tools/list | jq -c '[.result.tools[].name | select(test("approv|decide|decline"))]')" '["list-pending-approvals"]'
setscope read
check 'read scope may list'             "$(call list-pending-approvals | jq .result.structuredContent.count)" '!null'
setscope full
check 'another editor sees none of it'  "$(wpe "\$u = wp_insert_user(['user_login' => 'cm_pend_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']); wp_set_current_user(\$u); \$r = wp_get_ability('classic-monks/list-pending-approvals')->execute(array()); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user(\$u); echo is_wp_error(\$r) ? \$r->get_error_code() : \$r['count'];")" 0
check 'subscriber refused'              "$(wpe "\$u = wp_insert_user(['user_login' => 'cm_pend_sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber']); wp_set_current_user(\$u); \$r = wp_get_ability('classic-monks/list-pending-approvals')->execute(array()); require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user(\$u); echo is_wp_error(\$r) ? \$r->get_error_code() : 'ran';")" ability_invalid_permissions

wpe "global \$wpdb; \$t = CM_AI_Activity_Log::table(); \$a = json_decode(\$wpdb->get_var(\"SELECT approval_json FROM \$t WHERE id = $OP\"), true); \$a['expires'] = time() - 1; \$wpdb->update(\$t, ['approval_json' => wp_json_encode(\$a)], ['id' => $OP]);" >/dev/null
check 'expired request is left out'     "$(mine "$OP" " | length")" 0

wpe "global \$wpdb; \$wpdb->query(\"UPDATE {\$wpdb->prefix}cm_ai_log SET status = 'expired' WHERE status = 'pending' AND connection_uuid = '$MCP_UUID'\"); wp_delete_post($P, true);" >/dev/null
done_checks
