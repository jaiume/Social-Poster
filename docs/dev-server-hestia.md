# Dev server on Hestia

Social Poster runs on a **private dev host**, not production.

| | |
|---|---|
| **URL** | https://socialposter.stuckbendix.com |
| **Server** | Hestia CP, `192.168.11.182` |
| **SSH user** | `StuckBendix` (host alias `StuckBendix` in `~/.ssh/config`) |
| **App root** | `/home/StuckBendix/web/socialposter.stuckbendix.com` |
| **Web root** | `.../public_html` (contents of `public/`) |

The old LXC at `192.168.11.50` is retired after migration.

## One-time migration (from 192.168.11.50)

On your Windows PC (with SSH keys for both hosts):

```powershell
cd C:\path\to\social-poster
.\bin\deploy-from-windows.ps1 -SourceHost root@192.168.11.50
```

Or manually:

```powershell
# On old server — build bundle (code + config + SQLite + images)
ssh root@192.168.11.50 "bash /var/www/social-poster/bin/export-deploy-bundle.sh"

# Download latest bundle
scp root@192.168.11.50:/tmp/social-poster-deploy-*.tar.gz $env:TEMP\

# Upload and install on Hestia dev host
scp $env:TEMP\social-poster-deploy-*.tar.gz StuckBendix:/tmp/social-poster-deploy.tar.gz
ssh StuckBendix "bash /home/StuckBendix/web/socialposter.stuckbendix.com/bin/install-on-hestia.sh /tmp/social-poster-deploy.tar.gz"
```

If this is the **first** install and the app is not on the server yet, extract once then run install:

```bash
ssh StuckBendix
mkdir -p /home/StuckBendix/web/socialposter.stuckbendix.com
tar -xzf /tmp/social-poster-deploy.tar.gz -C /tmp
cp -a /tmp/social-poster/. /home/StuckBendix/web/socialposter.stuckbendix.com/
bash /home/StuckBendix/web/socialposter.stuckbendix.com/bin/install-on-hestia.sh /tmp/social-poster-deploy.tar.gz
```

`install-on-hestia.sh` copies `public/` → `public_html/`, runs `composer install`, applies migrations, and sets `base_url` to `https://socialposter.stuckbendix.com`.

## Day-to-day on the dev server

```bash
ssh StuckBendix
cd /home/StuckBendix/web/socialposter.stuckbendix.com
bin/setup.sh                    # after pulling code changes
bin/fix-permissions-hestia.sh   # if SQLite or uploads fail with permission errors
composer test                   # optional
```

## Open in Cursor / VS Code

Open [`social-poster.code-workspace`](social-poster.code-workspace) from your machine. It includes:

1. **Local folder** — your clone on disk  
2. **Hestia dev** (`192.168.11.182`) — remote folder over SSH host `StuckBendix`

Requires `StuckBendix` in `~/.ssh/config` (see below) and the [Remote - SSH](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-ssh) extension.

## SSH config (Windows)

```sshconfig
Host StuckBendix
    HostName 192.168.11.182
    User StuckBendix
    IdentityFile ~/.ssh/stuckbendix
    IdentitiesOnly yes
```

## What moves in the bundle

- Application source, templates, migrations
- `config/config.ini` (admin auth, encryption key, OpenRouter)
- `var/data/` (SQLite database, generated images, task artifacts)

Not included: `vendor/` (rebuilt on target), `automation/` (removed), cache/logs.

## After migration

1. Log in at https://socialposter.stuckbendix.com (same credentials as before).
2. Confirm posts, images, and OpenRouter settings.
3. Decommission `192.168.11.50` when satisfied.
