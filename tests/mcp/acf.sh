#!/usr/bin/env bash
# Monks Central ACF fields: get-acf-fields and update-acf-fields with ACF free from wordpress.org, then the
# PRO types (repeater, gallery, flexible content) with Secure Custom Fields. Installs both plugins and deletes
# them, the field groups and the test posts at the end. Run: bash tests/mcp/acf.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"
FIX="$(cd "$(dirname "$0")" && pwd)/acf-fixture.php"
wpc() { (cd "$SITE_ROOT" && $WP_CMD "$@" 2>/dev/null); }
cleanup() {
    wpe "foreach (get_posts(['post_type' => ['post', 'page'], 's' => 'cm acf', 'post_status' => 'any', 'numberposts' => -1]) as \$p) wp_delete_post(\$p->ID, true); remove_all_filters('pre_delete_attachment'); foreach (get_posts(['post_type' => 'attachment', 's' => 'cm acf', 'numberposts' => -1]) as \$a) wp_delete_attachment(\$a->ID, true); if (function_exists('acf_get_field_group')) foreach (['group_cm_acf', 'group_cm_acf_page'] as \$k) { \$g = acf_get_field_group(\$k); if (\$g) acf_delete_field_group(\$g['ID']); }" >/dev/null
    wpc plugin deactivate advanced-custom-fields secure-custom-fields --quiet
    wpc plugin delete advanced-custom-fields secure-custom-fields --quiet
}
trap 'cleanup; rm -f "${OAUTH_JARS[@]}"; revoke_grant "$FAM"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT
# Free has no undo-operation: the undo data is kept for Pro, so run the ability's undo callback on the logged data.
undo() { wpe "wp_set_current_user(1); global \$wpdb; \$r = \$wpdb->get_row(\$wpdb->prepare(\"SELECT ability, undo_json FROM {\$wpdb->prefix}cm_ai_log WHERE id = %d\", $1)); \$x = call_user_func(wp_get_ability(\$r->ability)->get_meta_item('cm_undo_callback'), json_decode(\$r->undo_json, true)); echo is_wp_error(\$x) ? \$x->get_error_message() : '{\"undone\":$1}';"; }
ok() { check "$1" "$2" '!~"isError":true'; }
err() { check "$1" "$2" '~"isError":true'; }
sc() { jq -r ".result.structuredContent$2" <<<"$1"; }
logrow() { wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT id FROM {\$wpdb->prefix}cm_ai_log WHERE ability = 'classic-monks/update-acf-fields' AND status = 'ok' AND undo_json IS NOT NULL ORDER BY id DESC LIMIT 1\");"; }
meta() { wpe "echo wp_json_encode(get_post_meta($1, '$2', true));"; }
has() { wpe "var_export(metadata_exists('post', $1, '$2'));"; }

# Without ACF the tools are not there at all.
wpc plugin deactivate advanced-custom-fields secure-custom-fields --quiet
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Monks Central' -d operator=1)
OP=$(jq -r .access_token <<<"$T"); FAM=$(jq -r .family <<<"$T")
orpc() { brpc "$OP" "$@"; }
ocall() { bcall "$OP" "$@"; }
check 'no ACF: get-acf-fields is not listed' "$(orpc tools/list)" '!~"name":"get-acf-fields"'
check 'no ACF: update-acf-fields is not listed' "$(orpc tools/list)" '!~"name":"update-acf-fields"'

wpc plugin install advanced-custom-fields --activate --quiet
check 'ACF free is active'                  "$(wpe 'echo defined("ACF_VERSION") && ! defined("ACF_PRO") ? "free" : "no";')" free
LIST=$(rpc tools/list)
check 'get-acf-fields listed for an MCP client' "$LIST" '~"name":"get-acf-fields"'
check 'update-acf-fields is Monks Central only' "$LIST" '!~"name":"update-acf-fields"'
check 'the operator gets update-acf-fields' "$(orpc tools/list)" '~"name":"update-acf-fields"'

check 'the fixture group is made'           "$(wpc eval-file "$FIX" 'cm acf')" group_cm_acf
wpe "acf_import_field_group(['key' => 'group_cm_acf_page', 'title' => 'cm acf page only', 'fields' => [['key' => 'field_cm_acf_pageonly', 'name' => 'cm_acf_pageonly', 'label' => 'Page only', 'type' => 'text']], 'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]]]);" >/dev/null
IMG=$(wpe "\$f = wp_upload_dir()['path'] . '/cm-acf.png'; imagepng(imagecreatetruecolor(20, 20), \$f); echo wp_insert_attachment(['post_title' => 'cm acf image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], \$f);")
P=$(wpe "echo wp_insert_post(['post_title' => 'cm acf post', 'post_status' => 'draft']);")
O=$(wpe "echo wp_insert_post(['post_title' => 'cm acf other', 'post_status' => 'publish']);")

R=$(ocall get-acf-fields "{\"post_id\":$P}")
ok 'get-acf-fields' "$R"
check 'only the groups for this entry'      "$(sc "$R" '.groups | map(.title) | join(",")')" 'cm acf'
check 'every value field, no layout ones'   "$(sc "$R" '.groups[0].fields | map(.type) | join(",")')" 'text,textarea,number,range,email,url,password,select,checkbox,radio,button_group,true_false,wysiwyg,image,file,date_picker,date_time_picker,time_picker,color_picker,link,post_object,page_link,relationship,taxonomy,user,group,oembed'
F() { sc "$R" ".groups[0].fields[] | select(.name == \"cm_acf_$1\") | $2"; }
check 'a field has key, label and required' "$(F text '"\(.key) \(.label) \(.required) \(.instructions)"')" 'field_cm_acf_text Text true Required.'
check 'supported types are editable'        "$(sc "$R" ' | [.groups[0].fields[] | select(.editable | not) | .type] | join(",")')" oembed
check 'choices come with the field'         "$(F select .settings.choices.green)" Green
check 'limits come with the field'          "$(F number '"\(.settings.min)-\(.settings.max)"')" 1-10
check 'a group lists its sub-fields'        "$(F group '.sub_fields | map(.name) | join(",")')" line,count
check 'an empty post has no values'         "$(F text .value)" ''

FIELDS='"field_cm_acf_text":"Hello","cm_acf_number":7,"field_cm_acf_checkbox":["a","c"],"field_cm_acf_true_false":true,"field_cm_acf_image":'$IMG',"field_cm_acf_relationship":['$O'],"field_cm_acf_taxonomy":[1],"field_cm_acf_user":1,"field_cm_acf_group":{"field_cm_acf_group_line":"inside","field_cm_acf_group_count":3},"field_cm_acf_link":{"title":"Ex","url":"https://example.com/","target":""},"field_cm_acf_date_picker":"20261028"'
R=$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{$FIELDS}}")
ok 'update-acf-fields' "$R"
check 'it lists what it saved'              "$(sc "$R" '.updated | length')" 11
check 'text saved'                          "$(wpe "echo get_field('cm_acf_text', $P);")" Hello
check 'a field named by name saved'         "$(wpe "echo get_field('cm_acf_number', $P);")" 7
check 'checkbox saved'                      "$(wpe "echo implode(',', get_field('cm_acf_checkbox', $P));")" a,c
check 'true/false saved as 1'               "$(meta "$P" cm_acf_true_false)" '"1"'
check 'group saved as ACF stores it'        "$(meta "$P" cm_acf_group_line)" '"inside"'
check 'ACF wrote its field reference'       "$(meta "$P" _cm_acf_text)" '"field_cm_acf_text"'
check 'it returns the saved values'         "$(sc "$R" '.values.field_cm_acf_group.field_cm_acf_group_count')" 3
R=$(ocall get-acf-fields "{\"post_id\":$P}")
check 'values come back keyed by field key' "$(F group '.value.field_cm_acf_group_line')" inside
check 'refs name the image'                 "$(sc "$R" ".refs.media[\"$IMG\"].title")" 'cm acf image'
check 'refs name the related post'          "$(sc "$R" ".refs.posts[\"$O\"].title")" 'cm acf other'
check 'refs name the term'                  "$(sc "$R" '.refs.terms["1"].name')" Uncategorized
check 'refs name the user'                  "$(sc "$R" '.refs.users["1"].name')" "$(wpe 'echo get_userdata(1)->display_name;')"

# ACF validates first; one bad value saves nothing, and the messages are plain text with the label.
R=$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_text\":\"Changed\",\"field_cm_acf_email\":\"nope\"}}")
err 'a bad email is refused'                "$R"
check 'the message is plain and names the field' "$R" "~Email: 'nope' is not a valid email address"
check 'nothing was saved'                   "$(wpe "echo get_field('cm_acf_text', $P);")" Hello
check 'a required field cannot be emptied'  "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_text\":\"\"}}")" '~Text value is required'
check 'number limits come from ACF'      "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_number\":11}}")" '~Number: Value must be equal to or lower than 10'
check 'sub-fields are validated too'        "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_group\":{\"field_cm_acf_group_line\":\"x\",\"field_cm_acf_group_count\":9}}}")" '~Count: Value must be equal to or lower than 5'
check 'a field of another group is refused' "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_pageonly\":\"x\"}}")" '~is not an ACF field of this entry'
check 'an unknown field is refused'         "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"nope\":\"x\"}}")" '~is not an ACF field of this entry'
check 'a read-only type is refused'         "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_oembed\":\"https://example.com/\"}}")" '~can only be changed in WordPress'
check 'meta outside ACF cannot be reached'  "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"_edit_lock\":\"1\"}}")" '~is not an ACF field of this entry'
err 'an empty fields object is refused'     "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{}}")"
U=$(wpe 'echo wp_insert_user(["user_login" => "cm_acf_contrib", "user_pass" => wp_generate_password(), "role" => "contributor"]);')
check 'a contributor may not read the fields of a post of others' "$(wpe "wp_set_current_user($U); var_export(wp_get_ability('classic-monks/get-acf-fields')->check_permissions(['post_id' => $P]));")" false
wpe "require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($U);" >/dev/null

# Undo puts every field back, deleting the ones that had no value.
X=$(logrow)
ok 'one more change' "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_text\":\"Second\"}}")"
ok 'undo the last change' "$(undo $(logrow))"
check 'the earlier text is back'            "$(wpe "echo get_field('cm_acf_text', $P);")" Hello
ok 'undo the first change' "$(undo $X)"
check 'fields that were empty are gone'     "$(has "$P" cm_acf_text) $(has "$P" cm_acf_group_line) $(has "$P" cm_acf_checkbox)" 'false false false'

# A field the site hides from this user (acf/prepare_field returns false) is neither listed nor written.
MU="$SITE_ROOT/wp-content/mu-plugins/cm-acf-hide.php"
mkdir -p "$(dirname "$MU")"
echo "<?php add_filter('acf/prepare_field/key=field_cm_acf_password', '__return_false');" >"$MU"
check 'a hidden field is not listed'        "$(ocall get-acf-fields "{\"post_id\":$P}")" '!~"key":"field_cm_acf_password"'
check 'a hidden field is not written'       "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_password\":\"x\"}}")" '~is not an ACF field of this entry'
rm -f "$MU"
check 'shown again without the filter'      "$(ocall get-acf-fields "{\"post_id\":$P}")" '~"key":"field_cm_acf_password"'

# Secure Custom Fields (the wordpress.org fork) has the PRO types: repeater, gallery, flexible content.
wpc plugin deactivate advanced-custom-fields --quiet
wpc plugin install secure-custom-fields --activate --quiet
check 'the fixture adds the PRO types'      "$(wpc eval-file "$FIX" 'cm acf')" group_cm_acf
R=$(ocall get-acf-fields "{\"post_id\":$P}")
check 'repeater and gallery are editable, flexible content is not' "$(sc "$R" '.groups[0].fields[] | select(.type | test("repeater|gallery|flexible")) | "\(.type)=\(.editable)"' | tr '\n' ' ')" 'repeater=true gallery=true flexible_content=false '
check 'a repeater lists its sub-fields'     "$(F repeater '.sub_fields | map(.name) | join(",")')" label,photo
check 'an empty repeater is an empty list'  "$(F repeater '.value | length')" 0
ROWS='[{"field_cm_acf_repeater_label":"Row one","field_cm_acf_repeater_photo":'$IMG'},{"field_cm_acf_repeater_label":"Row two","field_cm_acf_repeater_photo":""}]'
R=$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_repeater\":$ROWS,\"field_cm_acf_gallery\":[$IMG]}}")
ok 'save repeater rows and a gallery' "$R"
check 'the rows are saved'                  "$(wpe "\$r = get_field('cm_acf_repeater', $P); echo count(\$r), ' ', \$r[1]['label'];")" '2 Row two'
check 'the gallery is saved'                "$(wpe "echo implode(',', get_field('cm_acf_gallery', $P, false));")" "$IMG"
R=$(ocall get-acf-fields "{\"post_id\":$P}")
check 'rows come back keyed by field key'   "$(F repeater '.value[0].field_cm_acf_repeater_label')" 'Row one'
check 'refs reach into repeater rows'       "$(sc "$R" ".refs.media[\"$IMG\"].title")" 'cm acf image'
check 'a required sub-field in a row is checked' "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_repeater\":[{\"field_cm_acf_repeater_label\":\"\"}]}}")" '~Label value is required'
check 'the rows are untouched'              "$(wpe "echo count(get_field('cm_acf_repeater', $P));")" 2
check 'flexible content is read-only'       "$(ocall update-acf-fields "{\"post_id\":$P,\"fields\":{\"field_cm_acf_flexible_content\":[]}}")" '~can only be changed in WordPress'
ok 'undo the rows' "$(undo $(logrow))"
check 'the repeater is gone again'          "$(has "$P" cm_acf_repeater) $(has "$P" cm_acf_repeater_0_label)" 'false false'

done_checks
