#!/usr/bin/env bash
# Full takeover archive: code, vendor, config, database, images, deploy scripts.
# Run on the source server, then download the zip to your PC.
set -euo pipefail

BASE="$(cd "$(dirname "$0")/.." && pwd)"
cd "$BASE"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="/tmp/social-poster-takeover-${STAMP}.zip"

echo "==> Building takeover zip: $OUT"

if command -v zip >/dev/null 2>&1; then
    zip -r "$OUT" . \
        -x './automation/*' \
        -x './automation' \
        -x './var/cache/*' \
        -x './var/logs/*' \
        -x './var/discover/*' \
        -x './var/data/test.sqlite' \
        -x './.git/*' \
        -x './.git' \
        -x './.phpunit.cache/*' \
        -x './*.zip' \
        -x './tmp/*'
else
    OUT="/tmp/social-poster-takeover-${STAMP}.tar.gz"
    tar -czf "$OUT" \
        --exclude='./automation' \
        --exclude='./var/cache/*' \
        --exclude='./var/logs/*' \
        --exclude='./var/discover/*' \
        --exclude='./var/data/test.sqlite' \
        --exclude='./.git' \
        --exclude='./.phpunit.cache' \
        -C "$(dirname "$BASE")" \
        "$(basename "$BASE")"
fi

echo "==> Done: $OUT ($(du -h "$OUT" | cut -f1))"
echo
echo "Download to your PC:"
echo "  scp root@192.168.11.50:${OUT} ."
echo
echo "On Hestia dev host (after upload):"
echo "  unzip social-poster-takeover-*.zip -d /home/StuckBendix/web/socialposter.stuckbendix.com"
echo "  # or extract tarball into domain folder, then:"
echo "  bash bin/install-on-hestia.sh   # only if using deploy tarball flow"
echo "  # For this full zip, run setup instead:"
echo "  cd /home/StuckBendix/web/socialposter.stuckbendix.com/social-poster  # if nested"
echo "  bin/setup.sh && bin/fix-permissions-hestia.sh"
