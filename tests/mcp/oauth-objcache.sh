#!/usr/bin/env bash
# Sign-in with an object cache that does not keep transients between requests (issue #26).
# Installs a per-request object-cache.php drop-in and removes it at the end. Run: bash tests/mcp/oauth-objcache.sh
. "$(dirname "$0")/lib.sh"
. "$(dirname "$0")/oauth-lib.sh"
DROPIN="$SITE_ROOT/wp-content/object-cache.php"
[[ ! -e $DROPIN ]] || { echo "an object-cache.php is already installed"; exit 1; }
cp "$(dirname "$0")/object-cache-fixture.php" "$DROPIN"
trap 'rm -f "$DROPIN" "${OAUTH_JARS[@]}"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT

check 'drop-in claims an external cache' "$(wpe 'echo wp_using_ext_object_cache() ? "ext" : "none";')" ext
T=$(connect "${CM_ADMIN_USER:-admin}" "${CM_ADMIN_PASS:-admin}" 'Objcache test' -d scope=read -d 'toolsets[]=content')
check 'code exchange works'              "$T" '~"token_type":"Bearer"'
check '... and the token reaches MCP'    "$(brpc "$(jq -r .access_token <<<"$T")" ping | jq -c .result)" '{}'
read -r CODE CID VERIFIER BACK <"$CODE_FILE"
check 'second redemption fails'          "$(curl -sk -d grant_type=authorization_code -d "code=$CODE" --data-urlencode "client_id=$CID" --data-urlencode "redirect_uri=$BACK" -d "code_verifier=$VERIFIER" "$OA/token")" '~invalid_grant'
check 'no code rows left'                "$(wpe "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE type = 'code'\");")" 0
[[ $(jq -r .token_type <<<"$T") != Bearer ]] || revoke_grant "$(jq -r .family <<<"$T")"

done_checks
