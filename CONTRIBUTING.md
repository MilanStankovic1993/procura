# Procura development workflow

Procura uses a two-branch release structure. Development commits go directly to `develop`; direct
commits never belong on `main`.

## Long-lived branches

- `main` contains only reviewed, production-ready checkpoints. Every merge must have a green pull
  request and is eligible for a release tag.
- `develop` is the shared integration branch for the next release. It must remain deployable and
  green, but it may contain approved functionality that has not yet been promoted to `main`.
  Normal sequential Procura development is committed and pushed directly to this branch after all
  task-level checks pass.

Neither long-lived branch may be force-pushed or deleted. Configure `main` branch protection to
require a pull request from `develop`, successful required checks, an up-to-date branch, and
resolved review conversations before merge. `develop` must retain CI checks and force-push/deletion
protection without requiring a feature pull request for each sequential development task.

## Short-lived branches

Short-lived branches are optional and reserved for parallel work, risky experiments, release
stabilization, or urgent production fixes:

- `feature/<short-description>` for product work;
- `fix/<short-description>` for ordinary fixes;
- `release/<version>` for final release stabilization;
- `hotfix/<short-description>` from `main` only for urgent production corrections.

Optional feature and fix pull requests target `develop`. The required reviewed release pull request
promotes `develop` or `release/<version>` into `main`. Hotfixes target `main` and must then be merged
back into `develop`. Delete every short-lived branch after merge. Do not create `codex/*` branches
for normal Procura development.

## Required checks

Every direct `develop` task and every pull request must:

1. keep tenant and authorization boundaries intact;
2. include all five supported locales for user-facing behavior;
3. include migrations, tests, and operational documentation when applicable;
4. pass PHP formatting, Composer validation, the PHP version matrix, frontend localization/lint/
   tests/build, production deployment verification, and the MySQL/Redis runtime contract;
5. contain no `.env`, credentials, generated keys, private evidence, dependency directories, or
   generated SPA output.

Use squash merge for small focused changes and a regular merge commit when preserving a reviewed
multi-commit release history is materially useful. Do not bypass failed or pending required checks.
