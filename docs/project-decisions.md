# Project Decisions

Recorded setup choices for Social Poster.

## Confirmed Setup

| Area | Choice |
|------|--------|
| Project type | Admin tool — internal dashboard for AI-generated posts |
| Hosting | Hestia dev host — https://socialposter.stuckbendix.com (`192.168.11.182`) |
| Framework | Slim 4 |
| Dependency injection | PHP-DI |
| Database | SQLite (`var/data/social_poster.sqlite`) |
| Frontend | Twig + Bootstrap 5.3 |
| Authentication | Config-based single admin (`config.ini`) |
| Testing | Unit tests for services |
| AI content | OpenRouter agent loop with server-side `fetch_page` tool |
| Publishing | Manual — copy text/image from workspace to FB/LI composers |

## Architecture

```text
Web (Generate / Regenerate image)
    -> TaskEngine::enqueue()
    -> redirect /posts?active_task_id=…
    -> JS POST /tasks/{id}/start -> TaskWorkerService::run() (Apache, in-process)
    -> poll GET /tasks/{id}/status
```

```text
HTTP Request -> Middleware -> Controller -> Service -> DAO -> SQLite
                                    |
                                    +-> Twig View
```

- Web controllers live in `src/Controllers/Web/`
- Business logic stays in services; controllers handle HTTP only
- Product profiles own sources and guidance

## Post workflow

- **draft** — generated, awaiting review
- **approved** — ready for manual posting
- **archived** — done / filed away

## Deviations From Default Standard

- **Hestia instead of bare LXC** — dev environment with HTTPS on a private domain; see [docs/dev-server-hestia.md](dev-server-hestia.md).
- **Manual posting instead of browser automation** — copy text and image from the admin UI into social platforms.
- **PHP 8.4 on server** — environment runs PHP 8.4; project requires `^8.3`.

## Not In Scope

- Automated cron posting
- Playwright / browser sessions
- Platform posting APIs
- Multi-user accounts

## Security

- `security.encryption_key` in `config.ini` encrypts OpenRouter API keys
- CSRF protection on all authenticated POST routes
- Single admin account in `config.ini`

## Setup Checklist

1. Copy `config/config.ini.example` to `config/config.ini`
2. Set `auth.admin_password` and generate `security.encryption_key` (32+ char secret)
3. Run `php bin/migrate.php` or `bin/setup.sh`
4. Configure OpenRouter settings
5. Create product profiles with sources and guidance
