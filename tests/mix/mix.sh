#!/usr/bin/env bash
# Every mix of the plugins that bundle the connector, on throwaway php -S sites: Classic Monks (Free), Classic Monks
# Pro and Monks Central, each alone, Free + Monks Central, Pro + Monks Central, and all three. Checks that exactly one
# connector boots, the highest version wins (a tie goes to the first plugin loaded), and an MCP grant made at the start
# keeps working, with its settings, while plugins are added, removed and switched.
# Run: bash tests/mix/mix.sh   (env: CM_PORT (8888), CM_KEEP=1 keeps the last site, CM_WP_VERSION, CM_FREE and CM_PRO (repo paths, default ../classic-monks-free
# and ../classic-monks); needs what tests/mcp/ci.sh needs, plus composer install and npm run build here.)
set -u
MC_DIR=$(cd "$(dirname "$0")/../.." && pwd)
FREE=${CM_FREE:-$MC_DIR/../classic-monks-free}
PRO=${CM_PRO:-$MC_DIR/../classic-monks}
PORT=${CM_PORT:-8888}
SITE=http://127.0.0.1:$PORT
MCP="$SITE/wp-json/classic-monks/v1/mcp"
WP_VERSION=${CM_WP_VERSION:-7.1.3}
CM_WP="php -d memory_limit=1G -d display_errors=stderr $(command -v wp)"
FAIL=0
PASSED=0
check() { # name, actual, expected
    if [[ $2 == "$3" ]]; then PASSED=$((PASSED + 1)); echo "PASS $1"; else FAIL=1; echo "FAIL $1: got $2, want $3"; fi
}
[[ -f $MC_DIR/vendor/autoload.php && -f $MC_DIR/build/admin.js ]] || { echo "run composer install and npm run build first"; exit 1; }

# site NAME: a fresh WordPress at $ROOT, served on $PORT, with the three plugins copied in (none active).
site() {
    [[ -n ${SERVER:-} ]] && { pkill -P "$SERVER"; kill "$SERVER" 2>/dev/null; wait "$SERVER" 2>/dev/null; }
    [[ -n ${ROOT:-} ]] && rm -rf "$ROOT"
    ROOT=$(mktemp -d)
    echo "::group::$1 ($ROOT)"
    w() { $CM_WP --path="$ROOT" "$@"; }
    w core download --version="$WP_VERSION" --quiet || exit 1
    curl -sSfL -o "$ROOT/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip || exit 1
    unzip -q "$ROOT/sqlite.zip" -d "$ROOT/wp-content/plugins" && rm "$ROOT/sqlite.zip"
    local sq="$ROOT/wp-content/plugins/sqlite-database-integration"
    sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$sq#" -e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' "$sq/db.copy" >"$ROOT/wp-content/db.php"
    w config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet --extra-php <<<"define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );"
    w core install --url="$SITE" --title='Mix' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
    w rewrite structure '/%postname%/' --quiet
    rsync -a --exclude=.git --exclude=node_modules --exclude=dist --exclude=tests "$MC_DIR/" "$ROOT/wp-content/plugins/monks-central/"
    rsync -a --exclude=.git --exclude=node_modules --exclude=dist --exclude=tests "$FREE/" "$ROOT/wp-content/plugins/classic-monks/"
    rsync -a --exclude=.git --exclude=node_modules --exclude=dist --exclude=tests --exclude=build "$PRO/" "$ROOT/wp-content/plugins/classic-monks-pro/"
    curl -s -o /dev/null "$SITE/" && { echo "port $PORT is already in use (set CM_PORT)"; exit 1; }
    PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$ROOT" "$MC_DIR/tests/mcp/router.php" >"$ROOT/server.log" 2>&1 &
    SERVER=$!
    for _ in $(seq 50); do curl -s -o /dev/null "$SITE/" && break; sleep 0.2; done
}
trap '[[ -n ${SERVER:-} ]] && { pkill -P $SERVER; kill $SERVER 2>/dev/null; }; [[ -n ${ROOT:-} && -z ${CM_KEEP:-} ]] && rm -rf "$ROOT"' EXIT
wpe() { $CM_WP --path="$ROOT" eval "$1" 2>/dev/null; }
on() { $CM_WP --path="$ROOT" plugin activate "$@" --quiet 2>/dev/null; }
off() { $CM_WP --path="$ROOT" plugin deactivate "$@" --quiet 2>/dev/null; }
# What booted: how many connector copies loaded, the running version and the plugin that runs it.
booted() {
    wpe '$n = count(array_filter(get_included_files(), function ($f) { return "/connector/connector.php" === substr($f, -24); }));
        echo $n, " ", defined("MONKS_CENTRAL_CONNECTOR_VERSION") && class_exists("Monks_Central_Connector") ? MONKS_CENTRAL_CONNECTOR_VERSION . " " . Monks_Central_Connector::host()["name"] : "none";'
}
# The MCP switch: every copy reads it from wp_options (Pro mirrors it into its own settings).
enable() { wpe 'update_option("cm_mcp_enabled", 1, false);'; }
# Pro's own tools are its AI abilities, so they load only with its AI module on (as on the live sites).
pro_ai() { wpe 'cm_update_option("enable_cm_ai", 1);'; }
# grant: an MCP connection for admin with scope "site" (as the app's operator grants are minted, an Application
# Password tagged with the MCP app id, which every copy accepts). Sets $AUTH and $UUID.
grant() {
    read -r UUID PASS <<<"$(wpe '$r = WP_Application_Passwords::create_new_application_password(1, ["name" => "Monks Central MCP · mix", "app_id" => "285c2739-211b-4f6f-98fb-121e7d24d97b"]); update_user_meta(1, "cm_mcp_scopes", [$r[1]["uuid"] => "site"]); echo $r[1]["uuid"], " ", $r[0];')"
    AUTH="admin:$PASS"
}
rpc() { curl -sk -u "$AUTH" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$1\",\"params\":${2:-{\}}}" "$MCP"; }
# tool NAME: "ok" when the tool ran (a result, and not a tool error; success carries no isError).
tool() { rpc tools/call "{\"name\":\"$1\",\"arguments\":{}}" | awk '/"result":\{"content"/ && !/"isError":true/ { ok = 1 } END { print ok ? "ok" : "failed" }'; }
# works LABEL: the grant still connects, keeps its scope, and runs a tool.
works() {
    check "$1: grant connects" "$(curl -sk -o /dev/null -w '%{http_code}' -u "$AUTH" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" 200
    check "$1: scope kept" "$(wpe "echo get_user_meta(1, 'cm_mcp_scopes', true)['$UUID'] ?? 'gone';")" site
    check "$1: get-hq-status runs" "$(tool get-hq-status)" ok
}
bump() { echo "<?php if ( ! defined( 'ABSPATH' ) ) { exit; } return '$2';" >"$ROOT/wp-content/plugins/$1/connector/version.php"; }
LIB=$(sed -n "s/^return '\(.*\)';/\1/p" "$MC_DIR/connector/version.php")

site 'Monks Central alone, then Free'
on monks-central; enable; grant
check 'MC alone boots one copy'        "$(booted)" "1 $LIB Monks Central"
works 'MC alone'
on classic-monks
check 'Free + MC: one copy, tie goes to the first loaded' "$(booted)" "1 $LIB Classic Monks"
works 'Free + MC'
bump monks-central 9.0.0
check 'Free + newer MC: MC runs'       "$(booted)" '1 9.0.0 Monks Central'
works 'Free + newer MC'
off monks-central
check 'Free alone after MC removed'    "$(booted)" "1 $LIB Classic Monks"
works 'Free alone'
echo "::endgroup::"

site 'Pro alone, then Monks Central'
on classic-monks-pro; enable; pro_ai; grant
check 'Pro alone boots one copy'       "$(booted)" "1 $LIB Classic Monks Pro"
check '... its switch is the shared one' "$(wpe 'global $wpdb; echo $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = \"cm_mcp_enabled\"");')" 1
works 'Pro alone'
check '... Pro tools served'          "$(tool get-features)" ok
on monks-central
check 'Pro + MC: one copy, tie goes to the first loaded' "$(booted)" "1 $LIB Classic Monks Pro"
works 'Pro + MC'
bump monks-central 9.0.0
check 'Pro + newer MC: MC runs'        "$(booted)" '1 9.0.0 Monks Central'
works 'Pro + newer MC'
check '... Pro tools still served'     "$(tool get-features)" ok
bump monks-central "$LIB"; bump classic-monks-pro 9.0.0
check 'newer Pro + MC: Pro runs'       "$(booted)" '1 9.0.0 Classic Monks Pro'
works 'newer Pro + MC'
off classic-monks-pro
check 'MC alone after Pro removed'     "$(booted)" "1 $LIB Monks Central"
works 'MC after Pro'
echo "::endgroup::"

site 'All three'
on classic-monks-pro; enable; pro_ai; grant
on monks-central classic-monks
# classic-monks-pro/ sorts before classic-monks/, so Pro loads first and Free stands down.
check 'all three: one Classic Monks, one copy' "$(booted)" "1 $LIB Classic Monks Pro"
check '... Free stands down'           "$(wpe 'echo function_exists("cm_hq_licence_status") ? "pro" : "free";')" pro
works 'all three'
check '... Pro tools served'          "$(tool get-features)" ok
off classic-monks-pro
check 'Free + MC after Pro removed'    "$(booted)" "1 $LIB Classic Monks"
works 'Free + MC (from all three)'
off classic-monks
check 'MC alone at the end'            "$(booted)" "1 $LIB Monks Central"
works 'MC alone (from all three)'
on classic-monks-pro
check 'switched back to Pro'           "$(booted)" "1 $LIB Classic Monks Pro"
works 'switched back to Pro'
echo "::endgroup::"

echo "$PASSED passed"
((FAIL)) && echo "SOME CHECKS FAILED" || echo "ALL PASSED"
exit $FAIL
