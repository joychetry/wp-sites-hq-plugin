#!/usr/bin/env bash
# Monks Central M8 install-zip over MCP: a plugin or theme zip sent in chunks, retries, overwrite, activate,
# refusals and cleanup of abandoned uploads. Run: bash tests/mcp/m8.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"

PF=cm-m8-plugin/cm-m8-plugin.php
PL="$SITE_ROOT/wp-content/plugins/cm-m8-plugin"
TH="$SITE_ROOT/wp-content/themes/cm-m8-theme"
MU="$SITE_ROOT/wp-content/mu-plugins/cm-m8-test.php"
TMP=$(mktemp -d)
THEME_BEFORE=$(wpe 'echo get_stylesheet();')
# Option cm_m8_nomods turns file changes off, as DISALLOW_FILE_MODS does.
mkdir -p "$(dirname "$MU")"
echo '<?php add_filter( "file_mod_allowed", function ( $allowed ) { return get_option( "cm_m8_nomods" ) ? false : $allowed; } );' >"$MU"
cleanup() {
    wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; deactivate_plugins('$PF', true); switch_theme('$THEME_BEFORE'); delete_option('cm_m8_nomods'); if (\$u = get_user_by('login', 'cm_m8_installer')) wp_delete_user(\$u->ID);" >/dev/null
    rm -rf "$PL" "$TH" "$MU" "$TMP"
}
trap 'cleanup; rm -f "${OAUTH_JARS[@]}"; revoke_grant "$FAM"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
ok() { check "$1" "$2" '!~"isError":true'; }
err() { check "$1" "$2" '~"isError":true'; }
sc() { jq -r ".result.structuredContent | $2" <<<"$1"; }
b64() { base64 -w0 "$@"; }
# mkzip FILE [NAME CONTENT]...: a zip holding the given files.
mkzip() {
    php -r '$z = new ZipArchive(); $z->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE); for ($i = 2; $i < $argc; $i += 2) { $z->addFromString($argv[$i], $argv[$i + 1]); } $z->close();' "$@"
}
plugin() { printf '<?php\n/**\n * Plugin Name: CM M8 Plugin\n * Version: %s\n */\n// %s\n' "$1" "$(head -c 300 /dev/urandom | base64 -w0)"; }
mkzip "$TMP/p1.zip" cm-m8-plugin/cm-m8-plugin.php "$(plugin 1.0)"
mkzip "$TMP/p2.zip" cm-m8-plugin/cm-m8-plugin.php "$(plugin 2.0)"
mkzip "$TMP/t1.zip" cm-m8-theme/style.css $'/*\nTheme Name: CM M8 Theme\nVersion: 1.0\n*/\n' cm-m8-theme/index.php '<?php echo "cm m8";'
# tmpfile KEY: the temp file the cleanup event of an upload would delete.
tmpfile() { wpe "foreach (_get_cron_array() as \$h) foreach (\$h['cm_ai_chunk_expire'] ?? [] as \$e) if (\$e['args'][0] === '$1') echo \$e['args'][1];"; }
upid() { sc "$1" .upload_id; }

check 'install-zip is hidden from a full app password' "$(rpc tools/list)" '!~"name":"install-zip"'
check 'and unknown to it' "$(call install-zip "{\"filename\":\"p.zip\",\"kind\":\"plugin\",\"data\":\"$(b64 "$TMP/p1.zip")\",\"done\":true}")" '~Unknown tool'
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
rpc() { brpc "$OP" "$@"; }
check 'install-zip is listed for the operator' "$(rpc tools/list)" '~"name":"install-zip"'

# A plugin in three chunks; a repeated chunk is ignored, a wrong offset refused.
S=$(stat -c %s "$TMP/p1.zip"); N=$((S / 3))
A=$(head -c "$N" "$TMP/p1.zip" | b64); B=$(tail -c +$((N + 1)) "$TMP/p1.zip" | head -c "$N" | b64); C=$(tail -c +$((2 * N + 1)) "$TMP/p1.zip" | b64)
R=$(call install-zip "{\"filename\":\"cm-m8-plugin.zip\",\"kind\":\"plugin\",\"data\":\"$A\"}")
check 'first chunk is received' "$(sc "$R" .received)" "$N"
UP=$(upid "$R")
F=$(tmpfile "cm_install_zip_$UP")
check 'the upload has a temp file and a cleanup event' "$([[ -n $F && -f $F ]] && echo yes)" yes
check 'a repeated chunk is ignored' "$(sc "$(call install-zip "{\"upload_id\":\"$UP\",\"offset\":0,\"data\":\"$A\"}")" .received)" "$N"
err 'a wrong offset is refused' "$(call install-zip "{\"upload_id\":\"$UP\",\"offset\":5,\"data\":\"$B\"}")"
check 'second chunk is received' "$(sc "$(call install-zip "{\"upload_id\":\"$UP\",\"offset\":$N,\"data\":\"$B\"}")" .received)" $((2 * N))
check 'the second chunk again is ignored' "$(sc "$(call install-zip "{\"upload_id\":\"$UP\",\"offset\":$N,\"data\":\"$B\"}")" .received)" $((2 * N))
R=$(call install-zip "{\"upload_id\":\"$UP\",\"offset\":$((2 * N)),\"data\":\"$C\",\"done\":true}")
ok 'last chunk installs the plugin' "$R"
check 'install output' "$(sc "$R" '[.kind, .slug, .name, .version, .plugin, .activated, .replaced, .received] | @json')" "[\"plugin\",\"cm-m8-plugin\",\"CM M8 Plugin\",\"1.0\",\"$PF\",false,false,$S]"
check 'the plugin is on disk' "$(sed -n 's/^ \* Version: //p' "$PL/cm-m8-plugin.php")" 1.0
check 'the temp file is gone' "$([[ -f $F ]] && echo yes || echo no)" no
check 'its cleanup event is gone' "$(tmpfile "cm_install_zip_$UP")" ''
err 'a finished upload id is gone' "$(call install-zip "{\"upload_id\":\"$UP\",\"offset\":$S,\"data\":\"$C\"}")"

# A retry racing its original (the same chunk twice at once) is appended once.
UP2=$(upid "$(call install-zip "{\"filename\":\"race.zip\",\"kind\":\"plugin\",\"data\":\"$A\"}")")
F2=$(tmpfile "cm_install_zip_$UP2")
for _ in 1 2 3; do call install-zip "{\"upload_id\":\"$UP2\",\"offset\":$N,\"data\":\"$B\"}" >/dev/null & done; wait
check 'a chunk sent three times at once is kept once' "$(stat -c %s "$F2")" $((2 * N))
wpe "delete_transient('cm_install_zip_$UP2');" >/dev/null; rm -f "$F2"

# Refusals.
R=$(call install-zip "{\"filename\":\"x.zip\",\"data\":\"$A\"}")
check 'kind is required on the first call' "$R" '~is required on the first call'
R=$(call install-zip "{\"filename\":\"x.zip\",\"kind\":\"plugin\",\"data\":\"$(printf 'not a zip at all' | b64)\",\"done\":true}")
check 'a non-zip is refused' "$R" '~This file is not a zip'
R=$(call install-zip "{\"filename\":\"x.zip\",\"kind\":\"plugin\",\"data\":\"$(b64 "$TMP/t1.zip")\",\"done\":true}")
check 'a theme zip sent as a plugin is refused' "$R" '~No valid plugins were found'
R=$(call install-zip "{\"filename\":\"p.zip\",\"kind\":\"plugin\",\"data\":\"$(b64 "$TMP/p2.zip")\",\"done\":true}")
check 'an installed plugin is refused without overwrite' "$R" '~A plugin with this folder is already installed. Send overwrite: true to replace it.'
check 'and left as it was' "$(sed -n 's/^ \* Version: //p' "$PL/cm-m8-plugin.php")" 1.0
check 'no temp files left by refusals' "$(wpe "\$n = 0; foreach (_get_cron_array() as \$h) foreach (\$h['cm_ai_chunk_expire'] ?? [] as \$e) if (0 === strpos(\$e['args'][0], 'cm_install_zip_') && file_exists(\$e['args'][1])) \$n++; echo \$n;")" 0

# overwrite replaces it (version changes), activate activates it; switches sent on the first chunk count.
S2=$(stat -c %s "$TMP/p2.zip"); H=$((S2 / 2))
R=$(call install-zip "{\"filename\":\"p.zip\",\"kind\":\"plugin\",\"overwrite\":true,\"activate\":true,\"data\":\"$(head -c "$H" "$TMP/p2.zip" | b64)\"}")
R=$(call install-zip "{\"upload_id\":\"$(upid "$R")\",\"offset\":$H,\"data\":\"$(tail -c +$((H + 1)) "$TMP/p2.zip" | b64)\",\"done\":true}")
check 'overwrite replaces it and activates' "$(sc "$R" '[.version, .replaced, .activated] | @json')" '["2.0",true,true]'
check 'version 2.0 on disk' "$(sed -n 's/^ \* Version: //p' "$PL/cm-m8-plugin.php")" 2.0
check 'the plugin is active' "$(wpe "echo is_plugin_active('$PF') ? 'yes' : 'no';")" yes

# A theme: installed, then replaced and switched on.
R=$(call install-zip "{\"filename\":\"t.zip\",\"kind\":\"theme\",\"data\":\"$(b64 "$TMP/t1.zip")\",\"done\":true}")
ok 'theme install' "$R"
check 'theme output' "$(sc "$R" '[.kind, .slug, .name, .version, .activated, .replaced, has("plugin")] | @json')" '["theme","cm-m8-theme","CM M8 Theme","1.0",false,false,false]'
check 'the theme is on disk' "$([[ -f $TH/style.css ]] && echo yes)" yes
R=$(call install-zip "{\"filename\":\"t.zip\",\"kind\":\"theme\",\"overwrite\":true,\"activate\":true,\"data\":\"$(b64 "$TMP/t1.zip")\",\"done\":true}")
check 'theme replaced and switched on' "$(sc "$R" '[.replaced, .activated] | @json')" '[true,true]'
check 'the theme is active' "$(wpe 'echo get_stylesheet();')" cm-m8-theme
wpe "switch_theme('$THEME_BEFORE');" >/dev/null

# Permissions: the kind's install capability, plus update for overwrite and activate/switch for activate.
U=$(wpe '$u = wp_insert_user(["user_login" => "cm_m8_installer", "user_pass" => wp_generate_password(), "role" => "editor"]); (new WP_User($u))->add_cap("install_plugins"); echo $u;')
perm() { wpe "wp_set_current_user($U); var_export(wp_get_ability('classic-monks/install-zip')->check_permissions($1));"; }
check 'install_plugins may install a plugin' "$(perm "['data' => 'eA==', 'kind' => 'plugin']")" true
check 'but not a theme' "$(perm "['data' => 'eA==', 'kind' => 'theme']")" false
check 'nor overwrite without update_plugins' "$(perm "['data' => 'eA==', 'kind' => 'plugin', 'overwrite' => true]")" false
check 'nor activate without activate_plugins' "$(perm "['data' => 'eA==', 'kind' => 'plugin', 'activate' => true]")" false

# Outside Monks Central's connection (the wp-admin chat, an app password over REST) it never runs.
check 'not in the REST list' "$(wpe "var_export(wp_get_ability('classic-monks/install-zip')->get_meta_item('show_in_rest'));")" false
check 'and refused when run as an administrator' "$(wpe "wp_set_current_user(1); \$r = wp_get_ability('classic-monks/install-zip')->execute(['filename' => 'p.zip', 'kind' => 'plugin', 'data' => 'eA==']); echo is_wp_error(\$r) ? \$r->get_error_code() : 'ran';")" cm_operator_only

# File changes off: said plainly.
wpe "update_option('cm_m8_nomods', 1);" >/dev/null
check 'DISALLOW_FILE_MODS is named' "$(call install-zip "{\"filename\":\"p.zip\",\"kind\":\"plugin\",\"data\":\"$A\"}")" '~file changes are turned off (DISALLOW_FILE_MODS'
wpe "delete_option('cm_m8_nomods');" >/dev/null

# An abandoned upload's temp file is deleted once its hour is up, not before.
R=$(call install-zip "{\"filename\":\"p.zip\",\"kind\":\"plugin\",\"data\":\"$A\"}")
UP=$(upid "$R"); F=$(tmpfile "cm_install_zip_$UP")
wpe "do_action('cm_ai_chunk_expire', 'cm_install_zip_$UP', '$F');" >/dev/null
check 'a live upload keeps its file' "$([[ -f $F ]] && echo yes)" yes
check 'and its cleanup is set again' "$(tmpfile "cm_install_zip_$UP")" "$F"
wpe "delete_transient('cm_install_zip_$UP'); do_action('cm_ai_chunk_expire', 'cm_install_zip_$UP', '$F');" >/dev/null
check 'an expired upload loses its file' "$([[ -f $F ]] && echo yes || echo no)" no
wpe "wp_clear_scheduled_hook('cm_ai_chunk_expire', ['cm_install_zip_$UP', '$F']);" >/dev/null

done_checks
