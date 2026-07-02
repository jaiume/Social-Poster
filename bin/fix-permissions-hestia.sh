#!/usr/bin/env bash
# Hestia: PHP-FPM runs as the domain user (e.g. StuckBendix).
set -euo pipefail

BASE="$(cd "$(dirname "$0")/.." && pwd)"
OWNER="${HESTIA_USER:-$(stat -c '%U' "$BASE")}"

mkdir -p "$BASE/var/data/post-images" "$BASE/var/data/task-jobs" "$BASE/var/cache" "$BASE/var/logs"

if [[ "$(id -u)" -eq 0 ]]; then
    chown -R "$OWNER:$OWNER" "$BASE/var" "$BASE/config/config.ini" 2>/dev/null || true
fi

chmod 775 "$BASE/var/data" "$BASE/var/logs" "$BASE/var/cache" \
    "$BASE/var/data/post-images" "$BASE/var/data/task-jobs" 2>/dev/null || true

if [[ -f "$BASE/var/data/social_poster.sqlite" ]]; then
    chmod 664 "$BASE/var/data/social_poster.sqlite"
fi

echo "Permissions updated for $OWNER."
