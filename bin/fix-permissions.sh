#!/usr/bin/env bash
# Apache runs as www-data; var/ must be writable for SQLite and logs.
set -euo pipefail
BASE="$(cd "$(dirname "$0")/.." && pwd)"
chown www-data:www-data "$BASE/var/data" "$BASE/var/logs" "$BASE/var/cache" 2>/dev/null || true
mkdir -p "$BASE/var/data/post-images" "$BASE/var/data/task-jobs"
chown www-data:www-data "$BASE/var/data/post-images" "$BASE/var/data/task-jobs" 2>/dev/null || true
[ -f "$BASE/var/data/social_poster.sqlite" ] && chown www-data:www-data "$BASE/var/data/social_poster.sqlite"
chmod 775 "$BASE/var/data" "$BASE/var/logs" "$BASE/var/cache"
[ -f "$BASE/var/data/social_poster.sqlite" ] && chmod 664 "$BASE/var/data/social_poster.sqlite"
echo "Permissions updated for www-data."
