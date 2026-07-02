#!/usr/bin/env bash
# Build a deploy tarball on the source server (code + var/data + config).
set -euo pipefail

BASE="$(cd "$(dirname "$0")/.." && pwd)"
cd "$BASE"
STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="/tmp/social-poster-deploy-${STAMP}.tar.gz"

echo "==> Building deploy bundle: $OUT"

tar -czf "$OUT" \
    --exclude='./automation' \
    --exclude='./vendor' \
    --exclude='./var/cache/*' \
    --exclude='./var/logs/*' \
    --exclude='./var/discover/*' \
    --exclude='./.phpunit.cache' \
    --exclude='./.git' \
    -C "$(dirname "$BASE")" \
    "$(basename "$BASE")"

echo "==> Done: $OUT ($(du -h "$OUT" | cut -f1))"
echo "Copy to Hestia host, then run bin/install-on-hestia.sh on the server."
