#!/usr/bin/env bash
# Monks Central M6 content tools over MCP: terms for any taxonomy, custom fields, dates,
# builder pages, revisions, untrash and chunked upload. Run: bash tests/mcp/m6.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null

# A custom post type that is not in REST, so only Classic Monks sees it.
MU="$SITE_ROOT/wp-content/mu-plugins/cm-m6-test.php"
mkdir -p "$(dirname "$MU")"
cat >"$MU" <<'PHP'
<?php
add_action( 'init', function () {
    register_post_type( 'cm_book', array( 'label' => 'Books', 'public' => true, 'show_in_rest' => false, 'supports' => array( 'title', 'editor', 'revisions', 'custom-fields' ) ) );
    register_post_type( 'cm_ledger', array( 'label' => 'Ledger', 'show_ui' => true, 'supports' => array( 'title' ) ) );
    register_taxonomy( 'cm_genre', 'cm_book', array( 'label' => 'Genres', 'show_ui' => true, 'show_in_rest' => false ) );
    // A field its plugin lets nobody change here.
    register_post_meta( 'cm_book', 'cm_featured', array( 'single' => true, 'auth_callback' => '__return_false' ) );
} );
PHP
TMP=$(mktemp -d)
trap 'rm -f "$MU" "${OAUTH_JARS[@]}"; rm -rf "$TMP"; revoke_grant "$FAM"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
id() { sed -E 's/.*"(id|post_id|attachment_id)":([0-9]+).*/\2/' <<<"$1"; }
ok() { check "$1" "$2" '!~"isError":true'; }
err() { check "$1" "$2" '~"isError":true'; }

# These tools are for Monks Central: other connections never see them.
LIST=$(rpc tools/list)
check 'hidden from a full app password' "$LIST" '!~"name":"list-post-types"'
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
rpc() { brpc "$OP" "$@"; }
LIST=$(rpc tools/list)
for t in list-post-types list-revisions restore-revision untrash-post upload-media; do
    check "listed for the operator: $t" "$LIST" "~\"name\":\"$t\""
done

R=$(call list-post-types)
check 'list-post-types has posts'            "$R" '~"name":"post"'
check 'list-post-types has a non-REST type'  "$R" '~"name":"cm_book"'
check 'list-post-types skips types without a body' "$R" '!~"name":"cm_ledger"'
check 'list-post-types gives its taxonomy'   "$R" '~"name":"cm_genre","label":"Genres"'
check 'list-post-types leaves out media'     "$R" '!~"name":"attachment"'
check 'list-post-types leaves out templates' "$R" '!~"name":"wp_template"'

# create-post: terms by name in any taxonomy, a custom field, a future date schedules it.
R=$(call create-post '{"title":"cm m6 book","post_type":"cm_book","status":"publish","date":"2099-01-02 03:04:05","terms":{"cm_genre":["Jazz"]},"meta":{"cm_rating":"5"}}')
ok 'create-post with terms, meta and date' "$R"
B=$(id "$R")
check 'a future date schedules it'          "$(wpe "echo get_post_status($B);")" future
check 'the date is kept'                    "$(wpe "echo get_post_field('post_date', $B);")" '2099-01-02 03:04:05'
check 'the new term was added'              "$(wpe "echo implode(',', wp_get_object_terms($B, 'cm_genre', ['fields' => 'names']));")" Jazz
check 'the custom field was set'            "$(wpe "echo get_post_meta($B, 'cm_rating', true);")" 5
R=$(call get-post-by-id "{\"post_id\":$B}")
check 'get-post-by-id gives terms by taxonomy' "$R" '~"cm_genre":[{"id":'
check 'get-post-by-id gives no builder'     "$R" '~"builder":null'
check 'get-post-by-id gives the image id'   "$R" '~"featured_image_id":0'

N=$(wpe "echo count(get_posts(['post_type' => 'cm_book', 'post_status' => 'any', 'numberposts' => -1]));")
err 'a protected custom field is refused'   "$(call create-post '{"title":"cm m6 bad","post_type":"cm_book","meta":{"_secret":"x"}}')"
err 'a field its auth callback refuses is refused on create' "$(call create-post '{"title":"cm m6 featured","post_type":"cm_book","meta":{"cm_featured":"1"}}')"
check '... and no half-made post is left'     "$(wpe "echo count(get_posts(['post_type' => 'cm_book', 'title' => 'cm m6 featured', 'post_status' => 'any']));")" 0
err 'a taxonomy the type lacks is refused'  "$(call create-post '{"title":"cm m6 bad","post_type":"post","terms":{"cm_genre":["Jazz"]}}')"
err 'an unknown term id is refused'         "$(call create-post '{"title":"cm m6 bad","post_type":"cm_book","terms":{"cm_genre":[999999]}}')"
err 'a bad date is refused'                 "$(call create-post '{"title":"cm m6 bad","post_type":"cm_book","date":"tomorrow"}')"
check 'nothing was created by refused calls' "$(wpe "echo count(get_posts(['post_type' => 'cm_book', 'post_status' => 'any', 'numberposts' => -1]));")" "$N"

# A contributor may use existing terms but not add new ones (edit_terms).
U=$(wpe 'echo wp_insert_user(["user_login" => "cm_m6_contrib", "user_pass" => wp_generate_password(), "role" => "contributor"]);')
ex() { wpe "wp_set_current_user($U); echo wp_json_encode(wp_get_ability('classic-monks/$1')->execute($2));"; }
check 'contributor may use an existing term' "$(ex create-post "['title' => 'cm m6 c', 'post_type' => 'cm_book', 'terms' => ['cm_genre' => ['Jazz']]]")" '~"id":'
check 'contributor may not add a term'       "$(ex create-post "['title' => 'cm m6 c', 'post_type' => 'cm_book', 'terms' => ['cm_genre' => ['Polka']]]")" '~"errors"'
check 'contributor may not upload'           "$(wpe "wp_set_current_user($U); var_export(wp_get_ability('classic-monks/upload-media')->check_permissions(['data' => 'eA==']));")" false

# update-post: terms replace, null deletes a field, the date moves, the featured image goes.
J=$(wpe "echo get_term_by('name', 'Jazz', 'cm_genre')->term_id;")
R=$(call update-post "{\"post_id\":$B,\"terms\":{\"cm_genre\":[$J,\"Blues\"]},\"meta\":{\"cm_rating\":null},\"date\":\"2099-02-03 04:05:06\",\"featured_image\":0}")
ok 'update-post with terms, meta, date and image' "$R"
check 'update-post lists what it wrote'     "$R" '~terms:cm_genre'
check 'terms were replaced'                 "$(wpe "echo implode(',', wp_get_object_terms($B, 'cm_genre', ['fields' => 'names', 'orderby' => 'name']));")" Blues,Jazz
check 'null deleted the custom field'       "$(wpe "var_export(metadata_exists('post', $B, 'cm_rating'));")" false
check 'the date moved'                      "$(wpe "echo get_post_field('post_date', $B);")" '2099-02-03 04:05:06'
call update-post "{\"post_id\":$B,\"terms\":{\"cm_genre\":[]}}" >/dev/null
check 'an empty list removes the terms'     "$(wpe "echo count(wp_get_object_terms($B, 'cm_genre'));")" 0
err 'update-post refuses a protected field' "$(call update-post "{\"post_id\":$B,\"meta\":{\"_edit_lock\":\"1\"}}")"

# Pages built with Elementor or Bricks: the body is refused, the title is not.
for b in elementor bricks; do
    P=$(wpe "echo wp_insert_post(['post_type' => 'page', 'post_title' => 'cm m6 $b', 'post_status' => 'draft', 'post_content' => '<p>fallback</p>']);")
    if [[ $b == elementor ]]; then wpe "update_post_meta($P, '_elementor_edit_mode', 'builder'); update_post_meta($P, '_elementor_data', '[]');" >/dev/null
    else wpe "wp_set_current_user(1); update_post_meta($P, '_bricks_page_content_2', [['id' => 'abc', 'name' => 'section']]);" >/dev/null; fi
    check "get-post-by-id says $b"           "$(call get-post-by-id "{\"post_id\":$P}")" "~\"builder\":\"$b\""
    R=$(call update-post "{\"post_id\":$P,\"content\":\"<p>mangled</p>\"}")
    err "$b body edit is refused"            "$R"
    check "$b refusal names the builder"     "$R" "~built with ${b^}"
    check "$b body is untouched"             "$(wpe "echo get_post_field('post_content', $P);")" '<p>fallback</p>'
    ok "$b title can still change"           "$(call update-post "{\"post_id\":$P,\"title\":\"cm m6 $b renamed\"}")"
    wpe "wp_delete_post($P, true);" >/dev/null
done

# Revisions: list, restore, and the version before is kept.
P=$(wpe "echo wp_insert_post(['post_title' => 'cm m6 rev', 'post_status' => 'draft', 'post_content' => 'one']);")
call update-post "{\"post_id\":$P,\"content\":\"two\"}" >/dev/null
call update-post "{\"post_id\":$P,\"content\":\"three\"}" >/dev/null
R=$(call list-revisions "{\"post_id\":$P}")
ok 'list-revisions' "$R"
TWO=$(wpe "foreach (wp_get_post_revisions($P) as \$r) if ('two' === \$r->post_content) echo \$r->ID;")
check 'list-revisions lists an older one'   "$R" "~\"id\":$TWO,"
BEFORE=$(wpe "echo count(wp_get_post_revisions($P));")
ok 'restore-revision' "$(call restore-revision "{\"revision_id\":$TWO}")"
check 'the old body is back'                "$(wpe "echo get_post_field('post_content', $P);")" two
check 'the version before was kept'         "$(wpe "echo count(wp_get_post_revisions($P)) > $BEFORE ? 'yes' : 'no';")" yes
err 'restore-revision refuses a post id'    "$(call restore-revision "{\"revision_id\":$P}")"

# Untrash: back as a draft, and only from the trash.
ok 'trash it'                               "$(call delete-post "{\"post_id\":$P}")"
R=$(call untrash-post "{\"post_id\":$P}")
ok 'untrash-post' "$R"
check 'untrash-post says the status'        "$R" '~"status":"draft"'
err 'untrash-post refuses a post not in the trash' "$(call untrash-post "{\"post_id\":$P}")"
wpe "wp_delete_post($P, true);" >/dev/null

# upload-media in two chunks; a repeated chunk is ignored, a wrong offset refused.
printf %s 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' | base64 -d >"$TMP/dot.png"
A=$(head -c 30 "$TMP/dot.png" | base64 -w0); Z=$(tail -c +31 "$TMP/dot.png" | base64 -w0)
R=$(call upload-media "{\"filename\":\"cm-m6-dot.png\",\"data\":\"$A\"}")
check 'first chunk is received'             "$R" '~"received":30'
UP=$(sed -E 's/.*"upload_id":"([A-Za-z0-9]+)".*/\1/' <<<"$R")
check 'a repeated chunk is ignored'         "$(call upload-media "{\"upload_id\":\"$UP\",\"offset\":0,\"data\":\"$A\"}")" '~"received":30'
err 'a wrong offset is refused'             "$(call upload-media "{\"upload_id\":\"$UP\",\"offset\":5,\"data\":\"$Z\"}")"
R=$(call upload-media "{\"upload_id\":\"$UP\",\"offset\":30,\"data\":\"$Z\",\"done\":true,\"alt\":\"A dot\"}")
check 'last chunk adds the image'           "$R" '~"mime_type":"image'
M=$(id "$R")
check 'the file is whole'                   "$(wpe "echo filesize(get_attached_file($M));")" "$(stat -c %s "$TMP/dot.png")"
check 'alt text was set'                    "$(wpe "echo get_post_meta($M, '_wp_attachment_image_alt', true);")" 'A dot'
err 'a finished upload id is gone'          "$(call upload-media "{\"upload_id\":\"$UP\",\"offset\":67,\"data\":\"$Z\"}")"
# The featured image can be set with update-post now there is an image.
ok 'update-post sets the featured image'    "$(call update-post "{\"post_id\":$B,\"featured_image\":$M}")"
check 'featured image is set'               "$(wpe "echo get_post_thumbnail_id($B);")" "$M"
err 'a non-image is refused'                "$(call upload-media "{\"filename\":\"x.png\",\"data\":\"$(printf 'not an image' | base64 -w0)\",\"done\":true}")"
err 'bad base64 is refused'                 "$(call upload-media '{"filename":"x.png","data":"%%%"}')"

# Editor fields (Monks Central's full-page editor): slug, parent, order, author, password, template, excerpt lines.
PG=$(id "$(call create-post '{"title":"cm m6 parent","post_type":"page","status":"draft"}')")
R=$(call create-post "{\"title\":\"cm m6 child\",\"post_type\":\"page\",\"status\":\"draft\",\"parent\":$PG,\"menu_order\":3,\"slug\":\"cm m6 Kid\"}")
ok 'create-post with parent, order and slug' "$R"
C=$(id "$R")
check 'the parent is set'                   "$(wpe "echo wp_get_post_parent_id($C);")" "$PG"
check 'the order is set'                    "$(wpe "echo get_post_field('menu_order', $C);")" 3
check 'the slug is cleaned and set'         "$(wpe "echo get_post_field('post_name', $C);")" cm-m6-kid
AU=$(wpe 'echo wp_insert_user(["user_login" => "cm_m6_author", "user_pass" => wp_generate_password(), "role" => "editor"]);')
SU=$(wpe 'echo wp_insert_user(["user_login" => "cm_m6_sub", "user_pass" => wp_generate_password(), "role" => "subscriber"]);')
R=$(call update-post "{\"post_id\":$C,\"author\":1,\"password\":\"open sesame\",\"excerpt\":\"Line one\nLine two\",\"template\":\"\"}")
ok 'update-post with author, password, excerpt and template' "$R"
check 'update-post lists the new fields'    "$R" '~password'
check 'the password is set'                 "$(wpe "echo get_post_field('post_password', $C);")" 'open sesame'
check 'the excerpt keeps its lines'         "$(wpe "echo str_replace(\"\n\", '|', get_post_field('post_excerpt', $C));")" 'Line one|Line two'
check 'the default template is set'         "$(wpe "echo get_post_meta($C, '_wp_page_template', true);")" default
err 'a subscriber can not be the author'    "$(call update-post "{\"post_id\":$C,\"author\":$SU}")"
err 'a parent of another type is refused'   "$(call update-post "{\"post_id\":$C,\"parent\":$B}")"
err 'an entry is not its own parent'        "$(call update-post "{\"post_id\":$C,\"parent\":$C}")"
err 'an unknown template is refused'        "$(call update-post "{\"post_id\":$C,\"template\":\"cm-nope.php\"}")"
check 'refused calls changed nothing'       "$(wpe "echo wp_get_post_parent_id($C), get_post_field('post_author', $C);")" "${PG}1"
ok 'an author can be given the page'        "$(call update-post "{\"post_id\":$C,\"author\":$AU,\"password\":\"\"}")"
check 'the author is set and it is public'  "$(wpe "echo get_post_field('post_author', $C), '|', get_post_field('post_password', $C);")" "$AU|"
R=$(call get-post-by-id "{\"post_id\":$C,\"choices\":true}")
check 'get-post-by-id gives the template'   "$R" '~"template":'
check 'get-post-by-id gives a preview link' "$R" '~"preview_link":"http'
check 'choices list authors'                "$R" "~{\"id\":$AU,\"name\":"
check 'choices leave out subscribers'       "$R" "!~{\"id\":$SU,\"name\":"
check 'choices list parents'                "$R" "~{\"id\":$PG,\"title\":\"cm m6 parent\"}"
check 'choices leave out the entry itself'  "$R" "!~{\"id\":$C,\"title\":\"cm m6 child\"}"
check 'choices give templates'              "$R" '~"templates":'
check 'without choices there are none'      "$(call get-post-by-id "{\"post_id\":$C}")" '!~"choices"'
check 'a contributor may not give an entry to someone else' "$(ex create-post "['title' => 'cm m6 c2', 'post_type' => 'cm_book', 'author' => 1]")" '~"errors"'
wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_post($C, true); wp_delete_post($PG, true); wp_delete_user($AU); wp_delete_user($SU);" >/dev/null
R=$(wpe "register_post_type('cm_m6_h', ['hierarchical' => true, 'public' => true]); \$s = wp_insert_post(['post_type' => 'cm_m6_h', 'post_title' => 'cm m6 secret', 'post_status' => 'private', 'post_author' => 1]); \$o = wp_insert_post(['post_type' => 'cm_m6_h', 'post_title' => 'cm m6 open', 'post_status' => 'publish', 'post_author' => 1]); \$m = wp_insert_post(['post_type' => 'cm_m6_h', 'post_title' => 'cm m6 mine', 'post_status' => 'draft', 'post_author' => $U]); wp_set_current_user($U); \$c = cm_content_choices(get_post(\$m)); foreach ([\$s, \$o, \$m] as \$p) wp_delete_post(\$p, true); echo wp_json_encode(\$c['parents']);")
check 'parents a contributor may read are listed' "$R" '~cm m6 open'
check "others' private entries are not"     "$R" '!~cm m6 secret'

wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; foreach (get_posts(['post_type' => 'cm_book', 'post_status' => 'any', 'numberposts' => -1]) as \$p) wp_delete_post(\$p->ID, true); wp_delete_attachment($M, true); wp_delete_user($U); foreach (get_terms(['taxonomy' => 'cm_genre', 'hide_empty' => false]) as \$t) wp_delete_term(\$t->term_id, 'cm_genre');" >/dev/null
done_checks
