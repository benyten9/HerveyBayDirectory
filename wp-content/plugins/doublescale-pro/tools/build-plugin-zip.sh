#!/usr/bin/env bash
# Build a WordPress-ready zip of DoubleScale-Pro / DoubleScale Pro.
# Run from anywhere. Defaults: plugin dir = parent of tools/, zip written into that folder.
# Requires Composer on PATH (runs composer install so dependencies/vendor is complete; vendor dirs are gitignored).
#
# Usage:
#   ./tools/build-plugin-zip.sh [PLUGIN_DIR] [OUTPUT_DIR]
#
# Example:
#   cd /path/to/DoubleScale-Pro && npm run build && ./tools/build-plugin-zip.sh
#   ./tools/build-plugin-zip.sh . ~/Desktop

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${1:-"$SCRIPT_DIR/.."}" && pwd)"
OUTPUT_DIR="$(cd "${2:-"$PLUGIN_DIR"}" && pwd)"

MAIN_PHP="$PLUGIN_DIR/doublescale-pro.php"
if [[ ! -f "$MAIN_PHP" ]]; then
	echo "error: not a plugin root (missing doublescale-pro.php): $PLUGIN_DIR" >&2
	exit 1
fi

VERSION="$(
	grep -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN_PHP" | head -1 |
		sed -E 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//;s/[[:space:]]*$//'
)"
SLUG="$(basename "$PLUGIN_DIR")"
ZIP_BASENAME="${SLUG}-${VERSION}.zip"
OUTPUT_ZIP="$OUTPUT_DIR/$ZIP_BASENAME"

STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"' EXIT

DEST="$STAGING/$SLUG"
mkdir -p "$DEST"

# dependencies/vendor is gitignored (.gitignore: "vendor"); incomplete installs produce zips that fatal on load.
if [[ -f "$PLUGIN_DIR/dependencies/composer.json" ]]; then
	if ! command -v composer >/dev/null 2>&1; then
		echo "error: composer not in PATH. Install deps: (cd dependencies && composer install --no-dev -o)" >&2
		echo "error: https://getcomposer.org" >&2
		exit 1
	fi
	(
		cd "$PLUGIN_DIR/dependencies"
		composer install --no-dev --optimize-autoloader --no-interaction
	)
fi

# Root vendor/ is gitignored too; --no-scripts skips post-install (dependencies already installed above).
if [[ -f "$PLUGIN_DIR/composer.json" ]] && command -v composer >/dev/null 2>&1; then
	(
		cd "$PLUGIN_DIR"
		composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
	)
fi

rsync -a \
	--exclude='.git/' \
	--exclude='.gitignore' \
	--exclude='node_modules/' \
	--exclude='src/' \
	--exclude='tools/' \
	--exclude='tests/' \
	--exclude='.DS_Store' \
	--exclude='.cursor/' \
	--exclude='.cursorignore' \
	--exclude='.vscode/' \
	--exclude='*.log' \
	--exclude='package.json' \
	--exclude='package-lock.json' \
	--exclude='yarn.lock' \
	--exclude='webpack.config.js' \
	--exclude='tsconfig.json' \
	--exclude='tsconfig.*.json' \
	--exclude='tailwind.config.js' \
	--exclude='postcss.config.js' \
	--exclude='.cache/' \
	--exclude='*.tsbuildinfo' \
	--exclude='phpcs.xml' \
	--exclude='phpunit.xml' \
	--exclude='phpunit.xml.dist' \
	--exclude='.phpunit.result.cache' \
	--exclude='phpunit/' \
	"$PLUGIN_DIR/" "$DEST/"

if [[ ! -f "$DEST/build/client/index.asset.php" ]]; then
	echo "warning: build/client/index.asset.php missing — run npm run build before zipping for a working admin UI." >&2
fi

(
	cd "$STAGING"
	zip -r -q "$OUTPUT_ZIP" "$SLUG"
)

echo "$OUTPUT_ZIP"
