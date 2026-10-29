#!/usr/bin/env bash
# Monks Central M7 user tools over MCP: send-password-reset, the roles get-users offers, and delete-user
# handing content to another user. Run: bash tests/mcp/m7.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"

# Mail is caught, not sent: the test reads what would have gone out.
MU="$SITE_ROOT/wp-content/mu-plugins/cm-m7-test.php"
mkdir -p "$(dirname "$MU")"
cat >"$MU" <<'PHP'
<?php
add_filter( 'pre_wp_mail', function ( $r, $atts ) { update_option( 'cm_m7_mail', $atts ); return true; }, 10, 2 );
PHP
cleanup() {
    wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; foreach (get_users(['search' => 'cm_m7_*', 'search_columns' => ['user_login']]) as \$u) wp_delete_user(\$u->ID, 1); remove_role('cm_m7_role'); delete_option('cm_m7_mail');" >/dev/null
}
trap 'cleanup; rm -f "$MU" "${OAUTH_JARS[@]}"; revoke_grant "$FAM"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
cleanup
ok() { check "$1" "$2" '!~"isError":true'; }
err() { check "$1" "$2" '~"isError":true'; }

check 'send-password-reset is hidden from a full app password' "$(rpc tools/list)" '!~"name":"send-password-reset"'
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
rpc() { brpc "$OP" "$@"; }
check 'send-password-reset is listed for the operator' "$(rpc tools/list)" '~"name":"send-password-reset"'

# get-users: the roles the caller may give, and a filter by any role the site has.
wpe "add_role('cm_m7_role', 'M7 Role', ['read' => true]);" >/dev/null
U=$(wpe 'echo wp_insert_user(["user_login" => "cm_m7_user", "user_email" => "cm_m7_user@example.com", "user_pass" => wp_generate_password(), "role" => "cm_m7_role"]);')
R=$(call get-users)
ok 'get-users' "$R"
check 'get-users offers administrator to an admin' "$R" '~{"id":"administrator","name":"Administrator"}'
check 'get-users offers a custom role'            "$R" '~{"id":"cm_m7_role","name":"M7 Role"}'
R=$(call get-users '{"role":"cm_m7_role"}')
check 'get-users filters by a custom role'        "$R" '~"username":"cm_m7_user"'
check 'the filter keeps other users out'          "$R" '!~"username":"admin"'
err 'get-users refuses a role the site lacks'     "$(call get-users '{"role":"cm_no_such_role"}')"
E=$(wpe 'echo wp_insert_user(["user_login" => "cm_m7_editor", "user_email" => "cm_m7_editor@example.com", "user_pass" => wp_generate_password(), "role" => "editor"]);')
check 'an editor is offered no roles' "$(wpe "wp_set_current_user($E); echo wp_json_encode(cm_ai_assignable_roles());")" '[]'

# send-password-reset: WordPress' own reset email, with a fresh key; the password stays as it was.
HASH=$(wpe "echo get_userdata($U)->user_pass;")
R=$(call send-password-reset "{\"user_id\":$U}")
ok 'send-password-reset' "$R"
check 'send-password-reset says where it went' "$R" '~"email":"cm_m7_user@example.com"'
MAIL=$(wpe "echo wp_json_encode(get_option('cm_m7_mail'));")
check 'the email goes to the user'        "$MAIL" '~"to":"cm_m7_user@example.com"'
check 'the email has a reset link'        "$MAIL" '~action=rp'
check 'a reset key was stored'            "$(wpe "echo get_userdata($U)->user_activation_key ? 'yes' : 'no';")" yes
check 'the password did not change'       "$(wpe "echo get_userdata($U)->user_pass;")" "$HASH"
err 'an unknown user is refused'          "$(call send-password-reset '{"user_id":999999}')"
check 'an editor may not send one for an admin' "$(wpe "wp_set_current_user($E); var_export(wp_get_ability('classic-monks/send-password-reset')->check_permissions(['user_id' => 1]));")" false
check 'a site that turns resets off is respected' "$(wpe "add_filter('allow_password_reset', '__return_false'); wp_set_current_user(1); echo is_wp_error(wp_get_ability('classic-monks/send-password-reset')->execute(['user_id' => $U])) ? 'refused' : 'sent';")" refused

# delete-user with reassign: the content moves, and bad targets are refused before anything changes.
P=$(wpe "echo wp_insert_post(['post_title' => 'cm m7 post', 'post_status' => 'draft', 'post_author' => $U]);")
err 'reassigning to the same user is refused'   "$(call delete-user "{\"user_id\":$U,\"reassign\":$U}")"
err 'reassigning to a missing user is refused'  "$(call delete-user "{\"user_id\":$U,\"reassign\":999999}")"
err 'deleting yourself is refused'              "$(call delete-user "{\"user_id\":1,\"reassign\":$E}")"
check 'refused deletes kept the user'           "$(wpe "echo get_userdata($U) ? 'yes' : 'no';")" yes
R=$(call delete-user "{\"user_id\":$U,\"reassign\":$E}")
ok 'delete-user with reassign' "$R"
check 'delete-user says it deleted'             "$R" '~"deleted":true'
check 'the user is gone'                        "$(wpe "echo get_userdata($U) ? 'yes' : 'no';")" no
check 'their post went to the other user'       "$(wpe "echo get_post_field('post_author', $P);")" "$E"
wpe "wp_delete_post($P, true);" >/dev/null

done_checks
