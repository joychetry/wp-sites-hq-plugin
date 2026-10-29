#!/usr/bin/env bash
# Approval gate, drift, trusted, approve-similar. Run: bash tests/mcp/approvals.sh
. "$(dirname "$0")/lib.sh"

newpost() { wpe 'echo wp_insert_post(["post_title" => "cm approval test", "post_status" => "draft"]);'; }
status() { wpe "echo get_post_status($1);"; }
opid() { sed -E 's/.*"op_id":([0-9]+).*/\1/' <<<"$1"; }
approve() { wpe "global \$wpdb; \$wpdb->update(CM_AI_Activity_Log::table(), ['status' => 'approved'], ['id' => $1]);" >/dev/null; }
# Through the approve page's handler, as a person would.
decide() { wpe "wp_set_current_user(1); \$_POST = \$_REQUEST = ['op' => $1, 'decision' => 'approve', '_wpnonce' => wp_create_nonce('cm_ai_decide_$1')]; add_filter('wp_redirect', function () { throw new Exception('redirect'); }); try { CM_MCP_Approvals::handle_decision(); } catch (Exception \$e) {}" >/dev/null; }

P=$(newpost)
R1=$(call delete-post "{\"post_id\":$P}")
check 'destructive call needs approval'     "$R1" '~"cause":"approval"'
check 'approval carries approve_url'        "$R1" '~cm-ai-approve&op='
check 'post untouched while pending'        "$(status $P)" draft
OP=$(opid "$R1")
R2=$(call delete-post "{\"post_id\":$P}")
check 'retry while pending reuses the op'   "$(opid "$R2")" "$OP"
check 'other arguments are a new request'   "$(opid "$(call delete-post '{"post_id":999999}')")" "!$OP"

decide "$OP"
check 'approved retry runs'                 "$(call delete-post "{\"post_id\":$P}")" '!~isError'
check 'post trashed after approval'         "$(status $P)" trash
# M9: the request, its retries and the approved run are one log row.
logrows() { wpe "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_ai_log WHERE connection_uuid = %s AND ability = 'classic-monks/delete-post' AND input_json = %s\", '$MCP_UUID', '{\"post_id\":$P}'));"; }
oprow() { wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT CONCAT(status, ' | ', client_label) FROM {\$wpdb->prefix}cm_ai_log WHERE id = $OP\");"; }
check 'request + retries + run = one row'   "$(logrows)" 1
check 'request row carries the result'      "$(oprow)" '~ok |'
check '... tagged with the approver'        "$(oprow)" '~[approved by admin]'
check 'no Refused row for the waiting retry' "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_ai_log WHERE status = 'denied' AND result_summary = 'Still waiting for approval.'\");")" 0
check 'approve page says it ran'            "$(wpe "wp_set_current_user(1); \$_GET['op'] = $OP; ob_start(); CM_MCP_Approvals::render_page(); echo ob_get_clean();")" '~Approved and already run.'
check 'approved op runs once'               "$(call delete-post "{\"post_id\":$P}")" '~"cause":"approval"'
check '... a new request is a new row'      "$(logrows)" 2

# Drift: post changes between approval and retry.
P2=$(newpost)
OP2=$(opid "$(call delete-post "{\"post_id\":$P2}")")
approve "$OP2"
wpe "wp_update_post(['ID' => $P2, 'post_title' => 'changed']);" >/dev/null
check 'drift is refused'                    "$(call delete-post "{\"post_id\":$P2}")" '~cm_approval_drift'
check 'post survives drift'                 "$(status $P2)" draft

# Trusted connection skips approvals.
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null
check 'trusted connection runs directly'    "$(call delete-post "{\"post_id\":$P2}")" '!~isError'
check 'trusted run is tagged in the log'    "$(wpe 'global $wpdb; echo $wpdb->get_var("SELECT client_label FROM {$wpdb->prefix}cm_ai_log ORDER BY id DESC LIMIT 1");')" '~[trusted]'
wpe "delete_user_meta(1, 'cm_mcp_trusted');" >/dev/null

# Approve-similar grant.
P3=$(newpost); P4=$(newpost)
SK="\$k = 'cm_ai_similar_' . md5('$MCP_UUID') . '_' . md5('classic-monks/delete-post');"
wpe "$SK update_option(\$k, 25, false); update_option(\$k . '_until', time() + 1800, false);" >/dev/null
check 'similar grant lets a call through'   "$(call delete-post "{\"post_id\":$P3}")" '!~isError'
check 'grant counts down'                   "$(wpe "$SK echo get_option(\$k);")" 24
wpe "$SK update_option(\$k . '_until', time() - 1, false);" >/dev/null
check 'expired grant is not used'           "$(call delete-post "{\"post_id\":$P4}")" '~"cause":"approval"'
check 'expired grant is cleaned up'         "$(wpe "$SK echo get_option(\$k, 'gone');")" gone
check 'without grant it asks again'         "$(call delete-post "{\"post_id\":$P4}")" '~"cause":"approval"'

# Not gated outside MCP (CLI / chat path).
check 'non-MCP callers are not gated'       "$(wpe "wp_set_current_user(1); \$r = wp_get_ability('classic-monks/delete-post')->execute(['post_id' => $P4]); echo is_wp_error(\$r) ? \$r->get_error_code() : 'ran';")" ran

# Read tools never need approval.
check 'reads are not gated'                 "$(call get-site-info)" '!~isError'

# Account and code-running tools are gated; create-user asks even on a trusted connection.
check 'deactivate-plugin needs approval'    "$(call deactivate-plugin '{"slug":"cm-no-such-plugin"}')" '~"cause":"approval"'
check 'install-theme needs approval'        "$(call install-theme '{"slug":"cm-no-such-theme"}')" '~"cause":"approval"'
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null
check 'create-user asks even when trusted'  "$(call create-user '{"username":"cm_approval_test","email":"cm-approval-test@example.com"}')" '~"cause":"approval"'
# H-3: tools that can't be undone ask even when trusted; trashing a post doesn't.
C=$(wpe "\$c = wp_insert_comment(['comment_post_ID' => 1, 'comment_content' => 'zz approval']); wp_trash_comment(\$c); echo \$c;")
P5=$(wpe "echo wp_insert_post(['post_title' => 'zz force', 'post_status' => 'draft']);")
check 'delete-comment asks when trusted'    "$(call delete-comment "{\"comment_id\":$C}")" '~"cause":"approval"'
check 'uninstall-plugin asks when trusted'  "$(call uninstall-plugin '{"slug":"zz-no-such-plugin"}')" '~"cause":"approval"'
check 'delete-theme asks when trusted'      "$(call delete-theme '{"stylesheet":"zz-no-such-theme"}')" '~"cause":"approval"'
check 'update-core-db asks when trusted'    "$(call update-core-db)" '~"cause":"approval"'
check 'delete-post force asks when trusted' "$(call delete-post "{\"post_id\":$P5,\"force\":true}")" '~"cause":"approval"'
check 'nothing deleted while pending'       "$(wpe "echo (int) (bool) get_comment($C), (int) (bool) get_post($P5);")" 11
check 'delete-post trash still runs directly' "$(call delete-post "{\"post_id\":$P5}")" '!~isError'
wpe "wp_delete_comment($C, true); wp_delete_post($P5, true);" >/dev/null
wpe "delete_user_meta(1, 'cm_mcp_trusted');" >/dev/null
check 'no user created while pending'       "$(wpe "echo username_exists('cm_approval_test') ? 'yes' : 'no';")" no

# The approve page shows inputs past the log's 8000-byte cut.
BIG="$(printf 'x%.0s' {1..20000})TAILMARK"
P6=$(newpost)
R3=$(call update-post "{\"post_id\":$P6,\"content\":\"$BIG\"}") # bash 3.2 mangles \" in a nested $( )
OP3=$(opid "$R3")
check 'approve page shows the full input'   "$(wpe "wp_set_current_user(1); \$_GET['op'] = $OP3; ob_start(); CM_MCP_Approvals::render_page(); echo false !== strpos(ob_get_clean(), 'TAILMARK') ? 'full' : 'cut';")" full

for id in $P $P2 $P3 $P4 $P6; do wpe "wp_delete_post($id, true);" >/dev/null; done
done_checks
