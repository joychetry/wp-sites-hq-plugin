#!/usr/bin/env bash
# Build an installable zip: production JS build, Composer autoloader only, no dev files.
#   bin/build-zip.sh   (or npm run plugin-zip)  =>  dist/wp-plugin-starter.zip
# Files listed in .distignore stay out of the zip.
set -euo pipefail

cd "$(dirname "$0")/.."
slug=wp-plugin-starter
out=dist/$slug

npm run build
rm -rf dist
mkdir -p "$out"
rsync -a --exclude-from=.distignore ./ "$out/"

# Zero runtime dependencies: this only writes vendor/autoload.php and vendor/composer/.
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress --quiet --working-dir="$out"
rm "$out/composer.lock"

(cd dist && zip -qr "$slug.zip" "$slug")
echo "Built dist/$slug.zip"
