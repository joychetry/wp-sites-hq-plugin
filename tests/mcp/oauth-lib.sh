#!/usr/bin/env bash
# OAuth helpers for suites that need a real grant (consent form included). Source after lib.sh.

OA="$SITE/wp-json/classic-monks/v1/oauth"
b64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }
urlenc() { jq -rn --arg v "$1" '$v|@uri'; }
OAUTH_JARS=()
CONSENT_FILE=$(mktemp); OAUTH_JARS+=("$CONSENT_FILE")

# connect LOGIN PASSWORD CLIENT_NAME [consent form args...]  (OAUTH_REDIRECT: the return address; loopback by default)
# Signs in, registers a client, loads the consent page (saved to $CONSENT_FILE), approves it with the form args and
# exchanges the code. Prints the token response JSON with "family" added.
connect() {
    local login=$1 pass=$2 name=$3; shift 3
    local back=${OAUTH_REDIRECT:-http://127.0.0.1/callback}
    local jar verifier challenge cid page nonce loc cb code
    jar=$(mktemp); OAUTH_JARS+=("$jar")
    verifier=$(openssl rand -hex 40)
    challenge=$(printf %s "$verifier" | openssl dgst -sha256 -binary | b64url)
    cid=$(curl -sk -H 'Content-Type: application/json' -d "{\"client_name\":\"$name\",\"redirect_uris\":[\"$back\"]}" "$OA/register" | jq -r .client_id)
    curl -sk -c "$jar" -b 'wordpress_test_cookie=WP%20Cookie%20check' -o /dev/null \
        --data-urlencode "log=$login" --data-urlencode "pwd=$pass" -d 'testcookie=1' "$SITE/wp-login.php"
    loc=$(curl -sk -o /dev/null -w '%{redirect_url}' "$OA/authorize?response_type=code&client_id=$(urlenc "$cid")&redirect_uri=$(urlenc "$back")&state=s&code_challenge=$challenge&code_challenge_method=S256&resource=$(urlenc "$MCP")")
    curl -sk -b "$jar" "$loc" >"$CONSENT_FILE"
    nonce=$(grep -o 'name="_wpnonce" value="[^"]*"' "$CONSENT_FILE" | cut -d'"' -f4)
    cb=$(curl -sk -b "$jar" -o /dev/null -w '%{redirect_url}' --data-urlencode action=cm_mcp_oauth_decide \
        --data-urlencode "client_id=$cid" --data-urlencode "redirect_uri=$back" --data-urlencode state=s \
        --data-urlencode "code_challenge=$challenge" --data-urlencode code_challenge_method=S256 --data-urlencode "resource=$MCP" \
        --data-urlencode response_type=code --data-urlencode "_wpnonce=$nonce" --data-urlencode decision=approve "$@" "$SITE/wp-admin/admin-post.php")
    code=$(sed -E 's/.*code=([a-f0-9]+).*/\1/' <<<"$cb")
    curl -sk -d grant_type=authorization_code -d "code=$code" --data-urlencode "client_id=$cid" --data-urlencode "redirect_uri=$back" -d "code_verifier=$verifier" "$OA/token" \
        | jq -c --arg f "$(wpe "global \$wpdb; echo \$wpdb->get_var(\"SELECT family FROM {\$wpdb->prefix}cm_mcp_oauth_tokens ORDER BY id DESC LIMIT 1\");")" '. + {family: $f}'
}
# bcall TOKEN TOOL [ARGS_JSON] -> tools/call response over a Bearer token
bcall() { local args=${3:-'{}'}; brpc "$1" tools/call "{\"name\":\"$2\",\"arguments\":$args}"; }
brpc() { local params=${3:-'{}'}; curl -sk -H "Authorization: Bearer $1" -H 'Content-Type: application/json' -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"$2\",\"params\":$params}" "$MCP"; }
revoke_grant() { wpe "CM_MCP_OAuth::revoke_family('$1');" >/dev/null; }
