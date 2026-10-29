#!/usr/bin/env bash
# Monks Central M5 (list-updates, update-translations) and M13 (update-core target). Signs in as admin/admin.
# Fake offers are written into WordPress's update transients, so nothing depends on WordPress.org. Run: bash tests/mcp/updates.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"
trap 'rm -f "${OAUTH_JARS[@]}"; wpe "delete_site_transient(\"update_core\"); delete_site_transient(\"update_plugins\"); delete_site_transient(\"update_themes\");" >/dev/null; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
sc() { jq -c ".result.structuredContent$2" <<<"$1"; }

# 9.9.0 offered in en_US and de_DE, a plugin and a theme update, and one translation pack whose package can't download.
fake_updates() { wpe '
$wp = get_bloginfo("version");
$offer = function ($v, $l, $r) { return (object) ["response" => $r, "current" => $v, "version" => $v, "locale" => $l, "php_version" => "7.4", "mysql_version" => "5.5.5", "download" => "", "packages" => (object) ["full" => "", "no_content" => "", "new_bundled" => "", "partial" => "", "rollback" => ""], "partial_version" => "", "new_bundled" => ""]; };
$pack = ["type" => "core", "slug" => "default", "language" => "de_DE", "version" => $wp, "updated" => "2026-10-01 00:00:00", "package" => "http://127.0.0.1:9/cm-no-such-pack.zip", "autoupdate" => true];
set_site_transient("update_core", (object) ["updates" => [$offer("9.9.0", "en_US", "upgrade"), $offer("9.9.0", "de_DE", "upgrade"), $offer($wp, "en_US", "latest")], "last_checked" => time(), "version_checked" => $wp, "translations" => [$pack]]);
require_once ABSPATH . "wp-admin/includes/plugin.php";
$checked = array_map(function ($p) { return $p["Version"]; }, get_plugins());
set_site_transient("update_plugins", (object) ["last_checked" => time(), "checked" => $checked, "response" => ["akismet/akismet.php" => (object) ["slug" => "akismet", "plugin" => "akismet/akismet.php", "new_version" => "99.0", "requires" => "7.1", "requires_php" => "7.4", "tested" => "7.1.3", "package" => "https://downloads.wordpress.org/plugin/akismet.99.0.zip"]], "translations" => [], "no_update" => []]);
$checked = array_map(function ($t) { return $t->get("Version"); }, wp_get_themes());
set_site_transient("update_themes", (object) ["last_checked" => time(), "checked" => $checked, "response" => ["twentytwentyfour" => ["theme" => "twentytwentyfour", "new_version" => "99.0", "requires" => "7.1", "requires_php" => "7.4", "package" => ""]], "translations" => []]);
' >/dev/null; }
fake_updates
# Not the active theme: a theme with its own updater (Bricks) replaces the fake offer with its real one.
STYLE=twentytwentyfour
WPV=$(wpe 'echo get_bloginfo("version");')

# --- M5 list-updates
L=$(call list-updates)
check 'core offers listed'                 "$(sc "$L" '.core | map(.version + " " + .locale)')" '["9.9.0 en_US","9.9.0 de_DE"]'
check 'reinstall offer left out'           "$(sc "$L" .counts.core)" 2
check 'plugin update listed'               "$(sc "$L" '.plugins[] | select(.plugin == "akismet/akismet.php") | [.new_version, .requires_wp, .requires_php, .package_offered]')" '["99.0","7.1","7.4",true]'
check 'theme update listed'                "$(sc "$L" ".themes[] | select(.stylesheet == \"$STYLE\") | [.stylesheet, .new_version]")" "[\"$STYLE\",\"99.0\"]"
check 'translation listed'                 "$(sc "$L" '.translations[0] | [.type, .slug, .language]')" '["core","default","de_DE"]'
check 'last_checked reported'              "$(sc "$L" '.last_checked.core > 0')" true
check 'read only: no approval'             "$L" '!~"cause":"approval"'

# --- M5 update-translations: one pack that can't download
U=$(call update-translations)
check 'translation update runs (no approval)' "$U" '!~"cause":"approval"'
check 'failed pack reported'               "$(sc "$U" '| [.updated, .failed, .items[0].language, .items[0].updated]')" '[0,1,"de_DE",false]'
# Installing translations can clear WordPress's update cache, so the transient may be gone already.
wpe '$t = get_site_transient("update_core"); if ($t) { $t->translations = []; set_site_transient("update_core", $t); }' >/dev/null
check 'nothing to update'                  "$(sc "$(call update-translations)" .message)" '"Translations are up to date."'

# --- M13 update-core target (operator grant: always-ask tools run directly)
fake_updates # installing translations cleared WordPress's update cache
check 'app password still needs approval'  "$(call update-core '{"version":"9.9.0"}')" '~"cause":"approval"'
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T")
check 'version not offered is refused'     "$(bcall "$OP" update-core '{"version":"9.9.9"}')" '~does not offer 9.9.9 for this site. Offered: 9.9.0 (en_US), 9.9.0 (de_DE)'
check 'locale not offered is refused'      "$(bcall "$OP" update-core '{"version":"9.9.0","locale":"fr_FR"}')" '~does not offer 9.9.0 fr_FR'
check 'locale needs a version'             "$(bcall "$OP" update-core '{"locale":"de_DE"}')" '~Name a version together with the locale'
check 'bad version is a validation error'  "$(bcall "$OP" update-core '{"version":"latest"}')" '~"cause":"validation"'
check 'current version is a no-op'         "$(sc "$(bcall "$OP" update-core "{\"version\":\"$WPV\"}")" '| [.updated, .message]')" '[false,"WordPress is already at that version."]'
check 'locale picks its own package'       "$(wpe 'echo cm_ai_find_core_offer("9.9.0", "de_DE")->locale;')" de_DE
check 'no locale: the site language'       "$(wpe 'echo cm_ai_find_core_offer("9.9.0")->locale;')" en_US
check 'no locale, site in de_DE'           "$(wpe 'add_filter("locale", function () { return "de_DE"; }); echo cm_ai_find_core_offer("9.9.0")->locale;')" de_DE
check 'no locale, site language not offered' "$(wpe 'add_filter("locale", function () { return "fr_FR"; }); echo cm_ai_find_core_offer("9.9.0")->locale;')" en_US
revoke_grant "$(jq -r .family <<<"$T")"

# Not tested here: an actual core download and install (needs WordPress.org packages).
done_checks
