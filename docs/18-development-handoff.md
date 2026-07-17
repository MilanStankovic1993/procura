# 18 — Development Handoff

Last updated: 2026-07-17

This document is the persistent handoff for continuing Procura development on another computer or in a new Codex task. Read it after the preceding product and architecture documents and verify the repository before making changes.

## 1. Source of truth

```text
Repository: https://github.com/MilanStankovic1993/procura
Default branch: main
Project name: Procura
```

Foundation commits preceding this handoff:

```text
4d058c4 Run CI on main branch
d85af3a Initialize Procura foundation
```

The repository was clean and synchronized with `origin/main` when this handoff was written.

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
- Each registered user will receive a personal organization.
- The architecture style is a modular monolith.
- Slow work will use queues and must not run directly inside controllers.
- Prices, risk scores, and recommendations must be explainable and reproducible.
- AI must not be the sole authority for price, fraud, tax, customs, or transaction safety.

## 3. Current technical state

Installed foundation:

```text
Laravel Framework 12.64.0
PHP requirement 8.3+ for Procura development
Composer 2
Node.js and npm required for frontend assets
```

The repository currently contains:

- the standard Laravel application skeleton,
- the complete Procura documentation set,
- the default Laravel user, cache, and queue migrations,
- two default Laravel example tests,
- GitHub Actions configured for the `main` branch,
- CI testing on PHP 8.2, 8.3, and 8.4.

Local verification completed before handoff:

```text
php artisan test       2 tests passed
vendor/bin/pint --test passed
composer validate      passed
GitHub Actions          passed
```

## 4. Not implemented yet

The following are intentionally not installed or implemented:

- authentication UI and registration flow,
- email verification flow,
- Pest,
- Filament,
- Sanctum,
- organizations and memberships,
- active organization resolution,
- tenant policies,
- roles and permissions,
- plans, plan features, and usage tracking,
- continent, country, and currency tables,
- application landing page and authenticated layout,
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

The development `.env` used on the first computer was configured with SQLite only for initial framework verification.

- No Procura domain migrations exist yet.
- The default Laravel migrations have not been treated as the Phase 1 database implementation.
- Production and primary local development are intended to use MySQL 8+.
- Redis, Horizon, and queue infrastructure are architectural requirements but are not configured yet.
- Never commit `.env`, database files, credentials, tokens, or generated application keys.

Before creating Phase 1 migrations, confirm the local MySQL database name and credentials and keep SQLite available for fast automated tests where appropriate.

## 6. Setup on another computer

Clone and enter the repository:

```powershell
git clone https://github.com/MilanStankovic1993/procura.git
Set-Location procura
```

Install application dependencies:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
npm install
npm run build
```

Then configure the local database in `.env`. Do not copy the previous computer's `.env` or `APP_KEY` through Git.

Open the cloned Procura directory itself as the Codex workspace. Do not use an unrelated parent or mirror folder as the active workspace.

Before development, run:

```powershell
php artisan --version
composer validate --no-check-publish
php artisan test
vendor\bin\pint --test
git status -sb
```

## 7. Next implementation task

Continue Phase 1 only.

The immediate next task is authentication and the Pest test foundation:

1. Inspect the fresh clone and current package versions.
2. Confirm the current official Laravel 12 authentication approach compatible with the documented Livewire and Tailwind stack.
3. Report the current Laravel version, Filament status, authentication status, database structure, and planned file changes before writing code.
4. Install and configure the selected authentication foundation.
5. Add registration, login, logout, password reset, and email verification.
6. Install Pest and convert or replace the default tests.
7. Add authentication feature tests.
8. Run migrations, tests, Pint, and frontend build checks.
9. Commit and push a focused change only after all checks pass.

Do not start organizations in the same change unless the authentication foundation is complete and verified first.

## 8. Phase 1 sequence after authentication

After authentication is complete, continue in small verified changes:

```text
authentication and Pest
→ personal organizations and memberships
→ active organization context and switching
→ roles and tenant-aware policies
→ global country and currency reference data
→ plans, plan features, and subscription usage
→ Filament v5 administration
→ landing page and authenticated application shell
```

## 9. Known non-blocking notes

- Composer previously displayed ambiguous Flysystem local-class resolution warnings from installed dependency versions. Installation, tests, and CI still passed. Re-check after dependency changes and do not hide the warning if it remains.
- GitHub CLI required the `workflow` OAuth scope to publish `.github/workflows` files. This was resolved on the first computer only; a new computer requires its own GitHub authentication.
- The current example tests prove only that the Laravel skeleton boots. They are not Procura acceptance tests.

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
- report every failed command and unresolved issue,
- keep documentation and this handoff current,
- never commit secrets.
