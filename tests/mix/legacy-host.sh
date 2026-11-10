#!/usr/bin/env bash
# A released Classic Monks Pro that bundles connector 1.x (old cm_ / CM_ names) next to this plugin, whose 2.x copy
# wins the loader: connector/compat-1x.php must give Pro the old names. Checks Pro's paths that call them (AI image
# import, search-content, create-post-summaries, undo data, live toasts, Logs > AI Activity, the old hook names) and
# that nothing lands in debug.log.
# Run: bash tests/mix/legacy-host.sh   (env: CM_PORT (8890), CM_KEEP=1 keeps the site, CM_WP_VERSION, CM_PRO (Pro repo,
# default ../classic-monks), CM_PRO_REV (default db7f368d, Pro with connector 1.2.1); needs what mix.sh needs.)
set -u
MC_DIR=$(cd "$(dirname "$0")/../.." && pwd)
PRO=${CM_PRO:-$MC_DIR/../classic-monks}
REV=${CM_PRO_REV:-db7f368d}
PORT=${CM_PORT:-8890}
SITE=http://127.0.0.1:$PORT
MCP="$SITE/wp-json/classic-monks/v1/mcp"
CM_WP="php -d memory_limit=1G -d display_errors=stderr $(command -v wp)"
FAIL=0
PASSED=0
check() { # name, actual, expected
    if [[ $2 == "$3" ]]; then PASSED=$((PASSED + 1)); echo "PASS $1"; else FAIL=1; echo "FAIL $1: got ${2:0:300}, want $3"; fi
}
[[ -f $MC_DIR/vendor/autoload.php && -f $MC_DIR/build/admin.js ]] || { echo "run composer install and npm run build first"; exit 1; }

ROOT=$(mktemp -d)
trap '[[ -n ${SERVER:-} ]] && { pkill -P $SERVER; kill $SERVER 2>/dev/null; }; [[ -z ${CM_KEEP:-} ]] && rm -rf "$ROOT"' EXIT
w() { $CM_WP --path="$ROOT" "$@"; }
wpe() { $CM_WP --path="$ROOT" eval "$1" 2>/dev/null; }
echo "site: $ROOT, Pro $REV"
w core download --version="${CM_WP_VERSION:-7.1.3}" --quiet || exit 1
curl -sSfL -o "$ROOT/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip || exit 1
unzip -q "$ROOT/sqlite.zip" -d "$ROOT/wp-content/plugins" && rm "$ROOT/sqlite.zip"
SQ="$ROOT/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#" -e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' "$SQ/db.copy" >"$ROOT/wp-content/db.php"
w config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet --extra-php <<<"define( 'WP_ENVIRONMENT_TYPE', 'local' ); define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', true ); define( 'WP_DEBUG_DISPLAY', false );"
w core install --url="$SITE" --title='Legacy host' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
w rewrite structure '/%postname%/' --quiet
rsync -a --exclude=.git --exclude=node_modules --exclude=dist --exclude=tests "$MC_DIR/" "$ROOT/wp-content/plugins/wp-sites-hq/"
mkdir "$ROOT/wp-content/plugins/classic-monks-pro"
git -C "$PRO" archive "$REV" | tar -x -C "$ROOT/wp-content/plugins/classic-monks-pro" --exclude=tests || exit 1
curl -s -o /dev/null "$SITE/" && { echo "port $PORT is already in use (set CM_PORT)"; exit 1; }
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$ROOT" "$MC_DIR/tests/mcp/router.php" >"$ROOT/server.log" 2>&1 &
SERVER=$!
for _ in $(seq 50); do curl -s -o /dev/null "$SITE/" && break; sleep 0.2; done

w plugin activate classic-monks-pro wp-sites-hq --quiet
wpe 'update_option("cm_mcp_enabled", 1, false); cm_update_option("enable_cm_ai", 1); cm_update_option("cm_ai_activity_log", 1);'
LIB=$(sed -n "s/^return '\(.*\)';/\1/p" "$MC_DIR/connector/version.php")
check 'this copy runs'              "$(wpe 'echo MONKS_CENTRAL_CONNECTOR_VERSION, " ", Monks_Central_Connector::host()["name"];')" "$LIB WP Sites HQ"
check "Pro's 1.x copy is registered" "$(wpe 'echo implode(",", array_map(function ($c) { return $c["connector"]; }, monks_central_connector_copies()));')" "1.2.1,$LIB"
check 'old names given'             "$(wpe 'echo class_exists("CM_MCP_Server") && class_exists("CM_AI_Activity_Log") && CM_MCP_APP_ID === WPSHQ_MCP_APP_ID && function_exists("cm_ai_prepare_image_file") && function_exists("cm_ai_safe_download") ? "yes" : "no";')" yes

# Pro's chat image path: requires the running copy's media-import-helpers.php, then calls cm_ai_prepare_image_file().
PNG=iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==
check 'AI image import'             "$(wpe "wp_set_current_user(1); \$r = cm_ai_import_base64_image('$PNG', 'legacy'); echo is_wp_error(\$r) ? \$r->get_error_message() : (is_array(\$r) && ! empty(\$r['attachment_id']) ? 'ok' : wp_json_encode(\$r));")" ok

# The old hook names still fire.
check 'cm_ai_log_reads honoured'    "$(wpe 'add_filter("cm_ai_log_reads", "__return_true"); echo apply_filters("wpshq_ai_log_reads", false) ? "yes" : "no";')" yes
check 'cm_theme_sandbox_check_url honoured' "$(wpe 'add_filter("cm_theme_sandbox_check_url", function () { return "x"; }); echo apply_filters("wpshq_theme_sandbox_check_url", "y");')" x
check 'cm_mcp_skills honoured'      "$(wpe 'add_filter("cm_mcp_skills", function ($s) { $s["legacy"] = 1; return $s; }); echo isset(apply_filters("wpshq_mcp_skills", array())["legacy"]) ? "yes" : "no";')" yes

read -r UUID PASS <<<"$(wpe '$r = WP_Application_Passwords::create_new_application_password(1, ["name" => "legacy", "app_id" => WPSHQ_MCP_APP_ID]); update_user_meta(1, "cm_mcp_scopes", [$r[1]["uuid"] => "site"]); echo $r[1]["uuid"], " ", $r[0];')"
AUTH="admin:$PASS"
wpe 'wp_insert_post(["post_title" => "Legacy probe", "post_content" => "legacy body text", "post_status" => "publish"]);' >/dev/null
rpc() { curl -sk -u "$AUTH" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"$1\",\"arguments\":$2}}" "$MCP"; }
tool() { local out; out=$(rpc "$1" "$2"); [[ $out == *'"result":{"content"'* && $out != *'"isError":true'* ]] && echo ok || echo "${out:0:300}"; }
check 'search-content'              "$(tool search-content '{"query":"legacy"}')" ok
check 'create-post-summaries'       "$(tool create-post-summaries '{"post_ids":[1]}')" ok
check 'get-features (Pro tool)'     "$(tool get-features '{}')" ok
check 'create-menu (Pro write)'     "$(tool create-menu '{"name":"Legacy menu"}')" ok
check '... its undo data is written' "$(wpe 'global $wpdb; $u = $wpdb->get_var("SELECT undo_json FROM {$wpdb->prefix}cm_ai_log WHERE ability = \"classic-monks/create-menu\" ORDER BY id DESC LIMIT 1"); echo empty(json_decode((string) $u, true)["menu_id"]) ? "none: $u" : "yes";')" yes
check '... Pro live toast queued (cm_ai_operation_logged)' "$(wpe 'echo count((array) get_transient(CM_AI_LIVE_TRANSIENT)) > 0 ? "yes" : "no";')" yes

# Logs > AI Activity: rows load over AJAX with Pro's settings nonce.
JAR=$ROOT/cookies
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$SITE/wp-login.php"
curl -s -c "$JAR" -b "$JAR" -o /dev/null -d 'log=admin&pwd=admin&wp-submit=Log+In&testcookie=1' "$SITE/wp-login.php"
PAGE=$(curl -s -b "$JAR" "$SITE/wp-admin/admin.php?page=classic-monks&tab=logs")
check 'Pro logs tab renders'        "$(grep -c 'critical error' <<<"$PAGE")" 0
LOG=failed
for n in $(grep -o '"nonce":"[a-f0-9]*"' <<<"$PAGE" | cut -d'"' -f4 | sort -u); do
    r=$(curl -s -b "$JAR" -d "action=cm_ai_log_list&nonce=$n" "$SITE/wp-admin/admin-ajax.php")
    [[ $r == *'"success":true'* ]] && { [[ $r == *create-menu* ]] && LOG=ok || LOG="no row: ${r:0:200}"; break; }
done
check 'AI Activity list loads'      "$LOG" ok
check 'Pro AI tab renders'          "$(curl -s -b "$JAR" "$SITE/wp-admin/admin.php?page=classic-monks&tab=ai" | grep -c 'critical error')" 0

ERRS=$(grep -hE 'PHP (Fatal|Parse|Warning|Notice|Deprecated)|Uncaught|Call to undefined' "$ROOT/wp-content/debug.log" "$ROOT/server.log" 2>/dev/null)
check 'no PHP errors or notices'    "${ERRS:-none}" none

echo "$PASSED passed"
((FAIL)) && echo "SOME CHECKS FAILED" || echo "ALL PASSED"
exit $FAIL
