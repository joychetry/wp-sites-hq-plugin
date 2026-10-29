#!/usr/bin/env bash
# get-hq-status, and the WordPress 7.1 floor (M15). Run: bash tests/mcp/hq-status.sh
. "$(dirname "$0")/lib.sh"

setscope() { wpe "\$s = get_user_meta(1, 'cm_mcp_scopes', true) ?: []; \$s['$MCP_UUID'] = '$1'; update_user_meta(1, 'cm_mcp_scopes', \$s);" >/dev/null; }
sc() { jq -c ".result.structuredContent$1" <<<"$S"; }

S=$(call get-hq-status)
check 'plugin name'                     "$(sc .plugin.name)" '"Monks Central"'
check 'plugin version'                  "$(sc .plugin.version)" "\"$(wpe 'echo MONKS_CENTRAL_VERSION;')\""
check 'header version matches the constant' "$(wpe 'echo get_file_data(MONKS_CENTRAL_FILE, ["v" => "Version"])["v"] === MONKS_CENTRAL_VERSION ? "same" : "drift";')" same
check 'connector version'               "$(sc .connector.version)" "\"$(wpe 'echo MONKS_CENTRAL_CONNECTOR_VERSION;')\""
check 'connector hosts listed'          "$(sc '.connector.hosts[0].name')" '"Monks Central"'
# M15: WordPress 7.1 is the minimum.
check 'header requires WordPress 7.1'   "$(wpe 'echo get_file_data(MONKS_CENTRAL_FILE, ["r" => "Requires at least"])["r"];')" 7.1
check 'readme requires WordPress 7.1'   "$(wpe 'echo get_file_data(MONKS_CENTRAL_DIR . "readme.txt", ["r" => "Requires at least"])["r"];')" 7.1
check 'wordpress version'               "$(sc .wordpress.version)" "\"$(wpe 'echo get_bloginfo("version");')\""
check 'php version'                     "$(sc .php.version)" "\"$(wpe 'echo PHP_VERSION;')\""
check 'protocol versions, modern first' "$(sc '.mcp.protocol_versions[0]')" '"2026-07-28"'
check 'legacy versions listed'          "$(sc .mcp.protocol_versions)" '~"2025-03-26"'
check 'approvals available on 7.1+'     "$(sc .mcp.approvals)" true
check 'Bricks module reported'          "$(sc .modules.bricks)" "$(wpe 'echo wp_json_encode(defined("BRICKS_VERSION"));')"
check 'modules are booleans'            "$(sc '.modules | map(type) | unique')" '["boolean"]'
check 'no licence field'        "$(sc .licence)" null
check 'caller role reported'            "$(sc .user.roles)" '["administrator"]'
check 'caller can manage options'       "$(sc .user.can_manage_options)" true
check 'connection kind'                 "$(sc .connection.kind)" '"app_password"'
check 'connection scope'                "$(sc .connection.scope)" '"full"'
P0=$(sc .pending_approvals) # earlier suites may leave requests pending
check 'pending approvals is a count'    "$P0" '!null'

# A pending approval the caller may decide is counted.
check 'destructive call waits'          "$(call deactivate-plugin '{"slug":"woocommerce"}')" '~"cause":"approval"'
S=$(call get-hq-status)
check 'pending approval counted'        "$(sc .pending_approvals)" "$((P0 + 1))"
check 'another user sees none'          "$(wpe '$u = wp_insert_user(["user_login" => "cm_hq_editor", "user_pass" => wp_generate_password(), "role" => "editor"]); wp_set_current_user($u); echo wp_json_encode(wp_get_ability("classic-monks/get-hq-status")->execute(array()));')" '~"pending_approvals":0'
check 'editor gets their own role'      "$(wpe 'wp_set_current_user(get_user_by("login", "cm_hq_editor")->ID); echo wp_json_encode(wp_get_ability("classic-monks/get-hq-status")->execute(array())["user"]);')" '~"roles":["editor"],"can_manage_options":false'
check 'subscriber refused'              "$(wpe '$u = wp_insert_user(["user_login" => "cm_hq_sub", "user_pass" => wp_generate_password(), "role" => "subscriber"]); wp_set_current_user($u); $r = wp_get_ability("classic-monks/get-hq-status")->execute(array()); echo is_wp_error($r) ? $r->get_error_code() : "ran";')" ability_invalid_permissions
wpe 'require_once ABSPATH . "wp-admin/includes/user.php"; foreach (["cm_hq_editor", "cm_hq_sub"] as $l) { wp_delete_user(get_user_by("login", $l)->ID); }' >/dev/null
wpe "global \$wpdb; \$wpdb->query(\"UPDATE {\$wpdb->prefix}cm_ai_log SET status = 'expired' WHERE status = 'pending' AND connection_uuid = '$MCP_UUID'\");" >/dev/null

# Works on the Read access level.
setscope read
check 'listed on read scope'            "$(rpc tools/list)" '~"name":"get-hq-status"'
S=$(call get-hq-status)
check 'runs on read scope'              "$S" '!~"isError":true'
check 'reports the read scope'          "$(sc .connection.scope)" '"read"'

done_checks
