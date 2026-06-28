# Social Poster

AI-powered daily Facebook and LinkedIn posting system. Product profiles define sources, posting windows, and target pages; OpenRouter generates platform-specific copy; Playwright publishes via browser automation.

## Stack

- PHP 8.3+ / Slim 4 / PHP-DI
- SQLite
- Twig + Bootstrap 5.3
- OpenRouter (content generation with `fetch_page` tool)
- Playwright (Node) for Facebook/LinkedIn posting
- Config-based single admin auth
- Cron every 15 minutes via `bin/post-daily.php` (flock)

See [docs/project-decisions.md](docs/project-decisions.md) for full setup choices.

## Quick start

```bash
cd /var/www/social-poster
chmod +x bin/setup.sh
bin/setup.sh                  # deps, config, migrations, Playwright
sudo bin/setup.sh --apache    # on Apache hosts: www-data permissions
```

Then edit `config/config.ini`: set `auth.admin_password` and OpenRouter settings (via `/settings` after login). Import browser sessions via **Sessions** in the admin UI.

Optional flags: `--with-deps` if Chromium fails to launch; `composer test` to run unit tests.

Prerequisites: PHP 8.3+, Composer, Node.js 20+, npm. On Debian: `apt install nodejs npm`.

## Admin UI

| Route | Purpose |
|-------|---------|
| `/` | Dashboard — due profiles, sessions, last cron run |
| `/profiles` | Product profile CRUD, sources, targets |
| `/settings` | OpenRouter API, posting grace/retry, browser options |
| `/sessions` | Named Facebook/LinkedIn Playwright sessions (create, capture, import) |
| `/posts` | Generated post history and publication status |
| `/tasks/{id}` | Background task progress (generation / publishing) |

Default login: configure `auth.admin_username` and `auth.admin_password` in `config.ini`.

## Directory structure

```text
automation/    Playwright scripts (post/repost FB/LI)
bin/           migrate.php, post-daily.php, task-worker.php, import-session.php
config/        config.ini, container, routes
docs/          project decisions and database docs
public/        web root
src/           Controllers, Services, DAO, Middleware
templates/     Twig views
tests/         PHPUnit tests
var/cache/     Twig cache (production)
var/data/      SQLite database
var/logs/      cron, browser screenshots
```

## Development

Connect via Cursor Remote SSH:

- Host: `root@192.168.11.50`
- Folder: `/var/www/social-poster`

Web: http://192.168.11.50/

## Cron

```cron
*/15 * * * * /usr/bin/php /var/www/social-poster/bin/post-daily.php >> /var/www/social-poster/var/logs/cron.log 2>&1
```

`post-daily.php` acquires `var/posting.lock`, recovers stale task jobs, finds due profiles via `PostingSchedulerService`, and enqueues recipes on `TaskEngine`. Workers run via `bin/task-worker.php` (spawned as `www-data`). Playwright steps serialize on `var/playwright.lock`. Results are recorded in `posting_runs` and `task_jobs`.

## Manual commands

```bash
php bin/migrate.php              # apply pending migrations
php bin/post-daily.php           # run posting cycle once
php bin/import-session.php "Jamie Facebook" /path/to/storage.json
composer test                    # unit tests
```

## Database

Migrations live in `docs/db/migrations/`. Current schema snapshot: `docs/db/current_schema.sql`.
