#!/usr/bin/env bash
# First-time and repeat setup: PHP deps, config, migrations.
set -euo pipefail

BASE="$(cd "$(dirname "$0")/.." && pwd)"
cd "$BASE"

RUN_APACHE_PERMS=0

usage() {
    cat <<'EOF'
Usage: bin/setup.sh [options]

Options:
  --apache       Fix var/ ownership for Apache (www-data). Requires root/sudo.
  -h, --help     Show this help.

Run from the project root after cloning. Safe to re-run after pulls.
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --apache) RUN_APACHE_PERMS=1 ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
    shift
done

info() { echo "==> $*"; }
warn() { echo "warning: $*" >&2; }

require_cmd() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "error: required command not found: $1" >&2
        exit 1
    fi
}

require_php_version() {
    local version major minor
    version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
    major="${version%%.*}"
    minor="${version#*.}"
    if (( major < 8 || (major == 8 && minor < 3) )); then
        echo "error: PHP 8.3+ required (found ${version})" >&2
        exit 1
    fi
}

info "Checking prerequisites"
require_cmd php
require_cmd composer
require_php_version

info "Installing PHP dependencies"
if [[ "$(id -u)" -eq 0 ]]; then
    export COMPOSER_ALLOW_SUPERUSER=1
fi
composer install --no-interaction

info "Ensuring runtime directories exist"
mkdir -p \
    var/cache \
    var/logs \
    var/data/post-images \
    var/data/task-jobs \
    var/discover

CONFIG="$BASE/config/config.ini"
EXAMPLE="$BASE/config/config.ini.example"

if [[ ! -f "$CONFIG" ]]; then
    info "Creating config/config.ini from example"
    cp "$EXAMPLE" "$CONFIG"
else
    info "Using existing config/config.ini"
fi

info "Ensuring security.encryption_key is set"
CONFIG_PATH="$CONFIG" php -r '
$configPath = getenv("CONFIG_PATH");
$lines = file($configPath, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Cannot read config\n");
    exit(1);
}
$inSecurity = false;
$keyIndex = null;
$keyValue = null;
foreach ($lines as $index => $line) {
    if (preg_match("/^\[(.+)\]$/", $line, $m)) {
        $inSecurity = $m[1] === "security";
        continue;
    }
    if (!$inSecurity) {
        continue;
    }
    if (preg_match("/^encryption_key\s*=\s*\"(.*)\"\s*$/", $line, $m)) {
        $keyIndex = $index;
        $keyValue = $m[1];
        break;
    }
}
if ($keyIndex === null) {
    fwrite(STDERR, "encryption_key not found in [security]\n");
    exit(1);
}
if ($keyValue !== "") {
    echo "encryption_key already set\n";
    exit(0);
}
$lines[$keyIndex] = "encryption_key = \"" . bin2hex(random_bytes(32)) . "\"";
file_put_contents($configPath, implode(PHP_EOL, $lines) . PHP_EOL);
echo "Generated security.encryption_key\n";
'

info "Applying database migrations"
php bin/migrate.php

if (( RUN_APACHE_PERMS == 1 )); then
    info "Updating Apache/www-data permissions"
    if [[ "$(id -u)" -eq 0 ]]; then
        "$BASE/bin/fix-permissions.sh"
    elif command -v sudo >/dev/null 2>&1; then
        sudo "$BASE/bin/fix-permissions.sh"
    else
        warn "Cannot run fix-permissions.sh without root or sudo"
    fi
fi

echo
echo "Setup complete."
echo
echo "Next steps:"
echo "  1. Edit config/config.ini:"
echo "     - auth.admin_password"
echo "     - openrouter settings (via /settings after login)"
if grep -q '^admin_password = ""' "$CONFIG" 2>/dev/null; then
    echo "     (admin_password is still empty)"
fi
echo "  2. On Apache hosts, run: sudo bin/setup.sh --apache"
echo "  3. Log in, configure OpenRouter in Settings, create profiles in Posts"
echo "  4. Optional: composer test"
