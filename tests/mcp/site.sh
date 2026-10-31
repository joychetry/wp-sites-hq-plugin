#!/usr/bin/env bash
# Toolsets and site-admin abilities over MCP. Run: bash tests/mcp/site.sh
. "$(dirname "$0")/lib.sh"
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null
settoolsets() { wpe "\$t = get_user_meta(1, 'cm_mcp_toolsets', true) ?: []; \$t['$MCP_UUID'] = $1; update_user_meta(1, 'cm_mcp_toolsets', \$t);" >/dev/null; }
count() { grep -o '"name":"[a-z0-9-]*","title"' <<<"$1" | wc -l | tr -d ' '; }

settoolsets "CM_MCP_Server::DEFAULT_TOOLSETS"
L=$(rpc tools/list)
check 'default toolsets stay under 128 tools' "$( (( $(count "$L") < 128 )) && echo yes)" yes
check 'default hides site admin'              "$L" '!~"name":"get-plugins"'
check 'site tool outside toolsets is unknown' "$(call get-plugins)" '~Unknown tool'

settoolsets "['site']"
L=$(rpc tools/list)
check 'site toolset lists get-plugins'        "$L" '~"name":"get-plugins"'
check 'site toolset hides content tools'      "$L" '!~"name":"get-posts"'

settoolsets "array_keys(CM_MCP_Server::toolsets())"
check 'get-plugins lists WP Sites HQ'      "$(call get-plugins)" '~"name":"WP Sites HQ"'
check 'search-plugins'                        "$(call search-plugins '{"search":"classic monks"}')" '!~"isError":true'
check 'set-plugin-auto-update'                "$(call set-plugin-auto-update '{"slug":"wp-sites-hq","enabled":true}')" '!~"isError":true'
check '... stored'                            "$(wpe 'echo in_array("wp-sites-hq/wp-sites-hq.php", (array) get_site_option("auto_update_plugins"), true) ? "on" : "off";')" on
call set-plugin-auto-update '{"slug":"wp-sites-hq","enabled":false}' >/dev/null
# wp.org guideline 8: Free installs from WordPress.org by slug only.
check 'install-plugin has no source_url'      "$L" '!~source_url'
check 'install-plugin refuses source_url'     "$(call install-plugin '{"source_url":"https://example.com/x.zip"}')" '~source_url is not a valid property'
check 'install-theme refuses source_url'      "$(call install-theme '{"slug":"x","source_url":"https://example.com/x.zip"}')" '~source_url is not a valid property'
check 'install-plugin without a slug refused' "$(call install-plugin '{}')" '~WordPress.org plugin slug'
check 'get-theme'                             "$(call get-theme)" "~$(wpe 'echo get_stylesheet();')"
check 'get-site-info'                         "$(call get-site-info)" '~"wordpress"'
check 'get-environment-info'                  "$(call get-environment-info)" '!~"isError":true'
wpe "delete_user_meta(1, 'cm_mcp_trusted');" >/dev/null
check 'install-plugin asks'                   "$(call install-plugin '{"slug":"hello-dolly"}')" '~"cause":"approval"'
check 'update-plugin asks'                    "$(call update-plugin '{"slug":"woocommerce"}')" '~"cause":"approval"'
check 'activate-theme asks'                   "$(call activate-theme '{"stylesheet":"twentytwentyfive"}')" '~"cause":"approval"'
check 'get-core-version'                      "$(call get-core-version)" '~"version"'
check 'list-themes'                           "$(call list-themes)" '~"active"'
check 'update-core always asks'               "$(call update-core)" '~"cause":"approval"'

# The fatal check after a theme change fetches the request's Host only for the site's own host, or on a local site.
fu() { wpe "wp_get_abilities(); echo cm_activate_theme_url_for_host('https://shop.example/', '$1', $2);"; }
check 'fatal check: own host, another port' "$(fu shop.example:8080 false)" 'https://shop.example:8080/'
check 'fatal check: a caller-chosen host is ignored' "$(fu 169.254.169.254 false)" 'https://shop.example/'
check 'fatal check: a local site uses it' "$(fu localhost:8881 true)" 'https://localhost:8881/'

done_checks
