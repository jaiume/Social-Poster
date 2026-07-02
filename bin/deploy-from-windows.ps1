# One-time migration: old dev LXC (192.168.11.50) -> Hestia dev host (StuckBendix).
# Prerequisites: SSH host "StuckBendix" in ~/.ssh/config.
#
# Usage:
#   .\bin\deploy-from-windows.ps1 -SourceHost root@192.168.11.50 -BundleRemote /tmp/social-poster-deploy-YYYYMMDD-HHMMSS.tar.gz
#
# Or if bundle is already local:
#   .\bin\deploy-from-windows.ps1 -LocalBundle C:\path\to\social-poster-deploy.tar.gz

param(
    [string]$SshHost = "StuckBendix",
    [string]$SourceHost = "root@192.168.11.50",
    [string]$BundleRemote = "",
    [string]$LocalBundle = "",
    [string]$DomainRoot = "/home/StuckBendix/web/socialposter.stuckbendix.com"
)

$ErrorActionPreference = "Stop"
$remoteTmp = "/tmp/social-poster-deploy.tar.gz"

if ($LocalBundle -eq "") {
    if ($BundleRemote -eq "") {
        Write-Host "Building bundle on source server..."
        ssh $SourceHost "bash /var/www/social-poster/bin/export-deploy-bundle.sh"
        $BundleRemote = ssh $SourceHost "ls -t /tmp/social-poster-deploy-*.tar.gz | head -1"
        $BundleRemote = $BundleRemote.Trim()
    }
    Write-Host "Downloading bundle from $SourceHost : $BundleRemote"
    $LocalBundle = Join-Path $env:TEMP "social-poster-deploy.tar.gz"
    scp "${SourceHost}:${BundleRemote}" $LocalBundle
}

Write-Host "Uploading bundle to $SshHost"
scp $LocalBundle "${SshHost}:${remoteTmp}"

Write-Host "Installing on Hestia server"
ssh $SshHost @"
set -e
cd '$DomainRoot'
if [ ! -f bin/install-on-hestia.sh ]; then
  mkdir -p '$DomainRoot'
  tar -xzf '$remoteTmp' -C /tmp
  rsync -a /tmp/social-poster/ '$DomainRoot/'
fi
bash '$DomainRoot/bin/install-on-hestia.sh' '$remoteTmp'
"@

Write-Host "Done. Dev site: https://socialposter.stuckbendix.com"
