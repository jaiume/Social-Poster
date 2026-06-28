# Project Decisions

Recorded setup choices for Social Poster. Update this file when meaningful decisions change.

## Confirmed Setup (2026-06-19)

| Area | Choice |
|------|--------|
| Project type | Admin tool — internal dashboard for AI-generated posts and posting management |
| Hosting | Home Debian 13 LXC, Apache, PHP 8.4 |
| Framework | Slim 4 |
| Dependency injection | PHP-DI |
| Database | SQLite (`var/data/social_poster.sqlite`) |
| Frontend | Twig + Bootstrap 5.3 |
| Authentication | Config-based single admin (`config.ini`) |
| Testing | Unit tests for services (scheduler, encryption, orchestrator) |
| Daily execution | Cron every 15 minutes via `bin/post-daily.php` — enqueues `TaskEngine` jobs only |
| Long-running work | `TaskEngine` + `bin/task-worker.php` — atomic steps composed into recipes |
| AI content | OpenRouter agent loop with server-side `fetch_page` tool |
| Publishing | Playwright (Node) browser automation — not platform APIs |
| Sessions | Named encrypted browser sessions (`browser_sessions`); each profile target references one session |

## Architecture

```text
Cron (15 min) -> bin/post-daily.php (flock)
    -> TaskJobRecovery::releaseStaleJobs()
    -> PostingSchedulerService (due profiles)
    -> TaskEngine::enqueue(recipe) per profile
    -> TaskWorkerService::run(jobId) inline
        -> generation.content / image_prep / image_render / finalize
        -> publishing.* (Playwright, playwright.lock)
```

```text
Web (Generate / Regenerate / Publish)
    -> TaskEngine::enqueue()
    -> redirect /tasks/{id}
    -> JS POST /start -> TaskWorkerService::run() (Apache, in-process)
    -> poll GET /tasks/{id}/status
```

```text
HTTP Request -> Middleware -> Controller -> Service -> DAO -> SQLite
                                    |
                                    +-> Twig View
```

- Web controllers live in `src/Controllers/Web/`
- Business logic stays in services; controllers handle HTTP only
- Product profiles own sources (URLs), targets (primary + repost pages), and posting windows

## Product Profile Model

- **Posting window** — random time chosen daily within `posting_window_start`–`posting_window_end` in profile timezone
- **Grace period** — global `posting_window_grace_minutes` (default 15) after window end
- **Weekdays only** — optional skip on Saturday/Sunday
- **Retries** — max `posting_max_retries_per_day` (default 3); reuse generated copy on retry
- **`last_posted_at`** — updated only when status is fully `published` (both platforms)

## Deviations From Default Standard

- **SQLite instead of MariaDB** — chosen for a single-server personal admin tool with simpler deployment.
- **Browser automation instead of APIs** — Facebook/LinkedIn posting via Playwright UI scripts with imported sessions.
- **PHP 8.4 on server** — environment runs PHP 8.4; project requires `^8.3`.

## Not In Scope (Initial Setup)

- Mobile API
- Email / PHPMailer
- systemd worker pool (uses per-job `nohup` workers)
- Docker
- Multi-user accounts

## Cron Setup

Add a system crontab entry (root or `www-data`):

```cron
*/15 * * * * /usr/bin/php /var/www/social-poster/bin/post-daily.php >> /var/www/social-poster/var/logs/cron.log 2>&1
```

The script uses `var/posting.lock` (flock) so overlapping runs exit cleanly.

## Node / Playwright

Browser automation requires Node.js 20+ and Playwright Chromium:

```bash
apt install nodejs npm   # or use nvm
cd /var/www/social-poster/automation
npm install
npx playwright install chromium
npx playwright install-deps chromium
```

Import platform sessions via **Sessions** in the admin UI or `php bin/import-session.php`.

## Security

- `security.encryption_key` in `config.ini` encrypts OpenRouter API keys and browser session storage
- CSRF protection on all authenticated POST routes
- Single admin account in `config.ini` (plain password on private network)

## Setup Checklist

1. Copy `config/config.ini.example` to `config/config.ini`
2. Set `auth.admin_password` and generate `security.encryption_key` (32+ char secret)
3. Run `php bin/migrate.php`
4. Install Node dependencies in `automation/` and Playwright Chromium
5. Configure OpenRouter settings and import Facebook/LinkedIn sessions
6. Create product profiles with sources and targets
7. Enable the 15-minute cron job
