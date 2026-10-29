#!/usr/bin/env bash
# Monks Central M10 safe update helpers over MCP: get-http-status, get-error-log-since, set-maintenance-mode and
# update-plugin's safe mode (roll back an update that fatals). Run: bash tests/mcp/m10.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"

PF=cm-m10-fixture/cm-m10-fixture.php
PL="$SITE_ROOT/wp-content/plugins/cm-m10-fixture"
UP="$SITE_ROOT/wp-content/uploads/cm-m10"
MU="$SITE_ROOT/wp-content/mu-plugins/cm-m10-test.php"
LOG="$SITE_ROOT/wp-content/debug.log"
SAVED="$LOG.cm-m10"
# The update offered (option cm_m10_offer) comes from a zip in uploads, so the site downloads it from itself.
# ?cm_m10_fatal=1 makes any page fatal after the plugins load.
mkdir -p "$(dirname "$MU")" "$UP"
cat >"$MU" <<'PHP'
<?php
$cm_m10_offer = function ( $t ) {
    $o = get_option( 'cm_m10_offer' );
    if ( ! $o ) {
        return $t;
    }
    $t = is_object( $t ) ? $t : (object) array( 'last_checked' => time(), 'checked' => array(), 'response' => array(), 'translations' => array(), 'no_update' => array() );
    $t->response = (array) ( $t->response ?? array() );
    $t->response['cm-m10-fixture/cm-m10-fixture.php'] = (object) array( 'slug' => 'cm-m10-fixture', 'plugin' => 'cm-m10-fixture/cm-m10-fixture.php', 'new_version' => $o['version'], 'package' => $o['package'] );
    return $t;
};
add_filter( 'site_transient_update_plugins', $cm_m10_offer );
add_filter( 'pre_set_site_transient_update_plugins', $cm_m10_offer );
// Fails an install after WordPress turned the plugin off for it, as a failed copy would.
add_filter( 'upgrader_source_selection', function ( $source ) {
    return get_option( 'cm_m10_fail' ) ? new WP_Error( 'cm_m10_fail', 'cm m10 failed install' ) : $source;
} );
// Clears the fatal before Classic Monks or WordPress' scrape reads it: then only the error log has it.
if ( get_option( 'cm_m10_clear' ) ) {
    add_action( 'shutdown', 'error_clear_last', PHP_INT_MIN, 0 );
}
// A notice at shutdown replaces the fatal in error_get_last() before WordPress prints the scrape, as WooCommerce's can.
if ( isset( $_GET['cm_m10_fatal'] ) ) {
    add_action( 'init', function () { cm_m10_no_such_function(); } );
    add_action( 'shutdown', function () { trigger_error( 'cm m10 notice at shutdown' ); } );
}
PHP
header() { printf '<?php\n/**\n * Plugin Name: CM M10 Fixture\n * Version: %s\n */\n' "$1"; }
# mkzip NAME VERSION [EXTRA_PHP]: the fixture at VERSION in uploads/cm-m10/NAME.zip.
mkzip() {
    php -r '$z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE); $z->addFromString("cm-m10-fixture/cm-m10-fixture.php", $argv[2]); $z->close();' \
        "$UP/$1.zip" "$(header "$2")$3"
}
mkzip broken 1.1 $'\ncm_m10_undefined_function();\n'
mkzip good 1.1
offer() { wpe "update_option('cm_m10_offer', ['version' => '1.1', 'package' => '$SITE/wp-content/uploads/cm-m10/$1.zip']); delete_site_transient('update_plugins');" >/dev/null; }
# fixture [inactive]: version 1.0 on disk, active unless asked.
fixture() {
    rm -rf "$PL"; mkdir -p "$PL"; header 1.0 >"$PL/cm-m10-fixture.php"
    if [[ -n ${1:-} ]]; then wpe "deactivate_plugins('$PF', true);"; else wpe "wp_clean_plugins_cache(); activate_plugin('$PF');"; fi >/dev/null
}
cleanup() {
    wpe "deactivate_plugins('$PF', true); delete_option('cm_m10_offer'); delete_option('cm_m10_fail'); delete_option('cm_m10_clear'); delete_site_transient('update_plugins');" >/dev/null
    rm -rf "$PL" "$UP" "$SITE_ROOT/.maintenance" "$SITE_ROOT/wp-content/upgrade-temp-backup/plugins/cm-m10-fixture"
    # Put back the log the rotation check moved aside, with whatever was written since.
    [[ -f $SAVED ]] && { cat "$LOG" >>"$SAVED" 2>/dev/null; mv "$SAVED" "$LOG"; }
}
trap 'cleanup; rm -f "$MU" "${OAUTH_JARS[@]}"; revoke_grant "$FAM"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
fixture
ok() { check "$1" "$2" '!~"isError":true'; }
err() { check "$1" "$2" '~"isError":true'; }
sc() { jq -r ".result.structuredContent | $2" <<<"$1"; }
active() { wpe "echo is_plugin_active('$PF') ? 'yes' : 'no';"; }
disk() { sed -n 's/^ \* Version: //p' "$PL/cm-m10-fixture.php"; }
up() { call update-plugin "{\"plugin_file\":\"$PF\",\"force_update_check\":false$1}"; }

L=$(rpc tools/list)
for t in get-http-status get-error-log-since set-maintenance-mode; do
    check "$t is hidden from a full app password" "$L" "!~\"name\":\"$t\""
done
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
rpc() { brpc "$OP" "$@"; }
L=$(rpc tools/list)
for t in get-http-status get-error-log-since set-maintenance-mode; do
    check "$t is listed for the operator" "$L" "~\"name\":\"$t\""
done
check 'update-plugin offers safe' "$(jq -c '.result.tools[] | select(.name == "update-plugin") | .inputSchema.properties.safe.type' <<<"$L")" '"boolean"'

# get-http-status: the home page, a page that fatals (the scrape shows it although the page hides it), bad paths.
R=$(call get-http-status)
ok 'get-http-status' "$R"
check 'home answers 200 and ok' "$(sc "$R" '[.url, .status, .ok, .fatal, .error, .maintenance] | @json')" "[\"$SITE/\",200,true,null,null,false]"
R=$(call get-http-status '{"path":"/?cm_m10_fatal=1"}')
check 'a fatal page is not ok' "$(sc "$R" '.ok')" false
check 'the fatal is reported as PHP logs it' "$(sc "$R" '.fatal')" '~PHP Fatal error: Uncaught Error: Call to undefined function cm_m10_no_such_function() in ABSPATH/wp-content/mu-plugins/cm-m10-test.php on line '
check 'the server path is masked' "$(sc "$R" '.fatal')" "!~$SITE_ROOT"
check 'the fatal page answers 500' "$(sc "$R" '.status')" 500
for p in 'shop' '//evil.example/' 'http://evil.example/' '/\\evil.example' '/a b'; do
    err "get-http-status refuses path $p" "$(call get-http-status "$(jq -nc --arg p "$p" '{path: $p}')")"
done

# get-error-log-since: a marker, then only the new lines, masked and redacted, with fatal and warning counts.
check 'WP_DEBUG_LOG is on in this site' "$(wpe 'echo cm_ai_error_log_path() === WP_CONTENT_DIR . "/debug.log" ? "yes" : "no";')" yes
touch "$LOG"
R=$(call get-error-log-since)
ok 'get-error-log-since marker' "$R"
M=$(sc "$R" '.offset')
check 'the marker is the log size' "$M" "$(stat -c %s "$LOG")"
check 'the marker has no lines' "$(sc "$R" '[.available, .lines] | @json')" '[true,[]]'
{
    echo "[28-Oct-2026 10:00:00 UTC] PHP Warning:  cm m10 warning in $SITE_ROOT/wp-content/plugins/x/x.php on line 3"
    echo "[28-Oct-2026 10:00:01 UTC] PHP Fatal error:  cm m10 fatal in $SITE_ROOT/wp-content/plugins/x/x.php on line 5"
    echo "[28-Oct-2026 10:00:02 UTC] cm m10 sent Bearer abcdef0123456789"
} >>"$LOG"
R=$(call get-error-log-since "{\"offset\":$M}")
check 'only the new lines' "$(sc "$R" '.lines | length')" 3
check 'counts the fatal and the warning' "$(sc "$R" '[.fatal, .warnings] | @json')" '[1,1]'
check 'paths are masked' "$(sc "$R" '.lines[1]')" '~cm m10 fatal in ABSPATH/wp-content/plugins/x/x.php on line 5'
check 'secrets are redacted' "$(sc "$R" '.lines[2]')" '~Bearer [redacted]'
check 'the new offset is the size now' "$(sc "$R" '.offset')" "$(stat -c %s "$LOG")"
check 'nothing new since then' "$(sc "$(call get-error-log-since "{\"offset\":$(sc "$R" '.offset')}")" '.lines | length')" 0
err 'a negative offset is refused' "$(call get-error-log-since '{"offset":-1}')"
# Rotation: a log smaller than the marker is read from the start.
mv "$LOG" "$SAVED"; echo "[28-Oct-2026 10:00:03 UTC] PHP Parse error:  cm m10 after rotation" >"$LOG"
R=$(call get-error-log-since "{\"offset\":$M}")
check 'a rotated log is read from the start' "$(sc "$R" '[(.lines | length), .fatal] | @json')" '[1,1]'
cat "$LOG" >>"$SAVED"; mv "$SAVED" "$LOG"

# set-maintenance-mode outside Monks Central's connection never runs, and isn't in the REST list.
check 'maintenance mode is not in the REST list' "$(wpe "var_export(wp_get_ability('classic-monks/set-maintenance-mode')->get_meta_item('show_in_rest'));")" false
check 'nor run for an administrator outside MCP' "$(wpe "wp_set_current_user(1); \$r = wp_get_ability('classic-monks/set-maintenance-mode')->execute(['on' => true]); echo is_wp_error(\$r) ? \$r->get_error_code() : 'ran';")" cm_operator_only

# set-maintenance-mode: every request gets 503 (MCP too) unless it carries the bypass; get-http-status gets through.
R=$(call set-maintenance-mode '{"on":true}')
check 'maintenance on' "$(sc "$R" '.on')" true
check '.maintenance written' "$([[ -f $SITE_ROOT/.maintenance ]] && echo yes)" yes
check 'home answers 503 in maintenance' "$(code "$SITE/")" 503
B=$(sc "$R" '.bypass')
check 'MCP answers 503 without the bypass' "$(code -H "Authorization: Bearer $OP" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$MCP")" 503
R=$(MCP="$MCP?$B" call get-http-status)
check 'get-http-status gets past maintenance' "$(sc "$R" '[.status, .ok, .maintenance] | @json')" '[200,true,true]'
R=$(MCP="$MCP?$B" call set-maintenance-mode '{"on":false}')
check 'maintenance off' "$(sc "$R" '.on')" false
check '.maintenance removed' "$([[ -f $SITE_ROOT/.maintenance ]] && echo yes || echo no)" no
check 'home answers 200 again' "$(code "$SITE/")" 200
err 'set-maintenance-mode needs on' "$(call set-maintenance-mode)"

# update-plugin safe: an update that fatals on load is rolled back in the same request.
offer broken
R=$(up ',"safe":true')
ok 'safe update of a broken version answers' "$R"
check 'it was rolled back' "$(sc "$R" '[.updated, .rolled_back, .checked, .previous_version, .version, .active] | @json')" '[false,true,true,"1.0","1.0",true]'
check 'the reason names the fatal' "$(sc "$R" '.reason')" '~PHP Fatal error: Uncaught Error: Call to undefined function cm_m10_undefined_function() in ABSPATH/wp-content/plugins/cm-m10-fixture/cm-m10-fixture.php'
check 'the message says it was put back' "$(sc "$R" '.message')" '~The update broke the site, so the previous version was put back. The home page answered'
check 'version 1.0 is back on disk' "$(disk)" 1.0
check 'the plugin is still active' "$(active)" yes
check 'home answers 200 after the rollback' "$(code "$SITE/")" 200
check 'no .maintenance left' "$([[ -f $SITE_ROOT/.maintenance ]] && echo yes || echo no)" no
check 'the update list is read again after the rollback' "$(wpe '$t = get_option("_site_transient_update_plugins"); echo empty( $t->last_checked ) ? "no" : "yes";')" yes
# When no scrape has the fatal, the reason still names it, from the error log.
wpe "update_option('cm_m10_clear', 1);" >/dev/null
R=$(up ',"safe":true')
check 'rolled back when the scrape misses the fatal' "$(sc "$R" '[.rolled_back, .status, .version] | @json')" '[true,500,"1.0"]'
check 'the reason names the fatal from the log' "$(sc "$R" '.reason')" '~The home page answered 500 after the update: PHP Fatal error: Uncaught Error: Call to undefined function cm_m10_undefined_function()'
wpe "delete_option('cm_m10_clear');" >/dev/null

# A good version: updated and checked.
offer good
R=$(up ',"safe":true')
check 'a good version is updated and checked' "$(sc "$R" '[.updated, .checked, .rolled_back, .status, .version, .active] | @json')" '[true,true,false,200,"1.1",true]'
check 'version 1.1 on disk' "$(disk)" 1.1
check 'no .maintenance after a good update' "$([[ -f $SITE_ROOT/.maintenance ]] && echo yes || echo no)" no

# Inactive: nothing loads it, so nothing is checked.
fixture inactive
check 'the fixture is inactive' "$(active)" no
R=$(up ',"safe":true')
check 'safe on an inactive plugin updates unchecked' "$(sc "$R" '[.updated, .checked, .version, .active] | @json')" '[true,false,"1.1",false]'

# Without safe: as before (activated again in this request, no check fields).
fixture
R=$(up)
check 'without safe it updates as before' "$(sc "$R" '[.updated, .version, .active, has("checked"), has("rolled_back")] | @json')" '[true,"1.1",true,false,false]'

# An update that fails after WordPress turned the plugin off leaves it on, with and without safe.
fixture
offer good
wpe "update_option('cm_m10_fail', 1);" >/dev/null
err 'a failing update fails' "$(up)"
check 'the plugin stays active after a failed update' "$(active)" yes
fixture
err 'a failing safe update fails' "$(up ',"safe":true')"
check 'the plugin stays active after a failed safe update' "$(active)" yes
check 'no .maintenance after a failed safe update' "$([[ -f $SITE_ROOT/.maintenance ]] && echo yes || echo no)" no

done_checks
