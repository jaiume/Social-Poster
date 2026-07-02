# Social Poster

AI-assisted social post drafting. Product profiles define sources and guidance; OpenRouter generates post copy and optional images. Posts are published manually by copying text and image from the workspace.

## Stack

- PHP 8.3+ / Slim 4 / PHP-DI
- SQLite
- Twig + Bootstrap 5.3
- OpenRouter (content generation with `fetch_page` tool)
- Config-based single admin auth

See [docs/project-decisions.md](docs/project-decisions.md) for setup choices.

## Quick start

See [docs/dev-server-hestia.md](docs/dev-server-hestia.md) for SSH, paths, migration, and the multi-root workspace (`social-poster.code-workspace`).

**Local / fresh clone:**

```bash
cd social-poster
chmod +x bin/setup.sh
bin/setup.sh                  # deps, config, migrations
bin/fix-permissions-hestia.sh # on Hestia; use bin/setup.sh --apache on Apache/www-data hosts
```

Then edit `config/config.ini`: set `auth.admin_password` and OpenRouter settings (via `/settings` after login). Create product profiles from the Posts workspace.

Optional: `composer test` to run unit tests.

Prerequisites: PHP 8.3+, Composer.

## Admin UI

| Route | Purpose |
|-------|---------|
| `/posts` | Posts workspace — generate, preview, approve, manual copy-to-clipboard |
| `/settings` | OpenRouter API and generation options |

Default login: configure `auth.admin_username` and `auth.admin_password` in `config.ini`.

## Manual posting workflow

1. Generate a post for a profile (New post).
2. Review and approve when ready.
3. Use **Copy text** / **Copy image** in the preview panel.
4. Paste into your social platform.

## Directory structure

```text
bin/           migrate.php, setup.sh
config/        config.ini, container, routes
docs/          project decisions and database docs
public/        web root, static JS
src/           Controllers, Services, DAO, Middleware
templates/     Twig views
tests/         PHPUnit tests
var/cache/     Twig cache (production)
var/data/      SQLite database, generated images
var/logs/      application logs
```

## Development

Web: http://192.168.11.50/ (or your host)

## Manual commands

```bash
php bin/migrate.php    # apply pending migrations
composer test          # unit tests
```

## Database

Migrations live in `docs/db/migrations/`. Current schema snapshot: `docs/db/current_schema.sql`.
