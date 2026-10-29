#!/usr/bin/env bash
# Rename the starter for a new plugin. Run once, right after cloning.
#   bin/rename.sh <new-slug> "<New Name>" [prefix]
# Rewrites slug, text domain, plugin name, PHP namespace, the WPS_ constants, the
# wps_/wps-/wps/ prefix and the main plugin file name in code files. docs/ and
# CHANGELOG.md are left alone because they describe the starter and are shared with it.
set -euo pipefail

slug=${1:-}
name=${2:-}
if [[ ! $slug =~ ^[a-z][a-z0-9]*(-[a-z0-9]+)*$ ]] || [ -z "$name" ]; then
	echo 'Usage: bin/rename.sh <new-slug> "<New Name>" [prefix]' >&2
	echo 'Slug: lowercase letters, digits and single hyphens, e.g. acme-tools' >&2
	exit 1
fi

# Default prefix: initials of the slug parts (acme-shop-tools => ast). WPCS needs at
# least 3 characters, so shorter initials fall back to the slug without hyphens (acmetools).
initials=$(echo "$slug" | perl -pe 's/(?:^|-)([a-z])[a-z0-9]*/$1/g')
[ ${#initials} -ge 3 ] || initials=${slug//-/}
prefix=${3:-$initials}
if [[ ! $prefix =~ ^[a-z][a-z0-9]{2,}$ ]]; then
	echo "Prefix must be 3+ lowercase letters and digits: $prefix" >&2
	exit 1
fi

cd "$(dirname "$0")/.."

export SLUG=$slug NAME=$name PREFIX=$prefix
export UNDER=${slug//-/_}
export UPPER=${prefix^^}
PASCAL=$(echo "$slug" | perl -pe 's/(?:^|-)([a-z0-9])/\U$1/g')
export PASCAL

if git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
	files=$(git ls-files)
else
	files=$(find . -type f -not -path './.git/*' -not -path './node_modules/*' -not -path './vendor/*' -not -path './build/*' | sed 's|^\./||')
fi

count=0
while IFS= read -r file; do
	case $file in
		docs/* | CHANGELOG.md | bin/rename.sh | *.svg | *.png) continue ;;
	esac
	[ -f "$file" ] || continue
	# Lockfiles only get the package name: their hashes could contain "wps" by chance.
	case $file in
		package-lock.json | composer.lock) export LOCKFILE=1 ;;
		*) export LOCKFILE= ;;
	esac
	perl -pi -e '
		s/wp-plugin-starter/$ENV{SLUG}/g;
		next if $ENV{LOCKFILE};
		s/WpPluginStarter/$ENV{PASCAL}/g;
		s/wp_plugin_starter/$ENV{UNDER}/g;
		s/WP Plugin Starter/$ENV{NAME}/g;
		s/\bWPS_(?=[A-Z])/$ENV{UPPER}_/g;
		s/\bwps(?=[_\/-])/$ENV{PREFIX}/g;
		s/\bwps(?=[A-Z][a-z])/$ENV{PREFIX}/g;
	' "$file"
	count=$((count + 1))
done <<< "$files"

# The main file is named after the slug, like every WordPress plugin.
if [ -f wp-plugin-starter.php ] && [ "$slug" != wp-plugin-starter ]; then
	if git rev-parse --is-inside-work-tree > /dev/null 2>&1; then
		git mv wp-plugin-starter.php "$slug.php"
	else
		mv wp-plugin-starter.php "$slug.php"
	fi
fi

# composer.lock hashes the package name, so refresh the hash (no packages change).
if command -v composer > /dev/null; then
	composer update --lock --no-install --no-interaction --quiet
fi

# Names changed length, so Prettier may rewrap some JS lines.
if [ -d node_modules ]; then
	npm run --silent format > /dev/null
	format_note=''
else
	format_note='Run `npm install && npm run format` next: Prettier rewraps lines whose names changed length.'
fi

echo "Renamed to $NAME ($SLUG), namespace $PASCAL, prefix ${PREFIX}_ / ${PREFIX}- / ${UPPER}_, in $count files."
[ -z "$format_note" ] || echo "$format_note"
echo "Review with: git diff --stat"
