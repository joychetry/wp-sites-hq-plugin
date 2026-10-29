#!/usr/bin/env bash
# Monks Central M12: refresh-token replay grace (60 s after rotation). Run: bash tests/mcp/oauth-grace.sh
. "$(dirname "$0")/lib.sh"

OA="$SITE/wp-json/classic-monks/v1/oauth"
CID=cmc_grace_test

# Prints "refresh_token family" for a new grant of user 1 to client $CID.
grant() {
    wpe "\$m = new ReflectionMethod('CM_MCP_OAuth', 'issue'); \$m->setAccessible(true); \$f = bin2hex(random_bytes(16)); CM_MCP_Server::save_connection_settings(1, 'oauth:' . \$f, 'read', ['content']); \$d = \$m->invoke(null, \$f, 1, hash('sha256', '$CID'), 'grace test', 'read')->get_data(); global \$wpdb; \$wpdb->update(\$wpdb->prefix . 'cm_mcp_oauth_tokens', ['resource' => '$MCP'], ['family' => \$f]); echo \$d['refresh_token'], ' ', \$f;"
}
refresh() { curl -sk -d grant_type=refresh_token -d "refresh_token=$1" -d "client_id=$CID" "$OA/token"; }
mcp_code() { curl -sk -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP"; }
family_rows() { wpe "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE family = '$1'\");"; }

# Lost response: the client sends the spent token again before using anything it was given.
read -r RT FAM <<<"$(grant)"
A=$(refresh "$RT")
check 'first refresh works'               "$(jq -r .token_type <<<"$A")" Bearer
B=$(refresh "$RT")
check 'replay within 60 s gets a new pair' "$(jq -r .token_type <<<"$B")" Bearer
check '... a different one'               "$(jq -r .refresh_token <<<"$B")" "!$(jq -r .refresh_token <<<"$A")"
check 'lost access token is dead'         "$(mcp_code "$(jq -r .access_token <<<"$A")")" 401
check 'new access token works'            "$(mcp_code "$(jq -r .access_token <<<"$B")")" 200
check 'the new pair refreshes'            "$(jq -r .token_type <<<"$(refresh "$(jq -r .refresh_token <<<"$B")")")" Bearer
# Whoever replayed may have been a thief: the real client presenting the lost refresh token revokes everything.
check 'lost refresh token later is reuse' "$(refresh "$(jq -r .refresh_token <<<"$A")")" '~already used'
check '... and revokes the family'        "$(family_rows "$FAM")" 0

# One grace per rotation: a second replay is reuse.
read -r RT FAM <<<"$(grant)"
refresh "$RT" >/dev/null
check 'first replay gets the grace'       "$(jq -r .token_type <<<"$(refresh "$RT")")" Bearer
check 'second replay is refused'          "$(refresh "$RT")" '~already used'
check '... and revokes the family'        "$(family_rows "$FAM")" 0

# The rotation's tokens were used, so the response was not lost: reuse is theft.
read -r RT FAM <<<"$(grant)"
A=$(refresh "$RT")
check 'rotated access token used'         "$(mcp_code "$(jq -r .access_token <<<"$A")")" 200
check 'replay after use is refused'       "$(refresh "$RT")" '~already used'
check '... and revokes the family'        "$(family_rows "$FAM")" 0

# A replay while the rotation hasn't issued its tokens (it never does here) gets no second chain beside it.
read -r RT FAM <<<"$(grant)"
wpe "global \$wpdb; \$wpdb->query(\$wpdb->prepare(\"UPDATE {\$wpdb->prefix}cm_mcp_oauth_tokens SET used = 1, last_used = %d WHERE token_hash = %s\", time(), hash('sha256', '$RT')));" >/dev/null
check 'replay during a rotation is refused' "$(refresh "$RT")" '~already used'
check '... and revokes the family'        "$(family_rows "$FAM")" 0

# Past the grace window.
read -r RT FAM <<<"$(grant)"
refresh "$RT" >/dev/null
wpe "global \$wpdb; \$wpdb->query(\$wpdb->prepare(\"UPDATE {\$wpdb->prefix}cm_mcp_oauth_tokens SET last_used = %d WHERE token_hash = %s\", time() - 61, hash('sha256', '$RT')));" >/dev/null
check 'replay after 60 s is refused'      "$(refresh "$RT")" '~already used'
check '... and revokes the family'        "$(family_rows "$FAM")" 0

done_checks
