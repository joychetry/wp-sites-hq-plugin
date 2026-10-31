#!/usr/bin/env bash
# Build an installable zip: production JS build, Composer autoloader only, no dev files.
#   bin/build-zip.sh          (or npm run plugin-zip)  =>  dist/wp-sites-hq.zip and its .sha256
#   bin/build-zip.sh --wporg  the same without the GitHub updater (wp.org forbids self-updaters)
# Files listed in .distignore stay out of the zip.
set -euo pipefail

wporg=0
[[ ${1:-} == --wporg ]] && wporg=1

cd "$(dirname "$0")/.."
slug=wp-sites-hq
out=dist/$slug

npm run build
rm -rf dist
mkdir -p "$out"
rsync -a --exclude-from=.distignore ./ "$out/"
# Plugin::register() hooks the updater only when the class exists.
((wporg)) && rm "$out/src/Updater.php"

# Zero runtime dependencies: this only writes vendor/autoload.php and vendor/composer/.
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet --working-dir="$out"
# composer.json stays: wp.org asks for it next to vendor/.
rm "$out/composer.lock"

(cd dist && zip -qr "$slug.zip" "$slug")
# The zip holds only what WordPress runs plus readme.txt; fail if a dev file got in.
dev=$(unzip -Z1 "dist/$slug.zip" | grep -E '(^|/)(\.[^/]+|node_modules|tests?|docs|bin|assets-src|[^/]*\.(md|zip|map|lock|dist|neon)|package(-lock)?\.json|webpack\.config\.js|blueprint\.json)(/|$)' || true)
[[ -z $dev ]] || { echo "Dev files in the zip:"; echo "$dev"; exit 1; }
(cd dist && sha256sum "$slug.zip" >"$slug.zip.sha256")
echo "Built dist/$slug.zip$( ((wporg)) && echo ' (wp.org, no updater)' )"
