# AGENTS.md

## Cursor Cloud specific instructions

### Overview

BConnect is a Laravel 11 monolith (PHP 8.3, Blade/Livewire/Alpine.js/Tailwind) for vehicle shipping logistics and quotation management. It uses Filament v3 for the admin panel, SQLite for local dev, and Vite for frontend assets.

### Running the application

- **Dev server:** `php artisan serve --host=0.0.0.0 --port=8000`
- **Vite (frontend hot reload):** `npm run dev -- --host 0.0.0.0`
- **Combined dev (server + queue + logs + vite):** `composer dev` (uses `concurrently`)
- The `.env` defaults to SQLite (`DB_CONNECTION=sqlite`) and local filesystem (`FILESYSTEM_DISK=local`). No Redis, PostgreSQL, or MinIO is required for basic local development.

### Database

- Dev database: `database/database.sqlite`. Create with `touch database/database.sqlite` if missing.
- Schema dump at `database/schema/sqlite-schema.sql` is loaded automatically by `php artisan migrate`.
- Tests use in-memory SQLite via the `sqlite_testing` connection defined in `phpunit.xml`.

### Testing

- **Run all tests:** `php artisan test` or `./vendor/bin/pest`
- **Unit only:** `php artisan test --testsuite=Unit`
- **Feature only:** `php artisan test --testsuite=Feature`
- Some existing test failures are pre-existing in the codebase (CarrierSurchargeCalculator assertions, WebhookIntegrationTest DB connection bleed, PasswordResetTest notification logic).
- `database/pipeline-testing.sqlite` must exist for WebhookIntegrationTest (create with `touch database/pipeline-testing.sqlite`).

### Linting

- **Laravel Pint:** `./vendor/bin/pint --test` (check) or `./vendor/bin/pint` (fix)

### Admin panel

- Filament admin at `/admin`. Requires a user with active status.
- Database seeder creates an admin user: `php artisan db:seed` (credentials: `patrick@belgaco.be` / `password`).
- Manually created users may be blocked by an `is_active` check; use the seeder or set the user's status via Tinker.

### Frontend build

- `npm run build` for production assets, `npm run dev` for dev server with hot reload.

### External services (optional, not required for core dev)

- AI extraction: requires `OPENAI_API_KEY` / `ANTHROPIC_API_KEY` env vars.
- Robaws ERP: requires `ROBAWS_API_KEY`. Sandbox mode enabled by default.
- Queue processing: `php artisan horizon` (requires Redis) or `php artisan queue:listen` (uses database driver).
