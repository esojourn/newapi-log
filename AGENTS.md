# Repository Guidelines

## Project Structure & Module Organization

This Laravel 8 application targets PHP 8.1 and provides NewAPI log queries, usage dashboards, and Feishu balance alerts.

- `app/Http/Controllers/` and `routes/` handle API, dashboard, and alert endpoints.
- `app/Models/`, `app/Services/`, and `app/Support/` contain data access, alert delivery, and quota helpers.
- `resources/views/` contains Blade templates; `resources/js/` and `resources/css/` contain asset sources; `public/` is the web root.
- `tests/Feature/` and `tests/Unit/` hold tests. `docs/` documents database semantics and deployment.

## Build, Test, and Development Commands

- `composer install`: install PHP dependencies.
- First setup: copy `.env.example` to `.env`, configure database access and `ADMIN_PASSWORD`, then run `php artisan key:generate`.
- `php artisan serve`: start the local development server.
- With DDEV configured: `ddev start` and `ddev composer install`; prefix PHP commands with `ddev exec`.
- `php artisan test`: run PHPUnit; `./vendor/bin/phpunit tests/Feature/AlertsTest.php` runs alert tests.
- `php artisan config:clear`: reload configuration after `.env` changes.
- `php artisan alerts:check --dry-run`: evaluate alerts without sending notifications or updating state.

Pages load Tailwind CSS and Chart.js from CDNs; no frontend build is required. For changes to Mix assets, run `npm install`, then `npm run dev` or `npm run prod`.

## Coding Style & Naming Conventions

Follow `.editorconfig`: UTF-8, LF, four-space indentation, final newlines; YAML uses two spaces. `.styleci.yml` specifies the Laravel PHP preset. Use PSR-4 namespaces matching directories, PascalCase classes, camelCase methods, and descriptive `test_snake_case` test methods. Keep shared logic in services or helpers.

## Testing Guidelines

Use PHPUnit 9 and `*Test.php` filenames. Add regression tests for behavior changes; no minimum coverage percentage is configured. Statistics smoke tests require an accessible NewAPI database. Alert tests use in-memory SQLite; fake outbound HTTP and fix configuration values needed by assertions. Never use `RefreshDatabase` against the default connection.

## Commit & Pull Request Guidelines

Recent commits use `feat:`, `fix:`, `test:`, and `refactor:` prefixes, often with Chinese descriptions. Follow this pattern with focused subjects. PRs should describe behavior changes, link relevant issues, report validation and environment limitations, and include screenshots for UI changes.

## Security & Configuration

Keep the external NewAPI database read-only. Create `database/alerts.sqlite`, then migrate only with:

```bash
php artisan migrate --database=alerts --path=database/migrations/alerts
```

Never run unscoped migrations. Keep `.env`, API keys, webhooks, and SQLite files uncommitted. Preserve existing `APP_KEY` values: changing the key invalidates encrypted webhook settings. See `docs/deployment.md`.
