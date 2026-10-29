#!/usr/bin/env bash
# Shared helpers for MCP checks. Source it: `. tests/mcp/lib.sh`.
# Mints a throwaway MCP connection (and a plain app password) for user 1 and revokes both on exit.
# The MCP connection is an Application Password tagged with Pro's MCP app_id, which the connector still accepts (a site
# moved from Pro keeps it); it saves an OAuth round trip per suite. oauth-lib.sh makes real OAuth grants.

SITE=${CM_SITE:-http://127.0.0.1:8889}
SITE_ROOT=${CM_SITE_ROOT:?set CM_SITE_ROOT to the WordPress root (ci.sh does)}
MCP="$SITE/wp-json/classic-monks/v1/mcp"
FAIL=0

WP_CMD=${CM_WP:-wp} # e.g. CM_WP="studio wp" on a Studio site

wpe() { (cd "$SITE_ROOT" && $WP_CMD eval "$1" 2>/dev/null); }

# Prints "login uuid password" for a new app password; $1 = app_id ('' for a non-MCP password).
mint() {
    wpe '$u = get_userdata(1); $r = WP_Application_Passwords::create_new_application_password(1, ["name" => "cm-test-" . wp_generate_password(6, false), "app_id" => "'"$1"'"]); echo $u->user_login, " ", $r[1]["uuid"], " ", $r[0];'
}
revoke() { wpe "WP_Application_Passwords::delete_application_password(1, '$1'); foreach (['cm_mcp_trusted', 'cm_mcp_scopes', 'cm_mcp_toolsets', 'cm_mcp_namespaces'] as \$k) { \$m = get_user_meta(1, \$k, true); if (is_array(\$m)) { unset(\$m['$1']); update_user_meta(1, \$k, \$m); } }" >/dev/null; }

read -r LOGIN MCP_UUID MCP_PASS <<<"$(mint 285c2739-211b-4f6f-98fb-121e7d24d97b)"
read -r _ PLAIN_UUID PLAIN_PASS <<<"$(mint '')"
trap 'revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
[[ -n $MCP_PASS && -n $PLAIN_PASS ]] || { echo "could not mint test credentials"; exit 1; }
# Suites start from full scope and every toolset; a connection with no saved settings is read-only.
wpe "foreach (['cm_mcp_scopes' => 'full', 'cm_mcp_toolsets' => array_keys(CM_MCP_Server::toolsets())] as \$k => \$v) { \$m = get_user_meta(1, \$k, true) ?: []; \$m['$MCP_UUID'] = \$v; update_user_meta(1, \$k, \$m); }" >/dev/null

AUTH="$LOGIN:$MCP_PASS"
PLAIN="$LOGIN:$PLAIN_PASS"

# rpc METHOD [PARAMS_JSON] [extra curl args...] -> response body
rpc() {
    local method=$1 params=${2:-'{}'}; shift 2 2>/dev/null
    # CM_XCM=1: also send X-CM-Authorization, like the setup snippets (for hosts that strip Authorization).
    curl -sk -u "$AUTH" ${CM_XCM:+-H "X-CM-Authorization: Basic $(printf %s "$AUTH" | base64)"} -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
        -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$method\",\"params\":$params}" "$@" "$MCP"
}
call() { local args=${2:-'{}'}; rpc tools/call "{\"name\":\"$1\",\"arguments\":$args}"; }
code() { curl -sk -o /dev/null -w '%{http_code}' "$@"; }

# login JAR [USER PASS]: signs in to wp-admin (default admin/admin, CM_ADMIN_USER / CM_ADMIN_PASS) with a cookie jar.
login() {
    curl -sk -c "$1" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null \
        --data-urlencode "log=${2:-${CM_ADMIN_USER:-admin}}" --data-urlencode "pwd=${3:-${CM_ADMIN_PASS:-admin}}" -d 'testcookie=1' "$SITE/wp-login.php"
}
MC_PAGE="$SITE/wp-admin/options-general.php?page=monks-central"
MC_API="$SITE/wp-json/monks-central/v1/connector"
# mc_rest JAR METHOD PATH [JSON]: calls the connection screen's REST route (PATH after /connector) as the jar's user,
# with a REST nonce for that session; prints the HTTP status, a space and the body.
mc_rest() {
    local jar=$1 method=$2 path=$3 body=${4:-} nonce out
    nonce=$(curl -sk -b "$jar" "$SITE/wp-admin/admin-ajax.php?action=rest-nonce")
    out=$(curl -sk -b "$jar" -X "$method" -H "X-WP-Nonce: $nonce" ${body:+-H 'Content-Type: application/json' -d "$body"} -w '\n%{http_code}' "$MC_API$path")
    echo "${out##*$'\n'} ${out%$'\n'*}"
}

check() { # name, actual, expected; "!x" = anything but x, "~x" = contains x
    local ok=0
    case $3 in
        '!~'*) [[ $2 != *"${3#\!\~}"* ]] && ok=1 ;;
        '!'*) [[ $2 != "${3#!}" ]] && ok=1 ;;
        '~'*) [[ $2 == *"${3#\~}"* ]] && ok=1 ;;
        *)    [[ $2 == "$3" ]] && ok=1 ;;
    esac
    if ((ok)); then echo "PASS $1"; else echo "FAIL $1: got ${2:0:300}, want $3"; FAIL=1; fi
}
done_checks() { ((FAIL)) && echo "SOME CHECKS FAILED" || echo "ALL PASSED"; exit $FAIL; }
