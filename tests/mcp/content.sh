#!/usr/bin/env bash
# Posts, pages and other plugins' post types (WooCommerce, Elementor, Bricks) over MCP. Run: bash tests/mcp/content.sh
. "$(dirname "$0")/lib.sh"
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null

# create-post / update-post keep backslashes and admin HTML, and need publish_posts to publish.
C=$(call create-post '{"title":"cm slash test","content":"<p>a\\b</p><iframe src=\"https://example.com/\"></iframe>"}')
CP=$(sed -E 's/.*"id":([0-9]+).*/\1/' <<<"$C")
check 'create-post keeps backslashes'        "$(wpe "echo get_post_field('post_content', $CP);")" '<p>a\b</p><iframe src="https://example.com/"></iframe>'
check 'create-post keeps admin iframe'       "$(wpe "echo get_post_field('post_content', $CP);")" '~<iframe'
call update-post "{\"post_id\":$CP,\"content\":\"<p>c\\\\d</p>\"}" >/dev/null
check 'update-post keeps backslashes'        "$(wpe "echo get_post_field('post_content', $CP);")" '<p>c\d</p>'
U=$(wpe 'echo wp_insert_user(["user_login" => "cm_contrib_test", "user_pass" => wp_generate_password(), "role" => "contributor"]);')
perm() { wpe "wp_set_current_user($U); var_export(wp_get_ability('classic-monks/$1')->check_permissions($2));"; }
check 'contributor may create a draft'       "$(perm create-post "['title' => 'x']")" true
check 'contributor may not publish'          "$(perm create-post "['title' => 'x', 'status' => 'publish']")" false
CD=$(wpe "echo wp_insert_post(['post_title' => 'cm contrib draft', 'post_author' => $U]);")
check 'contributor may edit own draft'       "$(perm update-post "['post_id' => $CD, 'title' => 'y']")" true
check 'contributor may not publish own draft' "$(perm update-post "['post_id' => $CD, 'status' => 'publish']")" false
# Content reads only show what the user can read (R-8).
AD=$(wpe "echo wp_insert_post(['post_title' => 'cm r8 admin draft', 'post_content' => 'cm r8 secret draft', 'post_author' => 1]);")
AP=$(wpe "echo wp_insert_post(['post_title' => 'cm r8 admin private', 'post_content' => 'cm r8 secret private', 'post_status' => 'private', 'post_author' => 1]);")
AW=$(wpe "echo wp_insert_post(['post_title' => 'cm r8 admin protected', 'post_content' => 'cm r8 secret password', 'post_status' => 'publish', 'post_password' => 'pw', 'post_author' => 1]);")
wpe "update_post_meta($AD, 'cmMixedKey', 'mixed-ok'); update_post_meta($CD, 'cmMixedKey', 'own-mixed-ok');" >/dev/null
ex() { wpe "wp_set_current_user($U); echo wp_json_encode(wp_get_ability('classic-monks/$1')->execute($2));"; }
R=$(ex get-posts "['status' => 'draft,private,publish', 'limit' => 100]")
check 'contributor get-posts hides admin draft'   "$R" "!~\"id\":$AD,"
check 'contributor get-posts hides admin private' "$R" "!~\"id\":$AP,"
check 'contributor get-posts lists own draft'     "$R" "~\"id\":$CD,"
check 'contributor get-posts hides protected text' "$R" '!~secret password'
check 'contributor get-posts refuses user_request' "$(ex get-posts "['post_type' => 'user_request']")" '~"errors"'
check 'contributor get-post-by-id denied'       "$(perm get-post-by-id "['post_id' => $AD]")" false
check 'contributor get-post-content denied'     "$(perm get-post-content "['post_id' => $AP]")" false
check 'contributor get-post-meta denied'        "$(perm get-post-meta "['post_id' => $AD]")" false
check 'contributor reads own mixed-case meta'   "$(ex get-post-meta "['post_id' => $CD, 'key' => 'cmMixedKey']")" '~"value":"own-mixed-ok"'
wpe "update_post_meta($CD, '_cm_token', 'cm-hidden-token');" >/dev/null
check 'contributor cannot read a protected field' "$(ex get-post-meta "['post_id' => $CD, 'key' => '_cm_token']")" '!~cm-hidden-token'
check '... nor list it'                          "$(ex get-post-meta "['post_id' => $CD, 'exclude_private' => false]")" '!~cm-hidden-token'
check 'admin still reads it'                     "$(call get-post-meta "{\"post_id\":$CD,\"key\":\"_cm_token\"}")" '~cm-hidden-token'
R=$(call get-posts '{"status":"draft,private","limit":100}')
check 'admin get-posts lists draft'             "$R" "~$AD"
check 'admin get-posts lists private'           "$R" "~$AP"
check 'admin reads mixed-case meta key'         "$(call get-post-meta "{\"post_id\":$AD,\"key\":\"cmMixedKey\"}")" '~"value":"mixed-ok"'
wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ([$CP, $CD, $AD, $AP, $AW] as \$p) wp_delete_post(\$p, true); wp_delete_user($U);" >/dev/null

# Any post type: pages, and the types WooCommerce, Elementor and Bricks register, when they are active.
R=$(call create-post '{"title":"cm page test","post_type":"page","content":"<p>page</p>"}')
PG=$(sed -nE 's/.*"id":([0-9]+).*/\1/p' <<<"$R")
check 'create-post makes a page'             "$(wpe "echo get_post_type(${PG:-0});")" page
check 'get-posts lists pages'                "$(call get-posts '{"post_type":"page","status":"draft","limit":100}')" "~\"id\":${PG:-0},"
check 'delete-post trashes the page'         "$(call delete-post "{\"post_id\":${PG:-0}}")" '!~isError'
check 'page is in the trash'                 "$(wpe "echo get_post_status(${PG:-0});")" trash
wpe "wp_delete_post(${PG:-0}, true);" >/dev/null
for pt in product elementor_library bricks_template; do
    if [[ $(wpe "echo post_type_exists('$pt') ? 'y' : 'n';") != y ]]; then echo "SKIP $pt (not registered here)"; continue; fi
    R=$(call create-post "{\"title\":\"cm $pt test\",\"post_type\":\"$pt\"}")
    ID=$(sed -nE 's/.*"id":([0-9]+).*/\1/p' <<<"$R")
    check "create-post $pt"                  "$(wpe "echo get_post_type(${ID:-0});")" "$pt"
    check "get-posts $pt"                    "$(call get-posts "{\"post_type\":\"$pt\",\"status\":\"draft\",\"limit\":100}")" "~\"id\":${ID:-0},"
    check "update-post $pt"                  "$(call update-post "{\"post_id\":${ID:-0},\"title\":\"cm $pt renamed\"}")" '!~isError'
    check "get-post-by-id $pt"               "$(call get-post-by-id "{\"post_id\":${ID:-0}}")" "~cm $pt renamed"
    check "update-post-metadata $pt"         "$(call update-post-metadata "{\"post_id\":${ID:-0},\"key\":\"zz_cm_note\",\"value\":\"$pt\"}")" '!~isError'
    check "get-post-meta $pt"                "$(call get-post-meta "{\"post_id\":${ID:-0},\"key\":\"zz_cm_note\"}")" "~\"value\":\"$pt\""
    wpe "wp_delete_post(${ID:-0}, true);" >/dev/null
done
# WooCommerce product categories through the term tools.
if [[ $(wpe "echo taxonomy_exists('product_cat') ? 'y' : 'n';") == y ]]; then
    check 'list-taxonomies has product_cat'  "$(call list-taxonomies)" '~product_cat'
    R=$(call create-term '{"taxonomy":"product_cat","name":"cm mcp cat"}')
    T=$(sed -nE 's/.*"term_id":([0-9]+).*/\1/p' <<<"$R")
    check 'create-term product_cat'          "$(wpe "echo get_term(${T:-0})->taxonomy ?? '';")" product_cat
    check 'get-terms product_cat'            "$(call get-terms '{"taxonomy":"product_cat","search":"cm mcp"}')" '~cm mcp cat'
    wpe "wp_delete_term(${T:-0}, 'product_cat');" >/dev/null
else
    echo 'SKIP product_cat (WooCommerce not active)'
fi

done_checks
