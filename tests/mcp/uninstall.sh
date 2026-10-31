#!/usr/bin/env bash
# F-24 / T-5: uninstalling revokes MCP app passwords and drops the OAuth table, its version option and the
# cm_mcp_oauth_* transients on every site, whatever the data-removal setting.
# WARNING: deactivates and uninstalls wp-sites-hq (files kept). CI runs it last on a throwaway site.
cd "$(dirname "$0")/../.." && . tests/mcp/lib.sh

SITES=$(wpe 'echo implode(" ", is_multisite() ? get_sites(["fields" => "ids", "number" => 0]) : [0]);')
[[ -n $SITES ]] || { echo "FAIL no sites found"; exit 1; }
# PHP run once per site (switch_to_blog on multisite); prints "<site>:<output>" per site.
each() { wpe "foreach ([${SITES// /,}] as \$id) { if (\$id) switch_to_blog(\$id); echo \$id, ':'; $1; echo ' '; if (\$id) restore_current_blog(); }"; }

each 'CM_MCP_OAuth::install(); set_transient("cm_mcp_oauth_zz_uninstall", "x", 600); global $wpdb; echo $wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}cm_mcp_oauth_tokens\"") ? "table" : "none"' >/dev/null
check 'setup: OAuth table on every site' "$(each 'global $wpdb; echo $wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}cm_mcp_oauth_tokens\"") ? "t" : "-"')" "$(for s in $SITES; do printf '%s:t ' "$s"; done)"

for s in $SITES; do
    url=$(wpe "echo $s ? get_home_url($s) : home_url();")
    (cd "$SITE_ROOT" && $WP_CMD plugin deactivate wp-sites-hq --url="$url" >/dev/null 2>&1)
done
(cd "$SITE_ROOT" && $WP_CMD plugin uninstall wp-sites-hq --skip-delete >/dev/null 2>&1)

want=$(for s in $SITES; do printf '%s:--- ' "$s"; done)
check 'OAuth table, option and transients gone on every site' \
    "$(each 'global $wpdb; echo $wpdb->get_var("SHOW TABLES LIKE \"{$wpdb->prefix}cm_mcp_oauth_tokens\"") ? "t" : "-", get_option("cm_mcp_oauth_db_version") ? "o" : "-", get_transient("cm_mcp_oauth_zz_uninstall") ? "x" : "-"')" "$want"
check 'MCP app password revoked' "$(code -u "$AUTH" "$SITE/wp-json/wp/v2/users/me")" 401
check 'plain app password untouched' "$(code -u "$PLAIN" "$SITE/wp-json/wp/v2/users/me")" 200
done_checks
