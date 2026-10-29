#!/usr/bin/env bash
# Settings > Monks Central: the page, its REST routes (switch, connected apps, approvals, activity, undo), as an admin
# and an editor. Signs in as admin/admin (CM_ADMIN_USER / CM_ADMIN_PASS). Run: bash tests/mcp/admin-page.sh
. "$(dirname "$0")/lib.sh"
JAR=$(mktemp); EJAR=$(mktemp)
ED=$(wpe 'echo wp_insert_user(["user_login" => "cm_mc_editor", "user_pass" => "cm-mc-editor-pass", "role" => "editor"]);')
trap 'rm -f "$JAR" "$EJAR"; wpe "update_option(\"cm_mcp_enabled\", 1); require_once ABSPATH . \"wp-admin/includes/user.php\"; wp_delete_user($ED);" >/dev/null; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
login "$JAR"
login "$EJAR" cm_mc_editor cm-mc-editor-pass
ping() { code -u "$AUTH" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP"; }
body() { echo "${1#* }"; }

PAGE=$(curl -sk -b "$JAR" "$MC_PAGE")
check 'page renders for admins'             "$PAGE" '~id="monks-central-admin"'
check 'loads the screen script'             "$PAGE" '~monks-central/build/admin.js'
check 'screen data: this plugin runs it'    "$PAGE" '~"runs_here":true'
check 'screen data: switch on'              "$PAGE" '~"enabled":true'
check 'no PHP notices on the page'          "$PAGE" '!~<b>Warning</b>'
check 'listed under Settings'               "$(curl -sk -b "$JAR" "$SITE/wp-admin/")" '~options-general.php?page=monks-central'
check 'editors cannot open the page'        "$(curl -sk -b "$EJAR" -o /dev/null -w '%{http_code}' "$MC_PAGE")" 403
check 'editors get no screen data'          "$(mc_rest "$EJAR" GET '' | cut -d' ' -f1)" 403
check 'no screen data without a nonce'      "$(curl -sk -b "$JAR" -o /dev/null -w '%{http_code}' "$MC_API")" 401

S=$(body "$(mc_rest "$JAR" GET '')")
check 'shows the site address'              "$(jq -r .state.site <<<"$S")" "$SITE"
check 'shows the endpoint'                  "$(jq -r .state.endpoint <<<"$S")" "$MCP"
check 'shows the connector version'         "$(jq -r .state.version <<<"$S")" "$(wpe 'echo MONKS_CENTRAL_CONNECTOR_VERSION;')"
check 'lists the connection'                "$(jq -r --arg id "$MCP_UUID" '.connections[] | select(.uuid == $id) | .scope_label' <<<"$S")" '!'

# A logged change and a pending request show up.
P=$(wpe 'echo wp_insert_post(["post_title" => "cm admin page test", "post_status" => "draft"]);')
R=$(call delete-post "{\"post_id\":$P}")
OP=$(grep -oE 'op=[0-9]+' <<<"$R" | head -1 | cut -d= -f2)
S=$(body "$(mc_rest "$JAR" GET '')")
check 'pending request listed with its link' "$(jq -r --argjson op "$OP" '.pending[] | select(.id == $op) | .approve_url' <<<"$S")" "~cm-ai-approve&op=$OP"
A=$(body "$(mc_rest "$JAR" GET '/activity?per_page=5')")
check 'activity lists the request'          "$(jq -r '.rows[0].tool' <<<"$A")" delete-post
check 'activity is paged'                   "$(jq '.total > 0 and (.rows | length) <= 5' <<<"$A")" true
APPROVE=$(curl -sk -b "$JAR" "$SITE/wp-admin/admin.php?page=cm-ai-approve&op=$OP")
check 'approval page renders'               "$APPROVE" '~Approve change'
check '... with its decision form'          "$APPROVE" '~name="action" value="cm_ai_decide"'
check '... and no PHP notices'              "$APPROVE" '!~<b>Warning</b>'
wpe "global \$wpdb; \$wpdb->update(CM_AI_Activity_Log::table(), ['status' => 'expired'], ['id' => $OP]); wp_delete_post($P, true);" >/dev/null

# Undo from the screen: a term renamed over MCP (trusted, so it runs without approval) gets its name back.
wpe "\$m = get_user_meta(1, 'cm_mcp_trusted', true) ?: []; \$m['$MCP_UUID'] = true; update_user_meta(1, 'cm_mcp_trusted', \$m);" >/dev/null
T=$(wpe 'echo wp_insert_term("cm undo before", "category")["term_id"];')
call update-term "{\"term_id\":$T,\"name\":\"cm undo after\"}" >/dev/null
ROW=$(body "$(mc_rest "$JAR" GET '/activity?per_page=1')")
check 'the change can be undone'            "$(jq -c '.rows[0] | [.tool, .can_undo]' <<<"$ROW")" '["update-term",true]'
ID=$(jq -r '.rows[0].id' <<<"$ROW")
check 'editor cannot undo'                  "$(mc_rest "$EJAR" POST "/activity/$ID/undo" | cut -d' ' -f1)" 403
check 'admin undoes it'                     "$(mc_rest "$JAR" POST "/activity/$ID/undo")" "~200 {\"undone\":$ID}"
check '... name restored'                   "$(wpe "echo get_term($T)->name;")" 'cm undo before'
check 'a second undo is refused'            "$(mc_rest "$JAR" POST "/activity/$ID/undo" | cut -d' ' -f1)" 409
wpe "wp_delete_term($T, 'category');" >/dev/null

# Off: the endpoint is gone, MCP credentials stay confined, the switch stays reachable.
check 'editor cannot switch it off'         "$(mc_rest "$EJAR" POST '' '{"enabled":false}' | cut -d' ' -f1)" 403
check '... still on'                        "$(wpe 'echo get_option("cm_mcp_enabled");')" 1
check 'admin switches it off'               "$(mc_rest "$JAR" POST '' '{"enabled":false}')" '200 {"enabled":false}'
check 'option off'                          "$(wpe 'echo (int) get_option("cm_mcp_enabled");')" 0
check 'option not autoloaded'               "$(wpe 'global $wpdb; echo $wpdb->get_var("SELECT autoload FROM $wpdb->options WHERE option_name = \"cm_mcp_enabled\"");')" '~o'
check 'MCP endpoint gone while off'         "$(ping)" 404
check 'discovery gone while off'            "$(code "$SITE/.well-known/oauth-protected-resource")" 404
check 'MCP password still confined'         "$(code -u "$AUTH" "$SITE/wp-json/wp/v2/users/me")" 403
check 'screen shows the switch off'         "$(body "$(mc_rest "$JAR" GET '')" | jq -c '[.state.enabled, .connections]')" '[false,[]]'
check 'admin switches it on'                "$(mc_rest "$JAR" POST '' '{"enabled":true}')" '200 {"enabled":true}'
check 'MCP endpoint back'                   "$(ping)" 200

done_checks
