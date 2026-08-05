# Procura development workflow

Procura uses a protected two-branch release structure. Direct development commits do not belong on
`main`.

## Long-lived branches

- `main` contains only reviewed, production-ready checkpoints. Every merge must have a green pull
  request and is eligible for a release tag.
- `develop` is the shared integration branch for the next release. It must remain deployable and
  green, but it may contain approved functionality that has not yet been promoted to `main`.

Neither long-lived branch may be force-pushed or deleted. Configure GitHub branch protection to
require pull requests, successful required checks, an up-to-date branch, and resolved review
conversations before merge.

## Short-lived branches

Create short-lived branches from `develop`:

- `feature/<short-description>` for product work;
- `fix/<short-description>` for ordinary fixes;
- `release/<version>` for final release stabilization;
- `hotfix/<short-description>` from `main` only for urgent production corrections.

Feature and fix pull requests target `develop`. A reviewed release pull request promotes `develop`
or `release/<version>` into `main`. Hotfixes target `main` and must then be merged back into
`develop`. Delete short-lived branches after merge.

## Required checks

Every pull request must:

1. keep tenant and authorization boundaries intact;
2. include all five supported locales for user-facing behavior;
3. include migrations, tests, and operational documentation when applicable;
4. pass PHP formatting, Composer validation, the PHP version matrix, frontend localization/lint/
   tests/build, production deployment verification, and the MySQL/Redis runtime contract;
5. contain no `.env`, credentials, generated keys, private evidence, dependency directories, or
   generated SPA output.

Use squash merge for small focused changes and a regular merge commit when preserving a reviewed
multi-commit release history is materially useful. Do not bypass failed or pending required checks.
