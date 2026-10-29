#!/usr/bin/env bash
# MCP 2026-07-28 + legacy dual-era checks. Run: bash tests/mcp/protocol.sh
. "$(dirname "$0")/lib.sh"

V=2026-07-28
META='"_meta":{"io.modelcontextprotocol/protocolVersion":"'$V'","io.modelcontextprotocol/clientInfo":{"name":"cm-test","version":"1"},"io.modelcontextprotocol/clientCapabilities":{}}'
META_ELICIT='"_meta":{"io.modelcontextprotocol/protocolVersion":"'$V'","io.modelcontextprotocol/clientCapabilities":{"elicitation":{"url":{}}}}'

# modern METHOD NAME PARAMS_INNER(without braces) [extra curl args] -> "HTTPCODE BODY"
modern() {
    local method=$1 name=$2 inner=$3; shift 3
    local hdr=(-H "MCP-Protocol-Version: $V" -H "Mcp-Method: $method")
    [[ -n $name ]] && hdr+=(-H "Mcp-Name: $name")
    curl -sk -w ' HTTP%{http_code}' -u "$AUTH" -H 'Content-Type: application/json' "${hdr[@]}" "$@" \
        -d "{\"jsonrpc\":\"2.0\",\"id\":7,\"method\":\"$method\",\"params\":{${inner}}}" "$MCP"
}

R=$(modern server/discover '' "$META")
check 'discover lists 2026-07-28'          "$R" '~"supportedVersions":["2026-07-28","2025-11-25"'
check 'discover result is complete'        "$R" '~"resultType":"complete"'
check 'discover carries serverInfo meta'   "$R" '~io.modelcontextprotocol\/serverInfo'

R=$(modern tools/list '' "$META")
check 'modern tools/list has ttlMs'        "$R" '~"ttlMs":300000'
check 'modern tools/list is private cache' "$R" '~"cacheScope":"private"'
NAMES=$(grep -o '"name":"[a-z0-9-]*","title"' <<<"${R%% HTTP*}" | cut -d'"' -f4)
check 'tools are sorted by name'           "$([[ "$NAMES" == "$(LC_ALL=C sort <<<"$NAMES")" ]] && echo sorted)" sorted
check 'tools advertise outputSchema'       "$R" '~"outputSchema"'

R=$(modern tools/call get-site-info "\"name\":\"get-site-info\",\"arguments\":{},$META")
check 'modern call returns structuredContent' "$R" '~"structuredContent":{"wordpress"'
check 'modern call is HTTP 200'            "$R" '~HTTP200'

check 'missing Mcp-Name is HeaderMismatch'   "$(curl -sk -w ' HTTP%{http_code}' -u "$AUTH" -H 'Content-Type: application/json' -H "MCP-Protocol-Version: $V" -H 'Mcp-Method: tools/call' -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"get-site-info\",\"arguments\":{},$META}}" "$MCP")" '~-32020'
check 'header mismatch is HTTP 400'          "$(modern tools/call wrong-name "\"name\":\"get-site-info\",\"arguments\":{},$META")" '~HTTP400'
R=$(modern tools/list '' '"_meta":{"io.modelcontextprotocol/protocolVersion":"2099-01-01"}' -H 'MCP-Protocol-Version: 2099-01-01')
check 'unknown version is -32022'            "$R" '~-32022'
check 'unknown version lists supported'      "$R" '~"supported":["2026-07-28"'
check 'unknown modern method is 404'         "$(modern nope/nope '' "$META")" '~HTTP404'
check 'ping is gone in modern'               "$(modern ping '' "$META")" '~HTTP404'

# Free ships no skills: prompts and resources are empty lists.
check 'discover advertises prompts'          "$(modern server/discover '' "$META")" '~"prompts":{"listChanged":false}'
check 'modern prompts/list is empty'         "$(modern prompts/list '' "$META")" '~"prompts":[]'
check 'resources/list is empty'              "$(rpc resources/list)" '~"resources":[]'
check 'unknown prompt is -32602 (modern)'    "$(modern prompts/get nope "\"name\":\"nope\",$META")" '~-32602'
check 'unknown prompt is -32002 (legacy)'    "$(rpc prompts/get '{"name":"nope"}')" '~-32002'

# Legacy clients still work on the same endpoint.
check 'legacy initialize still works'        "$(rpc initialize '{"protocolVersion":"2025-11-25"}')" '~"protocolVersion":"2025-11-25"'
check 'legacy ping still works'              "$(rpc ping)" '~"result":{}'
check 'legacy results have no resultType'    "$(rpc tools/list)" '!~resultType'

# 5.3 approval via URL elicitation (connection not trusted).
P=$(wpe 'echo wp_insert_post(["post_title" => "cm elicit test", "post_status" => "draft"]);')
R=$(modern tools/call delete-post "\"name\":\"delete-post\",\"arguments\":{\"post_id\":$P},$META_ELICIT")
check 'elicitation client gets input_required' "$R" '~"resultType":"input_required"'
check 'input request is url mode'             "$R" '~"mode":"url"'
check 'url points at the approve page'        "$R" '~cm-ai-approve&op='
R=$(modern tools/call delete-post "\"name\":\"delete-post\",\"arguments\":{\"post_id\":$P},\"inputResponses\":{\"approval\":{\"action\":\"cancel\"}},$META_ELICIT")
check 'client cancel falls back to approve_url' "$R" '~"approve_url"'
R=$(modern tools/call delete-post "\"name\":\"delete-post\",\"arguments\":{\"post_id\":$P},\"inputResponses\":{\"approval\":{\"action\":\"decline\"}},$META_ELICIT")
check 'client decline closes the request'     "$R" '~cm_approval_declined'
check 'post untouched after decline'          "$(wpe "echo get_post_status($P);")" draft
R=$(modern tools/call delete-post "\"name\":\"delete-post\",\"arguments\":{\"post_id\":$P},$META")
check 'non-elicitation modern client gets approve_url' "$R" '~"approve_url"'
wpe "wp_delete_post($P, true);" >/dev/null

# JSON-RPC batch (2025-03-26 servers must accept one): one reply per request, none for notifications, no nesting.
batch() { curl -sk -u "$AUTH" -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' -d "$1" "$MCP"; }
R=$(batch '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","id":2,"method":"tools/list"}]')
check 'batch answers each request'         "$R" '~[{"jsonrpc":"2.0","id":1,'
check 'batch includes tools/list'          "$R" '~"id":2,"result":{"tools"'
check 'batch skips notifications'          "$(grep -o '"jsonrpc"' <<<"$R" | wc -l | tr -d ' ')" 2
R=$(curl -sk -u "$AUTH" -H 'Content-Type: application/json' -H "MCP-Protocol-Version: $V" -H 'Mcp-Method: tools/list' -d '[{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{'"$META"'}},{"jsonrpc":"2.0","id":2,"method":"prompts/get","params":{"name":"no-such-skill"}}]' "$MCP")
check 'batch: modern item does not leak'   "$R" '~"id":2,"error":{"code":-32002'
check 'nested batch is invalid'            "$(batch '[[{"jsonrpc":"2.0","id":1,"method":"ping"}]]')" '~-32600'

done_checks
