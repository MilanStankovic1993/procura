# 16 — Codex Development Rules

## 1. Read order

Before implementing a module, Codex must read:

- product vision,
- relevant functional requirements,
- domain model,
- architecture,
- module-specific document,
- testing strategy.
- global market model.
- current development handoff.
- production go-live register.

## 2. Existing repository

Before changing code:

1. inspect repository,
2. identify Laravel version,
3. identify Filament version,
4. identify authentication setup,
5. identify existing conventions,
6. list planned file changes.

Do not overwrite working code without reason.

Any change that adds an environment variable, secret, external provider, webhook, worker, scheduled
command, migration/backfill, production-only manual step, monitoring requirement, or rollback
condition must update `19-production-go-live.md` in the same task. An integration is not complete
until its configuration, external activation, verification, monitoring, rotation, and rollback are
recorded there without committing secret values.

## 3. Implementation style

- Use strict typing where practical.
- Keep controllers thin.
- Put business logic in services.
- Use DTOs between modules.
- Use enums for domain states.
- Use policies for authorization.
- Use queues for slow work.
- Use transactions for multi-record state changes.
- Use Pest tests.
- Run Pint.
- Every new FormRequest field and every newly used Laravel validation rule must update
  `validation_attributes.php` and `validation.php` for EN/DE/ES/FR/sr-Latn in the same change.
- Every expected public API conflict must use `ApiErrorCode`; the same change must add one safe,
  non-empty `api_errors.php` message in all five locales. API renderers must never expose a raw
  exception message.
- Every expected application-service `422` must use `ApplicationValidationCode` and
  `ApplicationValidation`; the same change must add one safe, non-empty
  `application_validation.php` message in all five locales. Do not add new ad hoc
  `ValidationException::withMessages` presentation strings outside that boundary.
  Presentation text may be translated; stored codes, tenant boundaries, market scope, money, and
  authorization decisions must remain language-neutral.

## 4. Completion report

After each task, report:

- implemented features,
- migrations,
- changed files,
- tests added,
- commands run,
- failures,
- unresolved issues,
- recommended next task.

## 5. First implementation task

Implement only the project foundation:

1. authentication,
2. personal organizations,
3. memberships,
4. active organization,
5. tenant policies,
6. Filament admin,
7. plan tables,
8. usage tables,
9. seed plans,
10. country and currency reference data,
11. organization market preferences,
12. tests.

This was the initial bootstrap gate. Later phases may implement only the boundaries marked approved
in `15-delivery-roadmap.md` and `18-development-handoff.md`. The Stripe application boundary was
later separately approved and implemented; live activation, broader payment processing,
marketplace automation, and browser extensions still require their own approved boundary.

## 6. First Codex prompt

```text
Read all documentation files in the order defined in README.md.

Inspect the existing repository before making changes.

Implement Phase 1 foundation only.

First report:
- Laravel version
- Filament version
- authentication setup
- current database structure
- planned files to create or modify

Then implement:
- authentication
- personal organization creation
- organization memberships
- active organization context
- organization owner and member roles
- super admin authorization
- tenant-aware policies
- plan and plan-feature tables
- subscription usage table
- Free, Starter, Pro, and Business seed data
- Pest tests for registration, organization creation, membership, and cross-tenant access

After implementation:
- run migrations
- run tests
- run Pint
- report changed files
- report all unresolved errors honestly

Do not start Phase 2.
```
