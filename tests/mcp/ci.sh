#!/usr/bin/env bash
# Boots a throwaway WordPress (SQLite, PHP built-in server, like Studio) with this plugin and runs the Monks Central suites.
# Runs locally; needs php (pdo_sqlite), wp-cli, curl, unzip, jq, openssl, python3, rsync.
# Run: bash tests/mcp/ci.sh [suite ...]   (default: every suite below; env: CM_WP_VERSION, CM_PORT, CM_SITE_ROOT, CM_WOO, CM_ELEMENTOR)
# CM_WOO=1 installs WooCommerce, CM_ELEMENTOR=1 Elementor; CM_BRICKS_ZIP=<path to the Bricks theme zip> installs and activates
# Bricks. Bricks is licensed: pass a local zip, never commit it. content.sh then also checks their post types.
set -u
PLUGIN_DIR=$(cd "$(dirname "$0")/../.." && pwd)
# deactivate.sh and uninstall.sh go last: they deactivate and uninstall the plugin.
# Below 7.1 the connector is off, so the default is legacy.sh, which checks that.
WP_VERSION=${CM_WP_VERSION:-7.1.3}
SUITES=("$@")
((${#SUITES[@]})) || [[ $(printf '%s\n' 7.1 "$WP_VERSION" | sort -V | head -1) == 7.1 ]] || SUITES=(legacy)
((${#SUITES[@]})) || SUITES=(annotations smoke protocol approvals trust site wrappers wrappers2 content m6 m7 m10 m8 media2 acf oauth hq-status pending-approvals revoke oauth-grace operator login-link updates snapshot admin-page loader standdown deactivate uninstall)

PORT=${CM_PORT:-8889}
export CM_SITE_ROOT=${CM_SITE_ROOT:-$(mktemp -d)}
export CM_SITE=http://127.0.0.1:$PORT
# Through php so the ini applies: the core tarball needs >128M to unpack, and notices must stay off stdout (suites parse it).
export CM_WP="php -d memory_limit=1G -d display_errors=stderr $(command -v wp)"
ROOT=$CM_SITE_ROOT
w() { $CM_WP --path="$ROOT" "$@"; }

set -e
w core download --version="$WP_VERSION" --quiet
curl -sSfL -o "$ROOT/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
unzip -q "$ROOT/sqlite.zip" -d "$ROOT/wp-content/plugins" && rm "$ROOT/sqlite.zip"
SQ="$ROOT/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#" -e 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#' "$SQ/db.copy" >"$ROOT/wp-content/db.php"
# OAuth tokens need HTTPS or a local environment; notices must not leak into JSON responses.
w config create --dbname=wp --dbuser=wp --dbpass=wp --skip-check --quiet --extra-php <<'PHP'
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
w core install --url="$CM_SITE" --title='Monks Central CI' --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
w rewrite structure '/%postname%/' --quiet
# The plugin runs from vendor/ (autoload) and build/ (screens): composer install and npm run build first.
[[ -f $PLUGIN_DIR/vendor/autoload.php && -f $PLUGIN_DIR/build/admin.js ]] || { echo "run composer install and npm run build first"; exit 1; }
rsync -a --exclude=.git --exclude=node_modules --exclude=dist --exclude=tests "$PLUGIN_DIR/" "$ROOT/wp-content/plugins/monks-central/"
if [[ $(printf '%s\n' 7.1 "$WP_VERSION" | sort -V | head -1) == 7.1 ]]; then
    w plugin activate monks-central --quiet
else
    # Requires at least 7.1 blocks activation; legacy.sh covers a site that was active before, on older WordPress.
    w option update active_plugins '["monks-central/monks-central.php"]' --format=json --quiet
fi
[[ -z ${CM_WOO:-} ]] || w plugin install woocommerce --activate --quiet
if [[ -n ${CM_ELEMENTOR:-} ]]; then
    # Elementor's CLI logger prints its own deprecations at shutdown, which breaks the output suites parse;
    # WordPress's shutdown hook runs before Elementor's shutdown function, so clear the error there.
    mkdir -p "$ROOT/wp-content/mu-plugins"
    echo '<?php add_action( "shutdown", function () { error_clear_last(); }, 0 );' >"$ROOT/wp-content/mu-plugins/zz-ci-elementor-quiet.php"
    w plugin install elementor --activate --quiet
    w transient delete elementor_activation_redirect --quiet # else the first wp-admin visit lands on its onboarding
fi
[[ -z ${CM_BRICKS_ZIP:-} ]] || w theme install "$CM_BRICKS_ZIP" --activate --quiet
# Connect to Monks Central is off by default.
w option update cm_mcp_enabled 1 --quiet
if [[ $(printf '%s\n' 7.1 "$WP_VERSION" | sort -V | head -1) == 7.1 ]]; then
    [[ $(w eval 'echo class_exists("CM_MCP_Server") ? "on" : "off";') == on ]] || { echo "Monks Central did not load"; exit 1; }
fi
set +e

curl -s -o /dev/null "$CM_SITE/" && { echo "port $PORT is already in use (set CM_PORT)"; exit 1; }
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:$PORT" -t "$ROOT" "$PLUGIN_DIR/tests/mcp/router.php" >"$ROOT/server.log" 2>&1 &
SERVER=$!
trap 'pkill -P $SERVER; kill $SERVER 2>/dev/null' EXIT # workers are children of $SERVER
for _ in $(seq 50); do curl -s -o /dev/null "$CM_SITE/" && break; sleep 0.2; done

FAILED=()
for s in "${SUITES[@]}"; do
    echo "::group::$s"
    bash "$PLUGIN_DIR/tests/mcp/$s.sh"
    rc=$?
    echo "::endgroup::"
    ((rc)) && FAILED+=("$s")
done

if ((${#FAILED[@]})); then
    echo "FAILED suites: ${FAILED[*]}"
    echo "--- debug.log (tail)"; tail -n 50 "$ROOT/wp-content/debug.log" 2>/dev/null
    exit 1
fi
echo "All ${#SUITES[@]} suites passed."
