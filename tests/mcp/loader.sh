#!/usr/bin/env bash
# Version-wins loader: a second plugin (mc-fixture) bundles a newer copy of the connector (9.9.9). The newer copy runs,
# the other sleeps, existing grants keep working, and deactivating either plugin keeps the connections while the other
# still runs it. Run: bash tests/mcp/loader.sh
. "$(dirname "$0")/lib.sh"
P="$SITE_ROOT/wp-content/plugins"
FX="$P/mc-fixture"
wpcli() { (cd "$SITE_ROOT" && $WP_CMD "$@" >/dev/null 2>&1); }
JAR=$(mktemp)
trap 'wpcli plugin activate monks-central; wpcli plugin deactivate mc-fixture; rm -rf "$FX" "$JAR"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
ping() { code -u "$AUTH" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP"; }
running() { wpe 'echo MONKS_CENTRAL_CONNECTOR_VERSION, " ", Monks_Central_Connector::host()["name"];'; }

mkdir -p "$FX"
cp -r "$P/monks-central/connector" "$FX/connector"
echo "<?php if ( ! defined( 'ABSPATH' ) ) { exit; } return '9.9.9';" >"$FX/connector/version.php"
cat >"$FX/mc-fixture.php" <<'PHP'
<?php
/**
 * Plugin Name: MC Fixture
 * Description: Bundles a newer copy of the Monks Central connector (tests/mcp/loader.sh).
 * Version: 0.1.0
 */
require_once __DIR__ . '/connector/load.php';
monks_central_connector_register( array( 'dir' => __DIR__ . '/connector', 'file' => __FILE__, 'name' => 'MC Fixture', 'version' => '0.1.0', 'ui' => 'MonksCentral\Connector\Ui' ) );
add_action( 'monks_central_register_abilities', function ( $version ) {
	wp_register_ability( 'mc-fixture/hello', array( 'label' => 'Hello', 'description' => 'Says which connector asked.', 'category' => 'classic-monks', 'execute_callback' => function () use ( $version ) { return $version; }, 'permission_callback' => '__return_true' ) );
} );
PHP
login "$JAR"

check 'alone: the bundled copy runs'           "$(running)" '1.0.0 Monks Central'
wpcli plugin activate mc-fixture
check 'both active: the newer copy runs'       "$(running)" '9.9.9 MC Fixture'
check '... from its own folder'                "$(wpe 'echo MONKS_CENTRAL_CONNECTOR_DIR;')" "$FX/connector/"
check '... the older copy sleeps'              "$(wpe 'echo in_array(WP_PLUGIN_DIR . "/monks-central/connector/connector.php", get_included_files(), true) ? "loaded" : "asleep";')" asleep
check 'hosts keep their own versions'          "$(wpe 'echo wp_json_encode(array_map(function ($h) { return [$h["name"], $h["version"], $h["connector"]]; }, Monks_Central_Connector::state()["hosts"]));')" '[["MC Fixture","0.1.0","9.9.9"],["Monks Central","0.1.0","1.0.0"]]'
check 'host abilities registered via the hook' "$(wpe 'echo wp_get_ability("mc-fixture/hello")->execute();')" 9.9.9
check 'existing grant still works'             "$(ping)" 200
HQ=$(call get-hq-status)
check 'hq-status names the running plugin'     "$HQ" '~MC Fixture'
check 'screen: runs elsewhere'                 "$(mc_rest "$JAR" GET '' | cut -d' ' -f2- | jq -c '[.runs_here, .state.host, .state.version]')" '[false,"MC Fixture","9.9.9"]'

wpcli plugin deactivate monks-central
check 'other plugin deactivated: grant kept'   "$(ping)" 200
check '... still the newer copy'               "$(running)" '9.9.9 MC Fixture'
wpcli plugin activate monks-central
wpcli plugin deactivate mc-fixture
check 'newer plugin deactivated: grant kept'   "$(ping)" 200
check '... the bundled copy runs again'        "$(running)" '1.0.0 Monks Central'
check '... its host ability is gone'           "$(wpe 'echo wp_get_ability("mc-fixture/hello") ? "there" : "gone";')" gone

done_checks
