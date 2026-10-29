#!/usr/bin/env bash
# Release test: the built zip on a clean WordPress 7.1, connected by the Monks Central app, then updated from a local
# fake GitHub release (a wrong checksum is refused first, then the right one installs and the connection survives).
# Run: bin/build-zip.sh && bash tests/release/fresh-site.sh <monks-central app checkout>
#   The app checkout needs `cargo build --release -p monks-cli` (target/release/monks) and
#   `cargo build -p monks-core --example acceptance`. Needs php (pdo_sqlite), wp-cli, curl, unzip, jq, python3.
#   Env: CM_WP_VERSION (7.1.3), CM_PORT (8888) for the site, CM_RELEASE_PORT (8889) for the fake release.
# Everything lives in one temp folder, the app's data folder too; its keychain item is deleted at the end.
set -euo pipefail
PLUGIN_DIR=$(cd "$(dirname "$0")/../.." && pwd)
APP=$(cd "${1:?usage: fresh-site.sh <monks-central app checkout>}" && pwd)
ZIP=$PLUGIN_DIR/dist/monks-central.zip
PORT=${CM_PORT:-8888}
RPORT=${CM_RELEASE_PORT:-8889}
SITE=http://127.0.0.1:$PORT
WORK=$(mktemp -d)
ROOT=$WORK/site
REL=$WORK/release
export MONKS_HOME=$WORK/app
# Its own WP-CLI cache, so a zip cached by an earlier run is never reused.
export WP_CLI_CACHE_DIR=$WORK/wp-cli-cache
WP="php -d memory_limit=1G -d display_errors=stderr $(command -v wp)"
w() { $WP --path="$ROOT" "$@"; }
drive() { "$APP/target/debug/examples/acceptance" "$@"; }

[[ -f $ZIP ]] || { echo "run bin/build-zip.sh first"; exit 1; }
for p in "$PORT" "$RPORT"; do ! curl -s -o /dev/null "http://127.0.0.1:$p/" || { echo "port $p is in use"; exit 1; }; done

FAIL=0
check() { if [[ $2 == "$3" ]]; then echo "PASS $1"; else echo "FAIL $1: got [$2], want [$3]"; FAIL=1; fi; }
has() { if [[ $2 == *"$3"* ]]; then echo "PASS $1"; else echo "FAIL $1: [$2] lacks [$3]"; FAIL=1; fi; }

# A clean site: WordPress, SQLite, nothing else.
mkdir -p "$ROOT" "$REL/releases" "$WORK/bin"
w core download --version="${CM_WP_VERSION:-7.1.3}" --quiet
curl -sSfL -o "$WORK/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -q "$WORK/sqlite.zip" -d "$ROOT/wp-content/plugins"
SQ="$ROOT/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#" -e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' "$SQ/db.copy" >"$ROOT/wp-content/db.php"
w config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
w core install --url="$SITE" --title='Monks Central fresh site' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
w rewrite structure '/%postname%/' --quiet

PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$ROOT" "$PLUGIN_DIR/tests/mcp/router.php" >"$WORK/site.log" 2>&1 &
SERVER=$!
(cd "$REL" && exec python3 -m http.server "$RPORT" --bind 127.0.0.1) >"$WORK/release.log" 2>&1 &
RSERVER=$!
cleanup() {
    drive forget-key >/dev/null 2>&1 || true
    pkill -P $SERVER 2>/dev/null; kill $SERVER $RSERVER 2>/dev/null
    echo "Site and logs kept in $WORK"
}
trap cleanup EXIT
for _ in $(seq 50); do curl -s -o /dev/null "$SITE/" && curl -s -o /dev/null "http://127.0.0.1:$RPORT/" && break; sleep 0.2; done

echo "== Install the built zip"
w plugin install "$ZIP" --activate --quiet
check 'installed from the zip, active' "$(w plugin get monks-central --field=status) $(w plugin get monks-central --field=version)" 'active 0.1.0'
# WP-CLI's own deprecation notices land in debug.log as well; only the site's count.
site_errors() { grep -v 'phar://' "$ROOT/wp-content/debug.log" 2>/dev/null || true; }
check 'no PHP errors on activation' "$(site_errors)" ''
check 'Connect to Monks Central is off on a new install' "$(curl -s -o /dev/null -w '%{http_code}' "$SITE/wp-json/classic-monks/v1/mcp")" 404
# The admin's switch on Settings > Monks Central, through its REST route.
w user application-password create admin fresh-site-test --porcelain >"$WORK/admin.pass"
curl -sf -u "admin:$(cat "$WORK/admin.pass")" -H 'Content-Type: application/json' -d '{"enabled":true}' "$SITE/wp-json/monks-central/v1/connector" >/dev/null
w user application-password delete admin "$(w user application-password list admin --name=fresh-site-test --field=uuid)" --quiet
check 'the admin turned it on' "$(w option get cm_mcp_enabled)" 1

echo "== Connect the app"
# The driver signs in as the site's admin with a login cookie from `studio wp`; this shim answers with plain WP-CLI.
# Studio sites are HTTPS; on this plain-HTTP site wp-admin reads the auth cookie instead of secure_auth.
cat >"$WORK/bin/studio" <<SHIM
#!/usr/bin/env bash
[[ \$1 == wp ]] && shift
[[ \$1 == --path ]] && set -- "--path=\$2" "\${@:3}"
a=("\${@//SECURE_AUTH_COOKIE/AUTH_COOKIE}")
exec $WP "\${a[@]//secure_auth/auth}"
SHIM
chmod +x "$WORK/bin/studio"
doctor=$("$APP/target/release/monks" doctor "$SITE" 2>&1) || true
echo "$doctor"
has 'monks doctor reaches the site' "$doctor" "$SITE answered 200"
has 'monks doctor passes' "$doctor" 'All checks passed.'
connect=$(PATH=$WORK/bin:$PATH drive connect-oauth "$SITE" "$ROOT" 2>&1) || true
echo "$connect"
has 'the app connected through OAuth as operator' "$connect" 'operator true'
check 'the site has one connection' "$(w --user=admin eval 'echo count( Monks_Central_Connector::connections() );')" 1
plugins=$(drive plugins 2>&1) || true
has 'the app sees Monks Central 0.1.0 and connector 1.1.0' "$plugins" 'plugin Monks Central 0.1.0  connector 1.1.0'
has 'the app check succeeds' "$(drive check 2>&1)" 'health Ok'

echo "== Update from a fake release"
# Version 0.1.1 of the same build, served like GitHub serves a release.
mkdir -p "$WORK/next" && unzip -q "$ZIP" -d "$WORK/next"
sed -i -e 's/^\( \* Version: *\)0\.1\.0$/\10.1.1/' -e "s/'MONKS_CENTRAL_VERSION', '0\.1\.0'/'MONKS_CENTRAL_VERSION', '0.1.1'/" "$WORK/next/monks-central/monks-central.php"
(cd "$WORK/next" && zip -qr "$REL/monks-central.zip" monks-central)
jq -n --arg u "http://127.0.0.1:$RPORT" '{tag_name: "v0.1.1", draft: false, prerelease: false, html_url: ($u + "/release"), body: "Fake release for the update test.",
    assets: [{name: "monks-central.zip", browser_download_url: ($u + "/monks-central.zip")}, {name: "monks-central.zip.sha256", browser_download_url: ($u + "/monks-central.zip.sha256")}]}' >"$REL/releases/latest"
# The site asks api.github.com; a test-only mu-plugin sends that one request to the fake and lets WordPress download from it.
mkdir -p "$ROOT/wp-content/mu-plugins"
cat >"$ROOT/wp-content/mu-plugins/fake-github-release.php" <<PHP
<?php
add_filter( 'pre_http_request', function ( \$pre, \$args, \$url ) {
	return 'https://api.github.com/repos/joychetry/monks-central-wp/releases/latest' === \$url ? wp_remote_get( 'http://127.0.0.1:$RPORT/releases/latest' ) : \$pre;
}, 10, 3 );
add_filter( 'http_request_host_is_external', fn( \$external, \$host ) => '127.0.0.1' === \$host || \$external, 10, 2 );
add_filter( 'http_allowed_safe_ports', fn( \$ports ) => array_merge( \$ports, array( $RPORT ) ) );
PHP
fresh() { w transient delete monks_central_release --network --quiet; w transient delete update_plugins --network --quiet; }

echo "0000000000000000000000000000000000000000000000000000000000000000  monks-central.zip" >"$REL/monks-central.zip.sha256"
fresh
check 'wp-admin offers 0.1.1' "$(w plugin list --name=monks-central --field=update_version)" 0.1.1
bad=$(w plugin update monks-central 2>&1 || true)
has 'a zip that does not match its checksum is refused' "$bad" 'does not match its SHA-256 checksum'
check '... and 0.1.0 stays' "$(w plugin get monks-central --field=version)" 0.1.0

(cd "$REL" && sha256sum monks-central.zip >monks-central.zip.sha256)
fresh
w plugin update monks-central --quiet
check 'the matching zip installs 0.1.1' "$(w plugin get monks-central --field=status) $(w plugin get monks-central --field=version)" 'active 0.1.1'
check 'the connector stays on' "$(w option get cm_mcp_enabled)" 1
check 'the connection survives the update' "$(w --user=admin eval 'echo count( Monks_Central_Connector::connections() );')" 1
has 'the app still checks the site' "$(drive check 2>&1)" 'health Ok'
has 'the app sees 0.1.1' "$(drive plugins 2>&1)" 'plugin Monks Central 0.1.1  connector 1.1.0'
check 'no PHP errors' "$(site_errors)" ''

((FAIL)) && { echo "FAILED"; exit 1; }
echo "ALL PASSED"
