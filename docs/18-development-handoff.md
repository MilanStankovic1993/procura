# 18 - Development Handoff

Last updated: 2026-07-17

This document is the persistent handoff for continuing Procura development on another computer or in a new Codex task. Read it after the preceding product and architecture documents and verify the repository before making changes.

## 1. Source of truth

```text
Repository: https://github.com/MilanStankovic1993/procura
Default branch: main
Project name: Procura
Current development branch: codex/authentication-foundation
```

Foundation commits preceding the authentication branch:

```text
ade00af Document development handoff
4d058c4 Run CI on main branch
d85af3a Initialize Procura foundation
```

Never commit `.env`, database files, credentials, tokens, generated application keys, or build artifacts.

## 2. Product decisions already made

- The application name is Procura.
- Procura is a global market-intelligence SaaS product.
- The initial product category is professional power tools.
- Manual listing analysis may accept data from any country.
- Continent is a discovery and grouping filter.
- Country is the primary pricing, marketplace, shipping, customs, tax, and compliance boundary.
- A user will be able to select one or more countries and explicitly enable cross-border search.
- Global architecture does not imply that Procura can automatically search every marketplace.
- Marketplace coverage must come from manual input or explicitly authorized APIs, feeds, imports, and future connectors.
- The application will use a shared-database multi-tenant model with `organization_id` on tenant-owned records.
- Each registered user will receive a personal organization in the next focused implementation task.
- The architecture style is a modular monolith.
- Slow work will use queues and must not run directly inside controllers.
- Prices, risk scores, and recommendations must be explainable and reproducible.
- AI must not be the sole authority for price, fraud, tax, customs, or transaction safety.

## 3. Current technical state

Installed and locked foundation:

```text
Laravel Framework 12.64.0
PHP requirement ^8.3
Laravel Fortify 1.36.2
Livewire 4.1.0
Flux 2.13.0
Pest 4.7.5
PHPUnit 12.5.30 (through Pest)
Node.js and npm with package-lock.json
Tailwind CSS 4 and Vite 6
```

Authentication currently includes:

- registration with normalized lowercase email addresses,
- login and logout through the session-based `web` guard,
- login rate limiting at five attempts per minute per email and IP address,
- passwords requiring at least 12 characters, uppercase, lowercase, a number, and a symbol,
- password reset by email notification,
- signed email verification links,
- password confirmation,
- a dashboard protected by both `auth` and `verified` middleware,
- responsive Procura auth and dashboard layouts.

The `User` model implements `MustVerifyEmail`. Fortify enables only registration, password reset, and email verification. Two-factor authentication, passkeys, profile updates, and password settings are intentionally disabled.

Local verification completed for this task:

```text
php artisan migrate                 passed (default users, cache, and jobs migrations)
php artisan test                    18 tests passed, 68 assertions
vendor/bin/pint                     passed after formatting
npm run build                       passed
composer validate --no-check-publish passed
composer audit                      no advisories
npm audit                           no advisories
Browser check                       desktop and mobile, no console errors or horizontal overflow
```

GitHub Actions now targets PHP 8.3 and 8.4, runs `php artisan test` so Pest is the test entry point, and performs `npm ci` plus the frontend build once in the PHP 8.3 job. Confirm the remote workflow result after this branch is pushed.

## 4. Not implemented yet

The following remain intentionally unimplemented:

- organizations and memberships,
- automatic personal organization creation,
- active organization resolution and switching,
- tenant policies,
- roles and permissions,
- plans, plan features, and usage tracking,
- continent, country, and currency tables,
- Filament administration,
- Sanctum API authentication,
- production email delivery configuration,
- final public landing page (the Laravel placeholder remains),
- listing intake,
- AI analysis,
- price analysis,
- risk and deal scoring,
- Stripe,
- Telegram,
- marketplace automation,
- scraping,
- browser extensions.

Do not assume that a package is installed because it appears in the architecture documents. Verify `composer.json` and installed package versions first.

## 5. Database state

The local `.env` uses SQLite. The ignored `database/database.sqlite` file was created and the three default Laravel migrations were run successfully.

- No Procura domain migrations exist yet.
- Automated feature tests use an in-memory SQLite database through `phpunit.xml`.
- Production and primary local development are intended to use MySQL 8+.
- Redis, Horizon, and queue infrastructure are architectural requirements but are not configured yet.

Before creating organization migrations, confirm the local MySQL database name and credentials if MySQL will be used on that computer. Keep SQLite available for fast automated tests.

## 6. Setup on another computer

Clone and enter the repository:

```powershell
git clone https://github.com/MilanStankovic1993/procura.git
Set-Location procura
```

Install dependencies and initialize the local application:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
npm ci
npm run build
```

Configure the local database in `.env`, create the SQLite file when using SQLite, and run:

```powershell
php artisan migrate
php artisan test
vendor\bin\pint --test
composer validate --no-check-publish
composer audit
git status -sb
```

Do not copy another computer's `.env` or `APP_KEY` through Git. Open the cloned Procura directory itself as the Codex workspace.

## 7. Next implementation task

Continue Phase 1 with personal organizations and memberships only.

1. Re-read the organization, tenancy, authorization, and database architecture documents.
2. Inspect the authentication branch and verify package versions and tests before editing.
3. Design and report the organization and membership migrations, models, constraints, relationships, and policies before implementation.
4. Create a personal organization atomically for each newly registered user.
5. Make the registering user the organization owner through an explicit membership record.
6. Add model factories and Pest tests for creation, uniqueness, ownership, rollback behavior, and tenant boundaries.
7. Decide and document how the active organization will be stored, but do not combine a full switcher or role system into the same change unless the foundation is complete and verified.
8. Run migrations, tests, Pint, Composer checks, and any affected frontend build.
9. Commit and push one focused change only after all checks pass.

Do not start roles, country reference data, plans, Filament, listing intake, AI, Stripe, Telegram, scraping, imports, or browser extensions in the organization-foundation change.

## 8. Phase 1 sequence after authentication

```text
personal organizations and memberships
-> active organization context and switching
-> roles and tenant-aware policies
-> global country and currency reference data
-> plans, plan features, and subscription usage
-> Filament administration
-> final landing page and broader application shell
```

## 9. Known non-blocking notes

- Fortify is intentionally constrained to `~1.36.2`. Fortify 1.37 currently requires a passkey/WebAuthn dependency tree with an audit advisory, while passkeys are outside this phase. Re-evaluate the constraint when Fortify provides a safe compatible passkey line or when passkeys are deliberately implemented.
- The first auth package install encountered an incomplete copied `vendor` directory. The generated directory was safely rebuilt with a clean Composer dist install; no repository file or user change was removed.
- The first Pest view run failed because no Vite manifest existed. Feature tests now call `withoutVite()`, and the real frontend pipeline is validated separately with `npm run build`.
- Local email delivery is not a production mail service. Registration, reset, and verification notifications are tested with Laravel notification fakes.
- GitHub CLI authentication is local to each computer and must include permission to update workflow files before pushing CI changes.

## 10. Required completion behavior

For every future task:

- inspect before editing,
- preserve tenant boundaries,
- list planned files,
- keep business logic out of controllers and views,
- use policies and backend enforcement,
- add tests proportional to risk,
- run migrations where relevant,
- run tests and Pint,
- run frontend build checks when frontend files change,
- run dependency validation and audit checks,
- report every failed command and unresolved issue,
- keep documentation and this handoff current,
- never commit secrets.
