#!/usr/bin/env bash
# Monks Central M2: operator grants. Signs in as admin/admin (CM_ADMIN_USER / CM_ADMIN_PASS). Run: bash tests/mcp/operator.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"
ADMIN=${CM_ADMIN_USER:-admin}; ADMIN_PASS=${CM_ADMIN_PASS:-admin}
ED=$(wpe 'echo wp_insert_user(["user_login" => "cm_op_editor", "user_pass" => "cm-op-editor-pass", "role" => "editor", "user_email" => "cm-op-editor@example.com"]);')
trap 'rm -f "${OAUTH_JARS[@]}"; wpe "require_once ABSPATH . \"wp-admin/includes/user.php\"; wp_delete_user($ED);" >/dev/null; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
hq() { bcall "$1" get-hq-status | jq -c ".result.structuredContent.connection$2"; }
newpost() { wpe 'echo wp_insert_post(["post_title" => "cm operator test", "post_status" => "draft"]);'; }

# Ticked by an admin: full scope, site toolset, trusted, and the always-ask tools run directly.
T=$(connect "$ADMIN" "$ADMIN_PASS" 'Monks Central' -d scope=content -d 'toolsets[]=content' -d 'toolsets[]=modules' -d operator=1)
check 'consent page offers operator to admins' "$(cat "$CONSENT_FILE")" '~This is my Monks Central app (operator)'
AT=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
check 'operator grant gets full scope'      "$(jq -r .scope <<<"$T")" full
check 'operator reported by get-hq-status'  "$(hq "$AT" '| [.operator, .operator_skips_always_ask, .trusted]')" '[true,true,true]'
check 'every tool group'                   "$(hq "$AT" '.toolsets | sort')" "$(wpe 'echo wp_json_encode(array_keys(CM_MCP_Server::toolsets()));' | jq -c sort)"
P=$(newpost)
check 'always-ask tool runs directly'       "$(bcall "$AT" delete-post "{\"post_id\":$P,\"force\":true}")" '!~isError'
check 'post really deleted'                 "$(wpe "echo get_post($P) ? 'kept' : 'gone';")" gone
check 'run tagged [operator]'               "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT CONCAT(status, ' ', client_label) FROM {\$wpdb->prefix}cm_ai_log WHERE connection_uuid = 'oauth:$FAM' ORDER BY id DESC LIMIT 1\");")" '~ok Monks Central [operator]'
# An update over HTTP keeps an active plugin on: core's upgrade() turns it off for a wp-admin page to turn back on.
AKA=$(wpe 'echo is_plugin_active("akismet/akismet.php") ? 1 : 0;')
(cd "$SITE_ROOT" && $WP_CMD plugin install akismet --version=5.0 --force --activate >/dev/null 2>&1)
check 'update-plugin runs over HTTP'        "$(bcall "$AT" update-plugin '{"plugin_file":"akismet/akismet.php","force_update_check":true}')" '~"updated":true'
check '... and the plugin is still active'  "$(wpe 'echo is_plugin_active("akismet/akismet.php") ? "active" : "inactive";')" active
[[ $AKA == 1 ]] || wpe 'deactivate_plugins("akismet/akismet.php");' >/dev/null

# The owner turns the always-ask skip off on Settings > Monks Central.
JAR=$(mktemp); OAUTH_JARS+=("$JAR")
login "$JAR" "$ADMIN" "$ADMIN_PASS"
SCREEN=$(mc_rest "$JAR" GET '')
check 'screen lists the operator skip on'   "$(jq -c --arg id "oauth:$FAM" '.connections[] | select(.uuid == $id) | .operator' <<<"${SCREEN#* }")" true
check 'owner turns the skip off'            "$(mc_rest "$JAR" POST /connections/operator "{\"user_id\":1,\"id\":\"oauth:$FAM\",\"skip\":false}")" '~200 '
check '... reported off'                    "$(hq "$AT" .operator_skips_always_ask)" false
P=$(newpost)
check 'always-ask tool asks again'          "$(bcall "$AT" delete-post "{\"post_id\":$P,\"force\":true}")" '~"cause":"approval"'
check 'other destructive tools still direct' "$(bcall "$AT" delete-post "{\"post_id\":$P}")" '!~isError'
check 'owner turns the skip back on'        "$(mc_rest "$JAR" POST /connections/operator "{\"user_id\":1,\"id\":\"oauth:$FAM\",\"skip\":true}")" '~200 '
check '... reported on'                     "$(hq "$AT" .operator_skips_always_ask)" true
# The plain MCP connection is no operator grant.
check 'not an operator: switch refused'     "$(mc_rest "$JAR" POST /connections/operator "{\"user_id\":1,\"id\":\"$MCP_UUID\",\"skip\":true}" | cut -d' ' -f1)" 400
check '... and stays no operator'           "$(wpe "echo isset(get_user_meta(1, 'cm_mcp_operator', true)['$MCP_UUID']) ? 'set' : 'none';")" none
wpe "wp_delete_post($P, true);" >/dev/null

# Revoking forgets it.
revoke_grant "$FAM"
check 'revoke drops the operator flag'      "$(wpe "echo isset(get_user_meta(1, 'cm_mcp_operator', true)['oauth:$FAM']) ? 'kept' : 'gone';")" gone

# The client's own name proves nothing: same name, box not ticked.
T=$(connect "$ADMIN" "$ADMIN_PASS" 'Monks Central' -d scope=content -d 'toolsets[]=content' -d 'toolsets[]=modules')
AT=$(jq -r .access_token <<<"$T")
check 'unticked: not an operator'           "$(hq "$AT" '| [.operator, .trusted]')" '[false,false]'
check 'unticked: scope as chosen'           "$(jq -r .scope <<<"$T")" content
revoke_grant "$(jq -r .family <<<"$T")"

# A web app (an https return address) is never an operator, even when it posts the box: only an app on this computer.
T=$(OAUTH_REDIRECT=https://app.example/callback connect "$ADMIN" "$ADMIN_PASS" 'Monks Central' -d scope=content -d 'toolsets[]=content' -d 'toolsets[]=modules' -d operator=1)
check 'web app: no operator box'            "$(cat "$CONSENT_FILE")" '!~This is my Monks Central app (operator)'
check 'web app: not an operator'            "$(hq "$(jq -r .access_token <<<"$T")" '| [.operator, .trusted]')" '[false,false]'
revoke_grant "$(jq -r .family <<<"$T")"

# Only admins can make an operator grant, even by posting the field.
T=$(connect cm_op_editor cm-op-editor-pass 'Monks Central' -d scope=content -d 'toolsets[]=content' -d 'toolsets[]=modules' -d operator=1)
check 'consent page hides operator from editors' "$(cat "$CONSENT_FILE")" '!~Monks Central app (operator)'
AT=$(jq -r .access_token <<<"$T")
check 'editor posting operator=1 is ignored' "$(hq "$AT" .operator)" false
check '... and keeps the chosen scope'      "$(jq -r .scope <<<"$T")" content
revoke_grant "$(jq -r .family <<<"$T")"

done_checks
