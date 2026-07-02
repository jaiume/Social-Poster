#!/usr/bin/env bash
# Unpack a takeover zip/tar on the Hestia dev host into the domain folder.
set -euo pipefail

DOMAIN_ROOT="${DOMAIN_ROOT:-/home/StuckBendix/web/socialposter.stuckbendix.com}"
BASE_URL="${BASE_URL:-https://socialposter.stuckbendix.com}"
ARCHIVE="${1:-}"

usage() {
    cat <<EOF
Usage: bin/unpack-takeover.sh /path/to/social-poster-takeover-*.zip

Extracts the app into $DOMAIN_ROOT and wires public_html.
EOF
}

if [[ -z "$ARCHIVE" || ! -f "$ARCHIVE" ]]; then
    usage >&2
    exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

case "$ARCHIVE" in
    *.zip)
        unzip -q "$ARCHIVE" -d "$TMP"
        ;;
    *.tar.gz|*.tgz)
        tar -xzf "$ARCHIVE" -C "$TMP"
        ;;
    *)
        echo "error: unsupported archive type" >&2
        exit 1
        ;;
esac

if [[ -d "$TMP/social-poster" ]]; then
    SRC="$TMP/social-poster"
elif [[ -f "$TMP/bin/setup.sh" ]]; then
    SRC="$TMP"
else
    SRC="$(find "$TMP" -mindepth 1 -maxdepth 2 -name setup.sh -path '*/bin/setup.sh' -printf '%h/%h\n' 2>/dev/null | head -1)"
    SRC="$(dirname "$(dirname "$SRC")")"
    [[ -f "$SRC/bin/setup.sh" ]] || { echo "error: could not find app root in archive" >&2; exit 1; }
fi

mkdir -p "$DOMAIN_ROOT/public_html"

if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete --exclude 'public_html/' "$SRC/" "$DOMAIN_ROOT/"
    rsync -a --delete "$SRC/public/" "$DOMAIN_ROOT/public_html/"
else
    find "$DOMAIN_ROOT" -mindepth 1 -maxdepth 1 ! -name 'public_html' -exec rm -rf {} +
    shopt -s dotglob
    for item in "$SRC"/*; do
        name="$(basename "$item")"
        [[ "$name" == 'public' ]] && continue
        cp -a "$item" "$DOMAIN_ROOT/"
    done
    rm -rf "$DOMAIN_ROOT/public_html"/*
    cp -a "$SRC/public/"* "$DOMAIN_ROOT/public_html/"
fi

cd "$DOMAIN_ROOT"

if [[ -f config/config.ini ]]; then
    sed -i "s|^base_url = .*|base_url = \"${BASE_URL}\"|" config/config.ini
    sed -i 's|^openrouter_referer = .*|openrouter_referer = "https://socialposter.stuckbendix.com"|' config/config.ini
fi

mkdir -p var/cache var/logs var/discover var/data/post-images var/data/task-jobs
php bin/migrate.php 2>/dev/null || true
bin/fix-permissions-hestia.sh 2>/dev/null || true

echo
echo "Takeover unpack complete."
echo "  Site: $BASE_URL"
echo "  App:  $DOMAIN_ROOT"
echo "  Web:  $DOMAIN_ROOT/public_html"
