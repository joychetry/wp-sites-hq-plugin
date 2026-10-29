#!/usr/bin/env bash
# Comment create/delete and the core DB update over MCP, with approvals and capabilities. Run: bash tests/mcp/wrappers2.sh
. "$(dirname "$0")/lib.sh"
num() { sed -nE "s/.*\"$1\":([0-9]+).*/\\1/p" <<<"$2"; }
# Tools that can't be undone ask even on a trusted connection (H-3): approve a fresh request (older ones
# would fail the drift check), then call again.
approved() { local args=${2:-'{}'} op; wpe "global \$wpdb; \$wpdb->update(CM_AI_Activity_Log::table(), ['status' => 'expired'], ['ability' => 'classic-monks/$1', 'status' => 'pending']);" >/dev/null; op=$(num op_id "$(call "$1" "$args")"); [ -n "$op" ] && wpe "global \$wpdb; \$wpdb->update(CM_AI_Activity_Log::table(), ['status' => 'approved'], ['id' => $op]);" >/dev/null; call "$1" "$args"; }

P=$(wpe 'echo wp_insert_post(["post_title" => "cm wrappers2", "post_status" => "publish"]);')
C=$(wpe "echo wp_insert_comment(['comment_post_ID' => $P, 'comment_content' => 'to delete', 'comment_approved' => 'trash']);")

# Destructive wrappers ask for approval on an untrusted connection and change nothing.
check 'delete-comment needs approval'          "$(call delete-comment "{\"comment_id\":$C}")" '~"cause":"approval"'
check 'update-core-db needs approval'          "$(call update-core-db)" '~"cause":"approval"'
check 'nothing changed while pending'          "$(wpe "echo get_comment($C) ? 1 : 0;")" 1
check 'reads are not gated'                    "$(call get-comments "{\"post_id\":$P}")" '!~isError'

wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null

# Comments
R=$(call create-comment "{\"post_id\":$P,\"content\":\"cm new comment\"}")
NC=$(num comment_id "$R")
check 'create-comment'                         "$R" '~"status":"approved"'
check 'create-comment is top level'            "$(wpe "echo get_comment(${NC:-0})->comment_parent, '|', get_comment(${NC:-0})->comment_post_ID;")" "0|$P"
check 'get-comments lists it'                  "$(call get-comments "{\"post_id\":$P}")" "~\"id\":${NC:-0}"
check 'create-comment refuses a missing post'  "$(call create-comment '{"post_id":99999999,"content":"x"}')" '~"isError":true'
A=$(wpe "echo wp_insert_comment(['comment_post_ID' => $P, 'comment_content' => 'live', 'comment_approved' => 1]);")
check 'delete-comment refuses a live comment'  "$(approved delete-comment "{\"comment_id\":$A}")" '~comment_not_trashed'
check 'live comment kept'                      "$(wpe "echo wp_get_comment_status($A);")" approved
check 'get-comments lists a comment with no email' "$(call get-comments "{\"post_id\":$P}")" "~\"id\":$A"
check 'delete-comment deletes trashed'         "$(approved delete-comment "{\"comment_id\":$C}")" '~"deleted":true'
check 'comment is gone'                        "$(wpe "echo get_comment($C) ? 'there' : 'gone';")" gone

# Core DB update: no-op when current; runs when behind (one version back runs no upgrade routine).
check 'update-core-db no-op'                   "$(approved update-core-db)" '~"updated":false'
wpe 'global $wp_db_version; update_option("db_version", $wp_db_version - 1);' >/dev/null
check 'update-core-db upgrades'                "$(approved update-core-db)" '~"updated":true'
check 'db_version current again'               "$(wpe 'global $wp_db_version; echo (int) get_option("db_version") === $wp_db_version ? "ok" : "behind";')" ok

# Capabilities: an editor may moderate but not touch plugins, themes, users, menus or the database.
check 'editor caps' "$(wpe "\$u = wp_insert_user(['user_login' => 'cmw2_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']); wp_set_current_user(\$u);
    foreach (['delete-comment' => ['comment_id' => $A], 'create-comment' => ['post_id' => $P, 'content' => 'x'], 'update-core-db' => null, 'activate-plugin' => ['slug' => 'woocommerce'], 'install-theme' => ['slug' => 'x'], 'create-user' => ['username' => 'x', 'email' => 'x@example.com'], 'get-users' => [], 'create-term' => ['taxonomy' => 'nav_menu', 'name' => 'x']] as \$n => \$in) {
        echo \$n, '=', true === wp_get_ability(\"classic-monks/\$n\")->check_permissions(\$in) ? 1 : 0, ' ';
    }
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user(\$u);")" \
    'delete-comment=1 create-comment=1 update-core-db=0 activate-plugin=0 install-theme=0 create-user=0 get-users=0 create-term=0 '
check 'editor may create categories' "$(wpe "\$u = wp_insert_user(['user_login' => 'cmw2_editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']); wp_set_current_user(\$u);
    echo true === wp_get_ability('classic-monks/create-term')->check_permissions(['taxonomy' => 'category', 'name' => 'x']) ? 1 : 0;
    require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user(\$u);")" 1

wpe "wp_delete_post($P, true);" >/dev/null
done_checks
