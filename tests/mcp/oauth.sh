#!/usr/bin/env bash
# 5.4 OAuth 2.1 checks: discovery, DCR, consent, PKCE, Bearer confinement, refresh rotation, revoke.
# Signs in as admin/admin (override: CM_ADMIN_USER / CM_ADMIN_PASS) with a cookie jar to drive the consent form. Run: bash tests/mcp/oauth.sh
. "$(dirname "$0")/lib.sh"

OA="$SITE/wp-json/classic-monks/v1/oauth"
JAR=$(mktemp)
trap 'rm -f "$JAR"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"' EXIT

b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }
urlenc() { jq -rn --arg v "$1" '$v|@uri'; }
VERIFIER=$(openssl rand -hex 40)
CHALLENGE=$(printf %s "$VERIFIER" | openssl dgst -sha256 -binary | b64url)
REDIRECT=http://127.0.0.1/callback
RES_ENC=$(urlenc "$MCP")

# --- Discovery
PRM=$(curl -sk "$SITE/.well-known/oauth-protected-resource/wp-json/classic-monks/v1/mcp")
check 'PRM resource is the MCP URL'          "$(jq -r .resource <<<"$PRM")" "$MCP"
check 'PRM names this site as AS'            "$(jq -r '.authorization_servers[0]' <<<"$PRM")" "$SITE"
check 'root PRM works too'                   "$(curl -sk "$SITE/.well-known/oauth-protected-resource" | jq -r .resource)" "$MCP"
ASM=$(curl -sk "$SITE/.well-known/oauth-authorization-server")
check 'AS issuer'                            "$(jq -r .issuer <<<"$ASM")" "$SITE"
check 'AS advertises S256 only'              "$(jq -c .code_challenge_methods_supported <<<"$ASM")" '["S256"]'
check 'AS advertises CIMD + none'            "$(jq -c '[.client_id_metadata_document_supported, .token_endpoint_auth_methods_supported]' <<<"$ASM")" '[true,["none"]]'
check 'AS advertises iss'                    "$(jq -r .authorization_response_iss_parameter_supported <<<"$ASM")" true
H=$(curl -sk -D - -o /dev/null -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")
check 'unauthenticated MCP is 401'           "$H" '~HTTP/1.1 401'
check '401 carries resource_metadata'        "$H" "~resource_metadata=\"$SITE/.well-known/oauth-protected-resource/wp-json/classic-monks/v1/mcp\""

# --- DCR
REG=$(curl -sk -H 'Content-Type: application/json' -d '{"client_name":"cm oauth test","redirect_uris":["'$REDIRECT'"],"grant_types":["authorization_code","refresh_token"],"token_endpoint_auth_method":"none"}' "$OA/register")
CID=$(jq -r .client_id <<<"$REG")
check 'DCR issues a client_id'               "$CID" '~cmc_'
check 'DCR client is public'                 "$(jq -r .token_endpoint_auth_method <<<"$REG")" none
check 'DCR refuses plain-http redirect'      "$(curl -sk -H 'Content-Type: application/json' -d '{"redirect_uris":["http://evil.example/cb"]}' "$OA/register")" '~invalid_redirect_uri'
check 'DCR refuses javascript: redirect'     "$(curl -sk -H 'Content-Type: application/json' -d '{"redirect_uris":["javascript://x/%0aalert(1)"]}' "$OA/register")" '~invalid_redirect_uri'
check 'DCR refuses nested grant_types'       "$(curl -sk -H 'Content-Type: application/json' -d '{"redirect_uris":["'$REDIRECT'"],"grant_types":[["x"]]}' "$OA/register")" '~invalid_client_metadata'
CID_ENC=$(urlenc "$CID")
ADMIN_ID=$(wpe 'echo get_user_by("login", "'"${CM_ADMIN_USER:-admin}"'")->ID;')

# --- Authorize (cookie session)
curl -sk -c "$JAR" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null \
    --data-urlencode "log=${CM_ADMIN_USER:-admin}" --data-urlencode "pwd=${CM_ADMIN_PASS:-admin}" -d 'testcookie=1&rememberme=forever' "$SITE/wp-login.php"
AUTHQ="response_type=code&client_id=$CID_ENC&redirect_uri=$(urlenc http://127.0.0.1:53682/callback)&state=xyz123&code_challenge=$CHALLENGE&code_challenge_method=S256&resource=$RES_ENC"
LOC=$(curl -sk -o /dev/null -w '%{redirect_url}' "$OA/authorize?$AUTHQ")
check 'authorize forwards to the consent page' "$LOC" '~wp-admin/admin.php?page=cm-mcp-authorize'
PAGE=$(curl -sk -b "$JAR" "$LOC")
check 'consent page names the client'        "$PAGE" '~cm oauth test'
check 'consent page shows redirect host'     "$PAGE" '~<code>127.0.0.1</code>'
check 'consent warns about loopback'         "$PAGE" '~program on your own computer'
NONCE=$(grep -o 'name="_wpnonce" value="[^"]*"' <<<"$PAGE" | cut -d'"' -f4)

bad() { curl -sk -b "$JAR" "$SITE/wp-admin/admin.php?page=cm-mcp-authorize&$1"; }
check 'unregistered redirect is not followed' "$(bad "response_type=code&client_id=$CID_ENC&redirect_uri=$(urlenc https://evil.example/cb)&code_challenge=$CHALLENGE&code_challenge_method=S256")" '~did not register'
check 'forged client_id refused'             "$(bad "response_type=code&client_id=${CID_ENC}x&redirect_uri=$(urlenc $REDIRECT)&code_challenge=$CHALLENGE&code_challenge_method=S256")" '~Unknown client'
check 'array state is not echoed as Array'  "$(bad "response_type=code&client_id=$CID_ENC&redirect_uri=$(urlenc $REDIRECT)&state[]=s&code_challenge=$CHALLENGE&code_challenge_method=S256")" '!~value="Array"'
# Errors render on the page, never redirect: open DCR would make the server an open redirector.
U="$SITE/wp-admin/admin.php?page=cm-mcp-authorize&response_type=code&client_id=$CID_ENC&redirect_uri=$(urlenc $REDIRECT)&state=s1&code_challenge=$CHALLENGE&code_challenge_method=plain"
check 'plain PKCE refused without redirect'  "$(curl -sk -b "$JAR" -o /dev/null -w '%{redirect_url}' "$U")" ''
check 'plain PKCE error shown on page'       "$(curl -sk -b "$JAR" "$U")" '~code_challenge_method=S256'
U="$SITE/wp-admin/admin.php?page=cm-mcp-authorize&response_type=token&client_id=$CID_ENC&redirect_uri=$(urlenc $REDIRECT)&code_challenge=$CHALLENGE&code_challenge_method=S256"
check 'implicit grant refused without redirect' "$(curl -sk -b "$JAR" -o /dev/null -w '%{redirect_url}' "$U")" ''
check 'implicit grant error shown on page'   "$(curl -sk -b "$JAR" "$U")" '~response_type=code'
check 'consent page denies framing'          "$(curl -sk -b "$JAR" -D - -o /dev/null "$LOC" | tr '[:upper:]' '[:lower:]')" "~frame-ancestors 'none'"

decide() { # decision [extra form args...] -> redirect URL
    local d=$1; shift
    curl -sk -b "$JAR" -o /dev/null -w '%{redirect_url}' --data-urlencode action=cm_mcp_oauth_decide \
        --data-urlencode "client_id=$CID" --data-urlencode redirect_uri=http://127.0.0.1:53682/callback --data-urlencode state=xyz123 \
        --data-urlencode "code_challenge=$CHALLENGE" --data-urlencode code_challenge_method=S256 --data-urlencode "resource=$MCP" \
        --data-urlencode response_type=code --data-urlencode "_wpnonce=$NONCE" --data-urlencode "decision=$d" "$@" "$SITE/wp-admin/admin-post.php"
}
check 'deny returns access_denied'           "$(decide deny)" '~error=access_denied'
check 'nonce is bound to code_challenge'     "$(decide approve --data-urlencode "code_challenge=$(printf %s x | openssl dgst -sha256 -binary | b64url)")" '!~code='
check 'nonce is bound to state'              "$(decide approve --data-urlencode state=other)" '!~code='
check 'GET cannot grant'                     "$(curl -sk -b "$JAR" -o /dev/null -w '%{redirect_url}' "$SITE/wp-admin/admin-post.php?action=cm_mcp_oauth_decide&$AUTHQ&_wpnonce=$NONCE&decision=approve")" '!~code='
CB=$(decide approve -d scope=content -d 'toolsets[]=content' -d 'toolsets[]=site')
check 'approve redirects to loopback (any port)' "$CB" '~http://127.0.0.1:53682/callback?code='
check 'approve echoes state'                 "$CB" '~state=xyz123'
check 'approve carries iss'                  "$CB" "~iss=$(urlenc "$SITE")"
CODE=$(sed -E 's/.*code=([a-f0-9]+).*/\1/' <<<"$CB")

# --- Token exchange
tok() { curl -sk -w ' HTTP%{http_code}' "$@" "$OA/token"; }
check 'wrong verifier refused'               "$(tok -d grant_type=authorization_code -d "code=$CODE" --data-urlencode "client_id=$CID" -d redirect_uri=http://127.0.0.1:53682/callback -d code_verifier=$(openssl rand -hex 40))" '~invalid_grant'
check 'code is single use (even after a failure)' "$(tok -d grant_type=authorization_code -d "code=$CODE" --data-urlencode "client_id=$CID" -d redirect_uri=http://127.0.0.1:53682/callback -d "code_verifier=$VERIFIER")" '~invalid_grant'
# Array fields read as empty: a clean OAuth error, never a PHP warning or "Array".
T=$(tok -d grant_type=refresh_token -d 'refresh_token[]=x' -d 'client_id[]=y')
check 'array token fields are a clean error'  "$T" '~invalid_'
check 'array token fields print no warning'   "$T" '!~Array'
CODE=$(sed -E 's/.*code=([a-f0-9]+).*/\1/' <<<"$(decide approve -d scope=content -d 'toolsets[]=content' -d 'toolsets[]=site')")
T=$(tok -d grant_type=authorization_code -d "code=$CODE" --data-urlencode "client_id=$CID" -d redirect_uri=http://127.0.0.1:53682/callback -d "code_verifier=$VERIFIER" --data-urlencode "resource=$MCP")
check 'token exchange succeeds'              "$T" '~HTTP200'
check 'token response scope'                 "$T" '~"scope":"content"'
AT=$(jq -r .access_token <<<"${T% HTTP*}"); RT=$(jq -r .refresh_token <<<"${T% HTTP*}")

bearer() { local p=${3:-'{}'}; curl -sk -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$2\",\"params\":$p}" "$MCP"; }
L=$(bearer "$AT" tools/list)
check 'Bearer tools/list works'              "$L" '~"name":"get-posts"'
check 'token toolsets apply (no seo tools)'  "$L" '!~"name":"get-seo'
check 'content scope hides update-features'  "$L" '!~"name":"update-features"'
check 'X-CM-Authorization Bearer fallback'   "$(curl -sk -H "X-CM-Authorization: Bearer $AT" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$MCP")" '~"name":"get-posts"'
check 'array tool name is a clean error'    "$(bearer "$AT" tools/call '{"name":["get-posts"]}')" '~"error"'
check 'Bearer refused on /wp/v2/users/me'    "$(curl -sk -H "Authorization: Bearer $AT" "$SITE/wp-json/wp/v2/users/me")" '!~"slug"'
check 'Bearer refused via query smuggling'   "$(curl -sk -H "Authorization: Bearer $AT" "$SITE/wp-json/wp/v2/users/me?x=/classic-monks/v1/mcp")" '!~"slug"'
check 'Bearer does not open wp-admin'        "$(code -H "Authorization: Bearer $AT" "$SITE/wp-admin/?rest_route=/classic-monks/v1/mcp")" 302
# WP::parse_request lets a POST body rest_route override the MCP path.
check 'Bearer refused via POST rest_route'   "$(curl -sk -H "Authorization: Bearer $AT" --data-urlencode rest_route=/wp/v2/users/me "$MCP")" '!~"slug"'
check 'Bearer refused via encoded slash'     "$(code -H "Authorization: Bearer $AT" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$SITE/wp-json/classic-monks%2Fv1/mcp")" '!200'
check 'Bearer refused via ?rest_route on another path' "$(curl -sk -H "Authorization: Bearer $AT" "$SITE/wp-json/wp/v2/users/me?rest_route=/classic-monks/v1/mcp")" '!~"slug"'

# Write is logged with the OAuth connection id and runs (content scope).
PID=$(wpe 'echo wp_insert_post(["post_title" => "cm oauth test", "post_status" => "draft"]);')
FAM=$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT family FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE token_hash = '$(printf %s "$AT" | openssl dgst -sha256 -r | cut -d' ' -f1)'\");")
WRITE="{\"name\":\"update-post-metadata\",\"arguments\":{\"post_id\":$PID,\"key\":\"k\",\"value\":\"v\"}}"
check 'OAuth write goes through approvals'   "$(bearer "$AT" tools/call "$WRITE")" '~"cause":"approval"'
wpe "update_user_meta($ADMIN_ID, 'cm_mcp_trusted', ['oauth:$FAM' => true] + (array) get_user_meta($ADMIN_ID, 'cm_mcp_trusted', true));" >/dev/null
check 'trusted OAuth connection writes'      "$(bearer "$AT" tools/call "$WRITE")" '!~isError'
check 'write logged as mcp + oauth id'       "$(wpe "global \$wpdb; echo wp_json_encode(\$wpdb->get_row(\"SELECT source, connection_uuid, client_label FROM {\$wpdb->prefix}cm_ai_log ORDER BY id DESC LIMIT 1\"));")" "~\"connection_uuid\":\"oauth:$FAM\""
check 'trusted OAuth connection deletes'    "$(bearer "$AT" tools/call "{\"name\":\"delete-post\",\"arguments\":{\"post_id\":$PID}}")" '!~isError'
wpe "wp_delete_post($PID, true);" >/dev/null

# Connections list shows the grant.
LIST=$(wpe 'wp_set_current_user(1); $r = new ReflectionMethod("CM_MCP_Server", "connections"); $r->setAccessible(true); echo wp_json_encode($r->invoke(null));')
check 'Connections list has the OAuth row'   "$LIST" "~\"kind\":\"oauth\",\"user_id\":$ADMIN_ID,\"uuid\":\"oauth:$FAM\""

# --- Refresh rotation + reuse detection
R1=$(tok -d grant_type=refresh_token -d "refresh_token=$RT" --data-urlencode "client_id=$CID")
check 'refresh works'                        "$R1" '~HTTP200'
RT2=$(jq -r .refresh_token <<<"${R1% HTTP*}"); AT2=$(jq -r .access_token <<<"${R1% HTTP*}")
check 'refresh rotates the token'            "$RT2" "!$RT"
check 'refreshed token works'                "$(bearer "$AT2" tools/list)" '~"name":"get-posts"'
check 'refresh with another client refused'  "$(tok -d grant_type=refresh_token -d "refresh_token=$RT2" -d client_id=cmc_other)" '~invalid_grant'
check 'reusing the old refresh token fails'  "$(tok -d grant_type=refresh_token -d "refresh_token=$RT" --data-urlencode "client_id=$CID")" '~already used'
check 'reuse revoked the family (new RT dead)' "$(tok -d grant_type=refresh_token -d "refresh_token=$RT2" --data-urlencode "client_id=$CID")" '~invalid_grant'
H=$(curl -sk -D - -H "Authorization: Bearer $AT2" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$MCP")
check 'revoked access token is 401'          "$H" '~HTTP/1.1 401'
check '401 says invalid_token'               "$H" '~error="invalid_token"'
check '401 keeps diagnostics in body'        "$H" '~invalid or expired'

# --- Expiry
CODE=$(sed -E 's/.*code=([a-f0-9]+).*/\1/' <<<"$(decide approve -d scope=read)")
T=$(tok -d grant_type=authorization_code -d "code=$CODE" --data-urlencode "client_id=$CID" -d redirect_uri=http://127.0.0.1:53682/callback -d "code_verifier=$VERIFIER")
AT3=$(jq -r .access_token <<<"${T% HTTP*}")
check 'read scope hides write tools'         "$(bearer "$AT3" tools/list)" '!~"name":"update-post"'
FAM3=$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT family FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE token_hash = '$(printf %s "$AT3" | openssl dgst -sha256 -r | cut -d' ' -f1)'\");")
wpe "global \$wpdb; \$wpdb->query(\"UPDATE {\$wpdb->prefix}cm_mcp_oauth_tokens SET expires_at = 1 WHERE type = 'access' AND family = '$FAM3'\");" >/dev/null
H=$(curl -sk -D - -H "Authorization: Bearer $AT3" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$MCP")
check 'expired token is 401 + WWW-Authenticate' "$(tr '[:upper:]' '[:lower:]' <<<"$H")" '~www-authenticate: bearer resource_metadata='

# --- Revoke on Settings > Monks Central (its REST route, as admin)
check 'revoke from the Monks Central screen' "$(mc_rest "$JAR" DELETE /connections "{\"user_id\":1,\"id\":\"oauth:$FAM3\"}")" '~200 {"state"'
check 'revoked grant is gone'                "$(wpe "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE family = '$FAM3'\");")" 0
check 'revoked grant settings forgotten'     "$(wpe "echo isset(get_user_meta(1, 'cm_mcp_scopes', true)['oauth:$FAM3']) ? 'kept' : 'gone';")" gone

# --- Rejected tokens sign their user out (audience, demotion), password changes revoke
TU=$(wpe 'echo wp_insert_user(["user_login" => "cm-oauth-t-" . wp_generate_password(6, false, false), "user_pass" => "Cm-oauth-test-1", "role" => "editor"]);')
trap 'rm -f "$JAR"; revoke "$MCP_UUID"; revoke "$PLAIN_UUID"; wpe "require_once ABSPATH . \"wp-admin/includes/user.php\"; wp_delete_user($TU);" >/dev/null' EXIT
mintfor() { # user_id -> access token bound to the MCP URL (CLI may see another host)
    wpe "\$m = new ReflectionMethod('CM_MCP_OAuth', 'issue'); \$m->setAccessible(true); \$f = bin2hex(random_bytes(16)); CM_MCP_Server::save_connection_settings($1, 'oauth:' . \$f, 'content', ['content']); \$t = \$m->invoke(null, \$f, $1, hash('sha256', 't'), 'probe', 'content')->get_data()['access_token']; global \$wpdb; \$wpdb->update(\$wpdb->prefix . 'cm_mcp_oauth_tokens', ['resource' => '$MCP'], ['family' => \$f]); echo \$t;"
}
tcount() { wpe "global \$wpdb; echo (int) \$wpdb->get_var(\"SELECT COUNT(*) FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE user_id = $TU\");"; }
AT4=$(mintfor "$TU")
check 'editor token works'                   "$(bearer "$AT4" tools/list)" '~"name":"get-posts"'
# Admin token: this site's Disable REST API setting would hide a leak for an editor.
AT5=$(mintfor "$ADMIN_ID"); H5=$(printf %s "$AT5" | openssl dgst -sha256 -r | cut -d' ' -f1)
wpe "global \$wpdb; \$wpdb->query(\"UPDATE {\$wpdb->prefix}cm_mcp_oauth_tokens SET resource = 'https://other.example/mcp' WHERE token_hash = '$H5'\");" >/dev/null
check 'other-audience token is 401'          "$(curl -sk -D - -o /dev/null -H "Authorization: Bearer $AT5" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"ping"}' "$MCP")" '~error="invalid_token"'
check 'other-audience token signs nobody in' "$(curl -sk -H "Authorization: Bearer $AT5" --data-urlencode rest_route=/wp/v2/users/me "$MCP")" '!~"slug"'
check 'rejected token cannot read settings' "$(curl -sk -H "Authorization: Bearer $AT5" -H 'Content-Type: application/x-www-form-urlencoded' --data rest_route=/wp/v2/settings "$MCP")" '!~"timezone"'
wpe "global \$wpdb; CM_MCP_OAuth::revoke_family(\$wpdb->get_var(\"SELECT family FROM {\$wpdb->prefix}cm_mcp_oauth_tokens WHERE token_hash = '$H5'\"));" >/dev/null
wpe "(new WP_User($TU))->set_role('subscriber');" >/dev/null
check 'demoted user token is 401'            "$(curl -sk -D - -o /dev/null -H "Authorization: Bearer $AT4" -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$MCP")" '~error="invalid_token"'
# A subscriber's consent page must not fetch the client's metadata URL (SSRF surface).
CIMD="https://example.com/cm-oauth-probe-$RANDOM"
check 'subscriber cannot consent'            "$(wpe "wp_set_current_user($TU); \$_GET = ['response_type' => 'code', 'client_id' => '$CIMD', 'redirect_uri' => '$REDIRECT', 'code_challenge' => '$CHALLENGE', 'code_challenge_method' => 'S256']; CM_MCP_OAuth::render_consent();")" '~cannot connect AI apps'
check 'subscriber triggers no CIMD fetch'    "$(wpe "echo false === get_transient('cm_mcp_oauth_cimd_' . hash('sha256', '$CIMD')) ? 'none' : 'fetched';")" none
wpe "(new WP_User($TU))->set_role('editor');" >/dev/null
mintfor "$TU" >/dev/null
wpe "wp_update_user(['ID' => $TU, 'user_pass' => 'Cm-oauth-test-2']);" >/dev/null
check 'password change revokes tokens'       "$(tcount)" 0
mintfor "$TU" >/dev/null
wpe "reset_password(get_userdata($TU), 'Cm-oauth-test-3');" >/dev/null
check 'password reset revokes tokens'        "$(tcount)" 0
mintfor "$TU" >/dev/null
wpe "wp_update_user(['ID' => $TU, 'display_name' => 'renamed']);" >/dev/null
check 'profile edit keeps tokens'            "$(tcount)" 2

done_checks
