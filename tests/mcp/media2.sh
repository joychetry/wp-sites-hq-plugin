#!/usr/bin/env bash
# Monks Central media manager: get-media details, upload-media for any allowed type, update-media,
# replace-media and restore-replaced-media, with undo. Run: bash tests/mcp/media2.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"
TMP=$(mktemp -d)
trap 'rm -f "${OAUTH_JARS[@]}"; rm -rf "$TMP"; revoke_grant "$FAM"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
# Free has no undo-operation: the undo data is kept for Pro, so run the ability's undo callback on the logged data.
undo() { wpe "wp_set_current_user(1); global \$wpdb; \$r = \$wpdb->get_row(\$wpdb->prepare(\"SELECT ability, undo_json FROM {\$wpdb->prefix}cm_ai_log WHERE id = %d\", $1)); \$x = call_user_func(wp_get_ability(\$r->ability)->get_meta_item('cm_undo_callback'), json_decode(\$r->undo_json, true)); echo is_wp_error(\$x) ? \$x->get_error_message() : '{\"undone\":$1}';"; }
ok() { check "$1" "$2" '!~"isError":true'; }
err() { check "$1" "$2" '~"isError":true'; }
sc() { jq -r ".result.structuredContent$2" <<<"$1"; }
# img W H png|jpg R OUT: a solid image made with GD.
img() { php -r '$i = imagecreatetruecolor((int) $argv[1], (int) $argv[2]); imagefill($i, 0, 0, imagecolorallocate($i, (int) $argv[4], 80, 120)); "png" === $argv[3] ? imagepng($i, $argv[5]) : imagejpeg($i, $argv[5]);' "$@"; }
# send TOOL FILE NAME [',"k":v' extra fields]: two chunks, the second one done.
send() {
    local half=$(( $(stat -c %s "$2") / 2 )) r up
    r=$(call "$1" "{\"filename\":\"$3\",\"data\":\"$(head -c "$half" "$2" | base64 -w0)\"${4:-}}")
    up=$(sc "$r" .upload_id)
    call "$1" "{\"upload_id\":\"$up\",\"offset\":$half,\"data\":\"$(tail -c +$((half + 1)) "$2" | base64 -w0)\",\"done\":true${4:-}}"
}
logrow() { wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT id FROM {\$wpdb->prefix}cm_ai_log WHERE ability = 'classic-monks/$1' AND status = 'ok' AND undo_json IS NOT NULL ORDER BY id DESC LIMIT 1\");"; }
field() { wpe "echo get_post_field('$2', $1);"; }
alt() { wpe "echo get_post_meta($1, '_wp_attachment_image_alt', true);"; }

LIST=$(rpc tools/list)
for t in update-media replace-media restore-replaced-media; do
    check "hidden from a full app password: $t" "$LIST" "!~\"name\":\"$t\""
done
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
rpc() { brpc "$OP" "$@"; }
LIST=$(rpc tools/list)
for t in get-media upload-media update-media replace-media restore-replaced-media; do
    check "listed for the operator: $t" "$LIST" "~\"name\":\"$t\""
done

# upload-media takes any type the site allows now, not only images; still refuses the rest.
img 300 200 jpg 200 "$TMP/photo.jpg"
R=$(send upload-media "$TMP/photo.jpg" cm-m2-photo.jpg ',"alt":"A photo"')
ok 'upload an image' "$R"
M=$(sc "$R" .attachment_id)
printf '%%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%%%EOF\n' >"$TMP/doc.pdf"
R=$(send upload-media "$TMP/doc.pdf" cm-m2-doc.pdf)
check 'upload a PDF'                        "$(sc "$R" .mime_type)" application/pdf
D=$(sc "$R" .attachment_id)
err 'a PHP file is refused'                 "$(call upload-media "{\"filename\":\"x.php\",\"data\":\"$(printf '<?php echo 1;' | base64 -w0)\",\"done\":true}")"
# The site's own upload rules apply: WordPress checks a file's content against its extension, unless the
# theme turns that off (Bricks does, for its SVG uploads); then an HTML file named .pdf is taken as a PDF, as in wp-admin.
R=$(call upload-media "{\"filename\":\"x.pdf\",\"data\":\"$(printf '<html><body>hi</body></html>' | base64 -w0)\",\"done\":true}")
if [[ $(wpe 'echo get_template();') == bricks ]]; then
    check 'HTML named .pdf follows the site rules (Bricks: taken)' "$(sc "$R" .mime_type)" application/pdf
    wpe "remove_all_filters('pre_delete_attachment'); wp_delete_attachment($(sc "$R" .attachment_id), true);" >/dev/null
else
    err 'HTML named .pdf is refused' "$R"
fi

# get-media: the details panel's fields, one item by id, month filter, usage on request.
R=$(call get-media "{\"id\":$M}")
check 'get-media by id gives one item'      "$(sc "$R" .total)" 1
check 'get-media gives the author'          "$(sc "$R" '.media[0].author')" "$(wpe 'echo get_userdata(1)->display_name;')"
check 'get-media gives the file name'       "$(sc "$R" '.media[0].file_name')" cm-m2-photo.jpg
check 'get-media gives the dimensions'      "$(sc "$R" '.media[0] | "\(.width)x\(.height)"')" 300x200
check 'get-media gives the file size'       "$(sc "$R" '.media[0].file_size')" "$(stat -c %s "$TMP/photo.jpg")"
check 'usage is not counted unless asked'   "$(sc "$R" '.media[0].usage')" null
check 'get-media gives the allowed types'   "$(sc "$R" '.allowed_types["jpg|jpeg|jpe"]')" image/jpeg
check 'get-media gives the upload limit'    "$(sc "$R" '.upload_limit > 0')" true
check 'unused media counts 0'               "$(sc "$(call get-media "{\"id\":$M,\"usage\":true}")" '.media[0].usage')" 0
P1=$(wpe "\$p = wp_insert_post(['post_title' => 'cm m2 featured', 'post_status' => 'publish']); set_post_thumbnail(\$p, $M); echo \$p;")
P2=$(wpe "echo wp_insert_post(['post_title' => 'cm m2 inline', 'post_status' => 'draft', 'post_content' => '<!-- wp:image {\"id\":$M} --><figure><img src=\"' . wp_get_attachment_url($M) . '\" class=\"wp-image-$M\"/></figure><!-- /wp:image -->']);")
P3=$(wpe "echo wp_insert_post(['post_title' => 'cm m2 other', 'post_status' => 'publish', 'post_content' => '<img class=\"wp-image-${M}9\"/>']);")
check 'featured and inline uses count 2'    "$(sc "$(call get-media "{\"id\":$M,\"usage\":true}")" '.media[0].usage')" 2
check 'month filter finds this month'       "$(sc "$(call get-media "{\"id\":$M,\"month\":\"$(date +%Y-%m)\"}")" .total)" 1
check 'month filter leaves out other months' "$(sc "$(call get-media '{"month":"2001-01"}')" .total)" 0
err 'a bad month is refused'                "$(call get-media '{"month":"May"}')"

# update-media: only the fields sent change; an empty string clears one; undo puts them back.
R=$(call update-media "{\"id\":$M,\"title\":\"Sunset\",\"caption\":\"By the sea\",\"description\":\"<p>Long text</p>\"}")
ok 'update-media' "$R"
check 'title saved'                         "$(field "$M" post_title)" Sunset
check 'caption saved as the excerpt'        "$(field "$M" post_excerpt)" 'By the sea'
check 'description saved as the content'    "$(field "$M" post_content)" '<p>Long text</p>'
check 'alt left alone'                      "$(alt "$M")" 'A photo'
check 'update-media returns the details'    "$(sc "$R" .alt)" 'A photo'
ok 'update-media alt only' "$(call update-media "{\"id\":$M,\"alt\":\"  Sun <b>set</b> \"}")"
check 'alt saved as plain text'             "$(alt "$M")" 'Sun set'
X=$(logrow update-media)
ok 'update-media clears the caption' "$(call update-media "{\"id\":$M,\"caption\":\"\"}")"
check 'caption cleared'                     "$(field "$M" post_excerpt)" ''
check 'undo restores the caption'           "$(undo $(logrow update-media))" '~"undone"'
check 'caption is back'                     "$(field "$M" post_excerpt)" 'By the sea'
ok 'undo the alt change' "$(undo $X)"
check 'alt is back'                         "$(alt "$M")" 'A photo'
err 'update-media needs a field'            "$(call update-media "{\"id\":$M}")"
check 'update-media refuses a post'         "$(call update-media "{\"id\":$P1,\"title\":\"x\"}")" '~"cause":"capability"'
check 'the post title is untouched'         "$(field "$P1" post_title)" 'cm m2 featured'
U=$(wpe 'echo wp_insert_user(["user_login" => "cm_m2_author", "user_pass" => wp_generate_password(), "role" => "author"]);')
perm() { wpe "wp_set_current_user($U); var_export(wp_get_ability('classic-monks/$1')->check_permissions($2));"; }
check 'an author may not edit media of others' "$(perm update-media "['id' => $M, 'alt' => 'x']")" false
check 'an author may not replace it'        "$(perm replace-media "['id' => $M, 'data' => 'eA==']")" false

# replace-media: same ID and URL, new size, thumbnails made again, one backup.
URL=$(wpe "echo wp_get_attachment_url($M);")
DIR=$(wpe "echo dirname(get_attached_file($M));")
img 400 300 jpg 30 "$TMP/bigger.jpg"
R=$(send replace-media "$TMP/bigger.jpg" anything.jpg ",\"id\":$M")
ok 'replace-media' "$R"
check 'same ID'                             "$(sc "$R" .id)" "$M"
check 'same URL'                            "$(sc "$R" .url)" "$URL"
check 'new dimensions'                      "$(sc "$R" ' | "\(.width)x\(.height)"')" 400x300
check 'the file on disk is the new one'     "$(md5sum <"$DIR/cm-m2-photo.jpg")" "$(md5sum <"$TMP/bigger.jpg")"
check 'a backup is kept'                    "$(sc "$R" .has_backup)" true
check 'the backup is the old file'          "$(wpe "\$b = get_post_meta($M, '_cm_replaced_backup', true); echo md5_file(wp_upload_dir()['basedir'] . '/' . \$b['file']);")" "$(md5sum "$TMP/photo.jpg" | cut -d' ' -f1)"
check 'the thumbnail was made again'        "$(wpe "\$m = wp_get_attachment_metadata($M); \$t = \$m['sizes']['thumbnail']; echo file_exists('$DIR/' . \$t['file']) && 100 !== \$t['height'] ? 'new' : 'old';")" new
check 'sizes match the new image'           "$(wpe "\$m = wp_get_attachment_metadata($M); echo \$m['sizes']['medium']['file'];")" cm-m2-photo-300x225.jpg
check 'no stray files of the old image'     "$(cd "$DIR" && ls cm-m2-photo* | sort | tr '\n' ' ')" "$(wpe "\$m = wp_get_attachment_metadata($M); \$f = array_column(\$m['sizes'], 'file'); \$f[] = 'cm-m2-photo.jpg'; \$f = array_unique(\$f); sort(\$f); echo implode(' ', \$f), ' ';")"
check 'the stored undo data has no file data' "$(wpe "global \$wpdb; echo strlen(\$wpdb->get_var(\"SELECT undo_json FROM {\$wpdb->prefix}cm_ai_log WHERE id = $(logrow replace-media)\")) < 2000 ? 'small' : 'big';")" small

# restore-replaced-media swaps back, and again; undo of a stale replace is refused.
R=$(call restore-replaced-media "{\"id\":$M}")
check 'restore puts the old file back'      "$(sc "$R" ' | "\(.width)x\(.height) \(.url)"')" "300x200 $URL"
check 'the old bytes are back'              "$(md5sum <"$DIR/cm-m2-photo.jpg")" "$(md5sum <"$TMP/photo.jpg")"
check 'the replaced file is the backup now' "$(sc "$R" .has_backup)" true
check 'undo of the replace is refused after a restore' "$(undo $(logrow replace-media))" '~can no longer be undone'
ok 'undo of the restore' "$(undo $(logrow restore-replaced-media))"
check 'the new file is back'                "$(sc "$(call get-media "{\"id\":$M}")" '.media[0] | "\(.width)x\(.height)"')" 400x300
check 'only one backup on disk'             "$(ls "$(wpe "echo wp_upload_dir()['basedir'];")/cm-replaced/$M" | wc -l)" 1

# Replacing with another image type keeps the name, with the new extension; keep_name uses the new name.
img 320 240 png 90 "$TMP/shot.png"
R=$(send replace-media "$TMP/shot.png" shot.png ",\"id\":$M")
check 'jpg replaced by png'                 "$(sc "$R" ' | "\(.mime_type) \(.file_name)"')" 'image/png cm-m2-photo.png'
check 'the old jpg is gone'                 "$(ls "$DIR"/cm-m2-photo*.jpg 2>/dev/null | wc -l)" 0
check 'undo brings the jpg back'            "$(undo $(logrow replace-media))" '~"undone"'
check 'the jpg is the file again'           "$(sc "$(call get-media "{\"id\":$M}")" '.media[0] | "\(.mime_type) \(.file_name)"')" 'image/jpeg cm-m2-photo.jpg'
R=$(send replace-media "$TMP/shot.png" 'New Name.png' ",\"id\":$M,\"keep_name\":true")
check 'keep_name uses the new file name'    "$(sc "$R" .file_name)" New-Name.png
check 'and the URL follows it'              "$(sc "$R" .url)" "~/New-Name.png"

# Type rules and refusals leave the file alone.
SIZE=$(wpe "echo filesize(get_attached_file($M));")
R=$(send replace-media "$TMP/doc.pdf" doc.pdf ",\"id\":$M")
err 'an image cannot become a PDF'          "$R"
check 'the refusal says why'                "$R" '~This image can only be replaced by another image, not application'
check 'the image is untouched'              "$(wpe "echo filesize(get_attached_file($M));")" "$SIZE"
R=$(send replace-media "$TMP/shot.png" shot.png ",\"id\":$D")
err 'a PDF cannot become an image'          "$R"
check 'and says it takes only a PDF'        "$R" '~file can only be replaced by another application'
printf '%%PDF-1.4\n%% second\ntrailer<<>>\n%%%%EOF\n' >"$TMP/doc2.pdf"
R=$(send replace-media "$TMP/doc2.pdf" whatever.pdf ",\"id\":$D")
check 'a PDF can replace a PDF'             "$(sc "$R" .file_name)" cm-m2-doc.pdf
check 'the PDF has its new bytes'           "$(sc "$R" .file_size)" "$(stat -c %s "$TMP/doc2.pdf")"
err 'no backup, nothing to restore'         "$(call restore-replaced-media "{\"id\":$P1}")"
N=$(wpe "echo wp_insert_attachment(['post_title' => 'cm m2 nofile', 'post_mime_type' => 'image/png', 'post_status' => 'inherit']);")
check 'restore without a backup is refused' "$(call restore-replaced-media "{\"id\":$N}")" '~no earlier file'
R=$(call replace-media "{\"id\":$M,\"filename\":\"a.png\",\"data\":\"$(head -c 40 "$TMP/shot.png" | base64 -w0)\"}")
check 'an upload for one item cannot finish on another' "$(call replace-media "{\"id\":$D,\"upload_id\":\"$(sc "$R" .upload_id)\",\"offset\":40,\"data\":\"$(tail -c +41 "$TMP/shot.png" | base64 -w0)\",\"done\":true}")" '~unknown or expired'

# Core's ability REST (the wp-admin AI chat, plain app passwords) runs no operator-only write; reads still run.
ab() { curl -sk -u "$PLAIN" -H 'Content-Type: application/json' -d "{\"input\":$2}" "$SITE/wp-json/wp-abilities/v1/abilities/classic-monks/$1/run"; }
check 'update-media over core REST is refused' "$(ab update-media "{\"id\":$M,\"alt\":\"from the chat\"}")" '~cm_operator_only'
check 'restore over core REST is refused'   "$(ab restore-replaced-media "{\"id\":$M}")" '~cm_operator_only'
check 'the alt text is unchanged'           "$(alt "$M")" '!from the chat'
check 'an operator-only read still runs there' "$(code -u "$PLAIN" "$SITE/wp-json/wp-abilities/v1/abilities/classic-monks/list-post-types/run")" 200

# The kept files: no folder listing, one swap at a time, and a backup path only inside its own folder.
UP=$(wpe "echo wp_upload_dir()['basedir'];")
check 'the backup folder cannot be listed'  "$(test -f "$UP/cm-replaced/index.html" && echo yes)" yes
wpe "add_option('cm_media_swap_$M', time(), '', false);" >/dev/null
check 'a second swap at once is refused'    "$(call restore-replaced-media "{\"id\":$M}")" '~being replaced right now'
wpe "delete_option('cm_media_swap_$M');" >/dev/null
KEPT=$(wpe "echo wp_json_encode(get_post_meta($M, '_cm_replaced_backup', true));")
wpe "update_post_meta($M, '_cm_replaced_backup', ['token' => 'x', 'file' => 'cm-replaced/$M/../../../wp-config.php', 'name' => 'x.jpg', 'mime' => 'image/jpeg']);" >/dev/null
check 'a backup path outside its folder is refused' "$(call restore-replaced-media "{\"id\":$M}")" '~no earlier file'
check 'and nothing outside was moved'       "$(test -f "$SITE_ROOT/wp-config.php" && echo kept)" kept
wpe "update_post_meta($M, '_cm_replaced_backup', json_decode('$KEPT', true));" >/dev/null

# Deleting the attachment deletes its backup.
BK="$(wpe "echo wp_upload_dir()['basedir'];")/cm-replaced/$M"
check 'the backup folder exists'            "$(test -d "$BK" && echo yes)" yes
wpe "remove_all_filters('pre_delete_attachment'); wp_delete_attachment($M, true);" >/dev/null # past the media trash module
check 'deleting the item deletes its backup' "$(test -d "$BK" && echo still || echo gone)" gone

wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ([$P1, $P2, $P3] as \$p) wp_delete_post(\$p, true); remove_all_filters('pre_delete_attachment'); foreach ([$D, $N] as \$a) wp_delete_attachment(\$a, true); wp_delete_user($U);" >/dev/null
done_checks
