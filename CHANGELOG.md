# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.0.0...master)

- Added the typed `ApplicationValidationCode`/`ApplicationValidation` boundary and exact
  EN/DE/ES/FR/sr-Latn catalogs for 60 expected platform and Analysis validation failures across
  organizations, monitoring/notifications, Telegram, privacy, uploads, product search,
  outcome-money normalization, manual retry, comparable intake/normalization, cost/opportunity
  confirmation, and buyer decisions, preserving Laravel's field-keyed `422` contract and adding
  enum/catalog/runtime/source regression checks.
- Added typed request-scoped localization for 22 public billing, marketplace-import, privacy,
  buyer-decision, sale-portfolio, and outcome conflict codes across EN/DE/ES/FR/sr-Latn, with
  stable language-neutral API codes, exact enum/catalog contracts, and raw-diagnostic exclusion.
- Added centralized five-language API/Fortify localization with authenticated preference
  precedence, regional guest `Accept-Language` resolution, deterministic English fallback,
  request-state isolation, `Content-Language`, complete current validator-rule and FormRequest
  attribute catalogs, privacy-preserving reset copy, CI contracts, and production smoke/reload
  instructions.
- Added the first production-capacity regression boundary: a 2,000-row deterministic tenant
  fixture, versioned `11/1/2` query budgets for cold Admin metrics/Analysis Operations/tenant
  Analysis listing, a guarded JSON staging command with optional duration enforcement, one shared
  stampede-protected dashboard snapshot, explicit CI execution, and production-shaped load/rollback
  instructions.
- Added production-grade operational readiness with bounded database/shared-cache probes,
  per-pool queue-processing heartbeats, stale/high-latency and out-of-order protection, sanitized
  `503` health responses, strict JSON/CLI deployment checks, a five-language Admin readiness tile,
  singleton scheduling, and complete activation/monitoring/incident instructions.
- Added localized Analysis Operations for terminal/stale processing heads with a shared dashboard
  count, verified-super-admin-only audited manual retry, exact-dispatch concurrency, UUID replay,
  immutable retry evidence, bounded cumulative attempts, quota preservation, CLI operation,
  sensitive-error projection controls, and production activation/rollback instructions.
- Added the audited self-service privacy-request foundation for data export and account deletion:
  immutable event history, optimistic concurrency, idempotent subject/operator commands,
  deterministic deletion blockers, read-only Admin operations, production runbook, and complete
  EN/DE/ES/FR/sr-Latn application/Admin copy. Export generation and data erasure remain explicitly
  gated by approved production procedures.
- Added independent immutable Sell comparable market normalization with exact assessment/target
  scope, dated FX provenance, bounded explicit market factors and landed costs, atomic
  selector/price-band v2 replay, default assessed target scopes, fail-closed validation, read-only
  Admin operations, bounded per-record API history, and complete EN/DE/ES/FR/sr-Latn UI.
- Added explicit immutable Buy comparable market normalization with dated FX provenance, bounded
  analyst-confirmed market factors, exact target-currency landed costs, selector/estimator v2
  replay, fail-closed validation, read-only Admin operations, and complete EN/DE/ES/FR/sr-Latn UI.
- Added the production-gated `authorized_csv` marketplace connector with private file storage,
  tenant/idempotency controls, immutable per-row evidence, bounded validation, isolated queue
  processing, stale-import recovery, read-only operations visibility, and complete
  EN/DE/ES/FR/sr-Latn application copy.

## [v12.0.0 (2025-??-??)](https://github.com/laravel/laravel/compare/v11.0.2...v12.0.0)

Laravel 12 includes a variety of changes to the application skeleton. Please consult the diff to see what's new.
