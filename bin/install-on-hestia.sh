#!/usr/bin/env bash
# Install or refresh Social Poster on the Hestia dev host.
# One-time migration: copy bundle from 192.168.11.50, then run this script.
set -euo pipefail

DOMAIN_ROOT="${DOMAIN_ROOT:-/home/StuckBendix/web/socialposter.stuckbendix.com}"
BASE_URL="${BASE_URL:-https://socialposter.stuckbendix.com}"
TARBALL="${1:-}"

usage() {
    cat <<EOF
Usage: bin/install-on-hestia.sh /path/to/social-poster-deploy-*.tar.gz

Environment:
  DOMAIN_ROOT  Default: $DOMAIN_ROOT
  BASE_URL     Default: $BASE_URL
EOF
}

if [[ -z "$TARBALL" || ! -f "$TARBALL" ]]; then
    usage >&2
    exit 1
fi

echo "==> Installing to $DOMAIN_ROOT"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
tar -xzf "$TARBALL" -C "$TMP"
SRC="$TMP/social-poster"

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

echo "==> Installing PHP dependencies"
cd "$DOMAIN_ROOT"
if command -v composer >/dev/null 2>&1; then
    composer install --no-interaction --optimize-autoloader
else
    echo "error: composer not found on server" >&2
    exit 1
fi

echo "==> Ensuring runtime directories"
mkdir -p var/data/post-images var/data/task-jobs var/cache var/logs var/discover
chmod 775 var/data var/logs var/cache var/data/post-images var/data/task-jobs 2>/dev/null || true

if [[ -f config/config.ini ]]; then
    echo "==> Updating base_url in config.ini"
    sed -i "s|^base_url = .*|base_url = \"${BASE_URL}\"|" config/config.ini
    sed -i 's|^openrouter_referer = .*|openrouter_referer = "https://socialposter.stuckbendix.com"|' config/config.ini
fi

echo "==> Running migrations"
php bin/migrate.php

echo "==> Setting permissions for Hestia user"
if [[ -x bin/fix-permissions-hestia.sh ]]; then
    bin/fix-permissions-hestia.sh
fi

echo
echo "Install complete."
echo "  Site: $BASE_URL"
echo "  App root: $DOMAIN_ROOT"
echo "  Web root: $DOMAIN_ROOT/public_html"
