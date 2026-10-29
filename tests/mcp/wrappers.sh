#!/usr/bin/env bash
# Comments, terms and media over MCP. Run: bash tests/mcp/wrappers.sh
. "$(dirname "$0")/lib.sh"
wpe "update_user_meta(1, 'cm_mcp_trusted', ['$MCP_UUID' => true]);" >/dev/null

P=$(wpe 'echo wp_insert_post(["post_title" => "cm wrappers", "post_status" => "publish"]);')
C=$(wpe "echo wp_insert_comment(['comment_post_ID' => $P, 'comment_content' => 'hi', 'comment_approved' => 0]);")
R=$(call moderate-comment '{"comment_ids":['"$C"'],"action":"approve"}')
check 'moderate-comment approves'     "$R" '~"updated":1'
check 'comment is approved'           "$(wpe "echo wp_get_comment_status($C);")" approved
check 'reply-to-comment'              "$(call reply-to-comment "{\"comment_id\":$C,\"content\":\"thanks\"}")" '~"parent_id"'
T=$(wpe "\$t = wp_insert_term('cm wrap term', 'category'); echo \$t['term_id'];")
check 'assign-terms'                  "$(call assign-terms "{\"post_id\":$P,\"taxonomy\":\"category\",\"term_ids\":[$T]}")" '~"term_ids":['"$T"
check 'update-term'                   "$(call update-term "{\"term_id\":$T,\"name\":\"cm renamed\"}")" '~"name":"cm renamed"'
check 'delete-term'                   "$(call delete-term "{\"term_id\":$T}")" '~"deleted":true'

# import-media-from-url (not Bricks-gated: listed in the loader's media group)
check 'import-media-from-url listed'  "$(rpc tools/list)" '~"name":"import-media-from-url"'
R=$(call import-media-from-url "{\"url\":\"https://s.w.org/style/images/about/WordPress-logotype-wmark.png\",\"alt\":\"cm logo\",\"caption\":\"cap\",\"post_id\":$P}")
A=$(sed -nE 's/.*"attachment_id":([0-9]+).*/\1/p' <<<"$R")
check 'import-media-from-url imports' "$(wpe "echo get_post_mime_type(${A:-0}), '|', get_post_meta(${A:-0}, '_wp_attachment_image_alt', true), '|', get_post_field('post_excerpt', ${A:-0}), '|', wp_get_post_parent_id(${A:-0});")" "image/png|cm logo|cap|$P"
for c in 'http://127.0.0.1/x.png|private or reserved' 'http://169.254.169.254/|private or reserved' 'file:///etc/passwd|http(s) image URL' 'https://wordpress.org/|not an image'; do
    check "import-media-from-url refuses ${c%%|*}" "$(call import-media-from-url "{\"url\":\"${c%%|*}\"}")" "~${c#*|}"
done
check 'get-media finds it'             "$(call get-media '{"search":"WordPress-logotype"}')" "~\"id\":${A:-0}"
check 'SVG is refused'                "$(call import-media-from-url '{"url":"https://www.w3.org/Icons/SVG/svg-logo-v.svg"}')" '~SVG files cannot be imported'
[[ -n $A ]] && wpe "wp_delete_attachment($A, true);" >/dev/null

wpe "wp_delete_post($P, true);" >/dev/null
done_checks
