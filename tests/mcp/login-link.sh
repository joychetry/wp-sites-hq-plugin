#!/usr/bin/env bash
# Monks Central M3: create-login-link (operator grants only). Signs in as admin/admin. Run: bash tests/mcp/login-link.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"
ADMIN=${CM_ADMIN_USER:-admin}; ADMIN_PASS=${CM_ADMIN_PASS:-admin}
A2=$(wpe 'echo wp_insert_user(["user_login" => "cm_ll_admin2", "user_pass" => "cm-ll-admin2-pass", "role" => "administrator", "user_email" => "cm-ll-admin2@example.com"]);')
MU="$SITE_ROOT/wp-content/mu-plugins/zz-cm-ll-login.php"
trap 'rm -f "${OAUTH_JARS[@]}" "$MU"; wpe "require_once ABSPATH . \"wp-admin/includes/user.php\"; wp_delete_user($A2); delete_transient(\"cm_login_link_rate_1\"); delete_transient(\"cm_login_link_rate_$A2\");" >/dev/null; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
token_of() { sed -E 's/.*token=([a-z0-9_]+).*/\1/' <<<"$1"; }
post_link() { curl -sk -D - -o /dev/null --data-urlencode "token=$1" "$SITE/wp-login.php?action=cm_login_link"; }
link() { bcall "$1" create-login-link | jq -r '.result.structuredContent.url // .result.content[0].text'; }

T=$(connect "$ADMIN" "$ADMIN_PASS" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
check 'listed for the operator'              "$(brpc "$OP" tools/list)" '~"name":"create-login-link"'
check 'hidden from a full app password'      "$(rpc tools/list)" '!~"name":"create-login-link"'
check 'unknown tool for a full app password' "$(call create-login-link)" '~Unknown tool'
T2=$(connect "$ADMIN" "$ADMIN_PASS" 'Monks Central' -d scope=full -d 'toolsets[]=site')
check 'unknown tool for a non-operator grant' "$(bcall "$(jq -r .access_token <<<"$T2")" create-login-link)" '~Unknown tool'
revoke_grant "$(jq -r .family <<<"$T2")"
check 'no other user can be named'           "$(bcall "$OP" create-login-link '{"user_id":2}')" '~"isError":true'

URL=$(link "$OP")
check 'link points at wp-login'              "$URL" "~/wp-login.php?action=cm_login_link&token=cmll_"
TOK=$(token_of "$URL")
G=$(curl -sk -D - "$URL")
check 'GET does not sign in'                 "$G" '!~wordpress_logged_in'
check 'GET is no-referrer'                   "$(tr '[:upper:]' '[:lower:]' <<<"$G")" '~referrer-policy: no-referrer'
check 'GET page posts the token'             "$G" '~method="post"'
mkdir -p "$(dirname "$MU")" # records wp_login, which the link must fire
echo '<?php add_action( "wp_login", function ( $login, $user ) { update_user_meta( $user->ID, "cm_ll_test_login", time() ); }, 10, 2 );' >"$MU"
P=$(post_link "$TOK")
check 'POST signs in and redirects'          "$P" '~/wp-admin/'
check '... as the grant user'                "$P" "~=$ADMIN%7C"
check 'POST is no-referrer'                  "$(tr '[:upper:]' '[:lower:]' <<<"$P")" '~referrer-policy: no-referrer'
check 'fires wp_login (seen by a mu-plugin)' "$(wpe 'echo get_user_meta(1, "cm_ll_test_login", true) > time() - 60 ? "yes" : "no"; delete_user_meta(1, "cm_ll_test_login");')" yes
rm -f "$MU"
check 'use is logged on the grant'           "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT CONCAT(user_id, ' ', status, ' ', client_label) FROM {\$wpdb->prefix}cm_ai_log WHERE connection_uuid = 'oauth:$FAM' AND ability = 'classic-monks/use-login-link' ORDER BY id DESC LIMIT 1\");")" '1 ok Monks Central'
check 'second use refused'                   "$(post_link "$TOK")" '~HTTP/1.1 403'
check '... and not logged as a use'          "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_ai_log WHERE connection_uuid = 'oauth:$FAM' AND ability = 'classic-monks/use-login-link'\");")" 1
check 'a fresh link signs in'                "$(post_link "$(token_of "$(link "$OP")")")" "~=$ADMIN%7C"
check 'logged on the grant'                  "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT status FROM {\$wpdb->prefix}cm_ai_log WHERE connection_uuid = 'oauth:$FAM' AND ability = 'classic-monks/create-login-link' ORDER BY id DESC LIMIT 1\");")" ok
check 'log never holds the token'            "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_ai_log WHERE result_summary LIKE '%cmll_%' OR input_json LIKE '%cmll_%'\");")" 0

TOK=$(token_of "$(link "$OP")")
wpe "\$k = 'cm_login_link_' . hash('sha256', '$TOK'); \$d = get_transient(\$k); \$d['expires'] = time() - 1; set_transient(\$k, \$d, 60);" >/dev/null
check 'expired link refused'                 "$(post_link "$TOK")" '~HTTP/1.1 403'
check 'unknown token refused'                "$(post_link cmll_nope)" '~HTTP/1.1 403'

TOK=$(token_of "$(link "$OP")")
wpe "set_transient('cm_login_link_rate_1', 5, 600);" >/dev/null
check 'rate limited'                         "$(bcall "$OP" create-login-link)" '~cm_login_link_rate_limited'
wpe "delete_transient('cm_login_link_rate_1');" >/dev/null
revoke_grant "$FAM"
check 'revoked grant: link refused'          "$(post_link "$TOK")" '~HTTP/1.1 403'

# Another admin's operator grant signs in as that admin, never as the first one.
T=$(connect cm_ll_admin2 cm-ll-admin2-pass 'Monks Central' -d operator=1)
P=$(post_link "$(token_of "$(link "$(jq -r .access_token <<<"$T")")")")
check "second admin's link is theirs"        "$P" '~=cm_ll_admin2%7C'
revoke_grant "$(jq -r .family <<<"$T")"

# Not tested here: the HTTPS refusal (the test site is a local environment).
done_checks
