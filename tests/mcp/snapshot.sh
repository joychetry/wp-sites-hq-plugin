#!/usr/bin/env bash
# Monks Central M4: get-site-snapshot. Run: bash tests/mcp/snapshot.sh
. "$(dirname "$0")/lib.sh"
trap 'wpe "delete_site_transient(\"update_plugins\"); delete_transient(\"health-check-site-status-result\");" >/dev/null; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
sc() { jq -c ".result.structuredContent$2" <<<"$1"; }

# A known plugin update and a Site Health result, so the snapshot has something to report.
wpe 'require_once ABSPATH . "wp-admin/includes/plugin.php"; $checked = array_map(function ($p) { return $p["Version"]; }, get_plugins());
set_site_transient("update_plugins", (object) ["last_checked" => time(), "checked" => $checked, "response" => ["akismet/akismet.php" => (object) ["slug" => "akismet", "plugin" => "akismet/akismet.php", "new_version" => "99.0", "package" => ""]], "translations" => [], "no_update" => []]);
set_transient("health-check-site-status-result", wp_json_encode(["good" => 12, "recommended" => 3, "critical" => 1]));' >/dev/null

S=$(call get-site-snapshot)
check 'every section in one call'        "$(sc "$S" '| keys')" '["core","database","environment","filesystem","generated_at","health","php","plugins","site","themes","updates"]'
check 'core version'                     "$(sc "$S" .core.version)" "\"$(wpe 'echo get_bloginfo("version");')\""
check 'core db version'                  "$(sc "$S" .core.db_version)" "$(wpe 'echo get_option("db_version");')"
check 'php version'                      "$(sc "$S" .php.version)" "\"$(wpe 'echo PHP_VERSION;')\""
check 'database server reported'         "$(sc "$S" '.database.server_version | length > 0')" true
check 'this plugin listed as active'     "$(sc "$S" '.plugins[] | select(.plugin == "wp-sites-hq/wp-sites-hq.php") | .active')" true
check 'plugin update shown on the plugin' "$(sc "$S" '.plugins[] | select(.plugin == "akismet/akismet.php") | .update')" '"99.0"'
check 'no update is null'                "$(sc "$S" '.plugins[] | select(.plugin == "wp-sites-hq/wp-sites-hq.php") | .update')" null
check 'active theme marked'              "$(sc "$S" '.themes[] | select(.active) | .stylesheet')" "\"$(wpe 'echo get_stylesheet();')\""
check 'updates include translations'     "$(sc "$S" '.updates | has("translations") and has("counts")')" true
check 'updates count the plugin'         "$(sc "$S" .updates.counts.plugins)" 1
check 'health from the last run'         "$(sc "$S" .health)" '{"good":12,"recommended":3,"critical":1}'
check 'environment type'                 "$(sc "$S" .environment.type)" '"local"'
check 'direct file system needs nothing' "$(sc "$S" '.filesystem | [.method, .needs_credentials]')" '["direct",false]'
# WP-CLI forces the direct method at the default priority.
check 'FTP-only site needs credentials'  "$(wpe 'add_filter("filesystem_method", function () { return "ftpext"; }, 999); echo wp_json_encode(cm_ai_snapshot_filesystem());')" '~"needs_credentials":true'
wpe 'delete_transient("health-check-site-status-result");' >/dev/null
check 'no Site Health run yet is null'   "$(sc "$(call get-site-snapshot)" .health)" null

# Read only: a Read-only connection may call it, without approval.
wpe "\$s = get_user_meta(1, 'cm_mcp_scopes', true); \$s['$MCP_UUID'] = 'read'; update_user_meta(1, 'cm_mcp_scopes', \$s);" >/dev/null
check 'works on a Read-only connection'  "$(call get-site-snapshot)" '~"filesystem"'

done_checks
