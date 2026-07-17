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

## 2. Existing repository

Before changing code:

1. inspect repository,
2. identify Laravel version,
3. identify Filament version,
4. identify authentication setup,
5. identify existing conventions,
6. list planned file changes.

Do not overwrite working code without reason.

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

Do not implement AI, Stripe, Telegram, marketplace automation, or browser extensions yet.

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
