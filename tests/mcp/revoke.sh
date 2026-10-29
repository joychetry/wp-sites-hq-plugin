#!/usr/bin/env bash
# Central C9: OAuth token revocation (RFC 7009). Run: bash tests/mcp/revoke.sh
. "$(dirname "$0")/lib.sh"

OA="$SITE/wp-json/classic-monks/v1/oauth"
CID=cmc_revoke_test

# Prints "access_token refresh_token family" for a new grant of user 1 to client $CID.
grant() {
    wpe "\$m = new ReflectionMethod('CM_MCP_OAuth', 'issue'); \$m->setAccessible(true); \$f = bin2hex(random_bytes(16)); CM_MCP_Server::save_connection_settings(1, 'oauth:' . \$f, 'read', ['content']); \$d = \$m->invoke(null, \$f, 1, hash('sha256', '$CID'), 'revoke test', 'read')->get_data(); global \$wpdb; \$wpdb->update(\$wpdb->prefix . 'cm_mcp_oauth_tokens', ['resource' => '$MCP'], ['family' => \$f]); echo \$d['access_token'], ' ', \$d['refresh_token'], ' ', \$f;"
}
revoke_tok() { curl -sk -o /dev/null -w '%{http_code}' "$@" "$OA/revoke"; }
mcp_code() { curl -sk -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP"; }
refresh() { curl -sk -d grant_type=refresh_token -d "refresh_token=$1" -d "client_id=$CID" "$OA/token"; }
family_rows() { wpe "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE family = '$1'\");"; }

ASM=$(curl -sk "$SITE/.well-known/oauth-authorization-server")
check 'metadata advertises revocation'  "$(jq -r .revocation_endpoint <<<"$ASM")" "$OA/revoke"
check 'revocation auth method is none'  "$(jq -c .revocation_endpoint_auth_methods_supported <<<"$ASM")" '["none"]'

read -r AT RT FAM <<<"$(grant)"
check 'fresh token works on MCP'        "$(mcp_code "$AT")" 200
check 'missing token is 400'            "$(revoke_tok -d "client_id=$CID")" 400
check 'unknown token is 200'            "$(revoke_tok -d token=nope -d "client_id=$CID")" 200
check "another client's revoke is 200"  "$(revoke_tok -d "token=$AT" -d client_id=cmc_other)" 200
check '... and the token still works'   "$(mcp_code "$AT")" 200
check 'revoking the access token is 200' "$(revoke_tok -d "token=$AT" -d "client_id=$CID" -d token_type_hint=access_token)" 200
check 'revoked access token is 401 on MCP' "$(mcp_code "$AT")" 401
check 'its refresh token is dead too'   "$(refresh "$RT")" '~invalid_grant'
check 'whole family deleted'            "$(family_rows "$FAM")" 0
check 'connection settings forgotten'   "$(wpe "echo isset(get_user_meta(1, 'cm_mcp_scopes', true)['oauth:$FAM']) ? 'kept' : 'gone';")" gone
check 'revoking it again is still 200'  "$(revoke_tok -d "token=$AT" -d "client_id=$CID")" 200

read -r AT RT FAM <<<"$(grant)"
check 'revoking the refresh token is 200' "$(revoke_tok -d "token=$RT" -d "client_id=$CID")" 200
check 'its access token is 401 on MCP'  "$(mcp_code "$AT")" 401
check 'response is no-store'            "$(curl -sk -D - -o /dev/null -d token=x -d "client_id=$CID" "$OA/revoke")" '~Cache-Control: no-store'

# Not tested here: the HTTPS refusal (wp_is_application_passwords_supported() is always true on a local site).

done_checks
