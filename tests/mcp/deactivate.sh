#!/usr/bin/env bash
# R-11: deactivating the plugin revokes MCP credentials (app passwords + OAuth tokens), leaves other app passwords alone.
# WARNING: briefly deactivates wp-sites-hq and drops every MCP connection on the site (other runs' too).
cd "$(dirname "$0")/../.." && . tests/mcp/lib.sh

ME="$SITE/wp-json/wp/v2/users/me"
PLUGIN=wp-sites-hq
reactivate() { (cd "$SITE_ROOT" && $WP_CMD plugin activate "$PLUGIN" >/dev/null 2>&1); }
trap 'reactivate; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT

TABLE=$(wpe 'global $wpdb; echo $wpdb->prefix . "cm_mcp_oauth_tokens";')
wpe 'global $wpdb; $t = "'"$TABLE"'"; if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t))) { $wpdb->insert($t, ["token_hash" => hash("sha256", wp_generate_password(20, false)), "type" => "access", "family" => md5(uniqid()), "user_id" => 1, "client_key" => str_repeat("0", 64), "created_at" => time(), "expires_at" => time() + 3600]); }' >/dev/null
wpe 'update_user_meta(1, "cm_mcp_trusted", ["'"$MCP_UUID"'" => ["x" => 1]]);' >/dev/null

check "active: MCP password confined" "$(code -u "$AUTH" "$ME")" 403
check "notice on plugins.php with a connection" "$(wpe 'wp_set_current_user(1); cm_mcp_deactivation_notice();')" "~disconnect"

(cd "$SITE_ROOT" && $WP_CMD plugin deactivate "$PLUGIN" >/dev/null 2>&1)
INACTIVE=$(code -u "$AUTH" "$ME")
reactivate

check "deactivated: MCP password revoked" "$INACTIVE" 401
check "OAuth tokens deleted" "$(wpe 'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM '"$TABLE"'");')" 0
check "per-connection meta cleared" "$(wpe 'echo get_user_meta(1, "cm_mcp_trusted", true) ? "kept" : "gone";')" gone
check "plain app password untouched" "$(code -u "$PLAIN" "$ME")" 200
check "plugin active again" "$(cd "$SITE_ROOT" && $WP_CMD plugin is-active "$PLUGIN" 2>/dev/null && echo yes)" yes
done_checks
