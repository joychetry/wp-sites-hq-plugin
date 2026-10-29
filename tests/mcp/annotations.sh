#!/usr/bin/env bash
# R-16: fails when a classic-monks/* ability's readonly/destructive/idempotent annotation changes, or a new ability is not in
# annotations.snapshot. UPDATE=1 rewrites the snapshot. Run: bash tests/mcp/annotations.sh
# Abilities not registered on this site (Bricks, Elementor or WooCommerce off) keep their snapshot line, so any site can check or update.
. "$(dirname "$0")/lib.sh"
SNAP="$(dirname "$0")/annotations.snapshot"

LIVE=$(wpe 'foreach ( wp_get_abilities() as $a ) {
    if ( 0 !== strpos( $a->get_name(), "classic-monks/" ) ) { continue; }
    $x = $a->get_meta()["annotations"] ?? array();
    echo $a->get_name();
    foreach ( array( "readonly", "destructive", "idempotent" ) as $k ) { echo " ", var_export( $x[ $k ] ?? null, true ); }
    echo "\n";
}' | grep '^classic-monks/')
[[ -n $LIVE ]] || { echo "FAIL no classic-monks abilities registered (AI/MCP off?)"; exit 1; }

KEPT=$(awk 'NR == FNR { live[$1]; next } !($1 in live)' <(echo "$LIVE") "$SNAP" 2>/dev/null)
NEW=$(printf '%s\n%s\n' "$LIVE" "$KEPT" | grep . | LC_ALL=C sort)

if [[ -n ${UPDATE:-} ]]; then
    echo "$NEW" >"$SNAP"
    echo "wrote $SNAP ($(wc -l <"$SNAP" | tr -d ' ') abilities)"
    exit 0
fi

D=$(diff -u "$SNAP" <(echo "$NEW"))
[[ -n $D ]] && echo "$D"
[[ -n $KEPT ]] && echo "not registered here, checked elsewhere: $(awk '{ print $1 }' <<<"$KEPT" | tr '\n' ' ')"
check "annotations match annotations.snapshot ($(wc -l <<<"$LIVE" | tr -d ' ') live; UPDATE=1 to accept)" "${D:+differs (diff above)}" ''
done_checks
