# Release Notes

## [Unreleased](https://github.com/laravel/laravel/compare/v12.0.0...master)

- Added a production deployment-contract verifier for nginx security/routing headers, Supervisor
  worker ownership/timeouts/recycling, Redis retry safety, scheduler recovery coverage, and the
  fail-closed environment template. CI now has bounded concurrency/timeouts and a separate MySQL
  8.4/Redis 7.4 job that applies the real migration ledger, verifies cached readiness, and enforces
  strict UTC/utf8mb4/InnoDB/foreign-key/index/query compatibility outside SQLite.
- Added a fail-closed production preflight boundary with secret-free table/JSON output, deployable
  versus strict launch decisions, trusted-host and explicit non-catch-all proxy enforcement,
  same-origin HTTPS/CORS/Sanctum checks, strict non-root MySQL, TLS Redis cache/queue, queue-timeout
  safety, encrypted secure sessions, private fail-loud S3 evidence storage, mail/provider and
  feature-dependency checks, a sanitized production environment template, and regression tests.
  Added an independent analysis-submission kill switch that blocks before quota/dispatch/provider
  work while fake providers remain configured.
- Added the Phase 8 broker/sourcing request foundation: tenant-safe draft/list/detail workflows,
  immutable event snapshots, exact event-head concurrency and UUID replay, atomic monthly plan
  enforcement, subject cancellation, evidence-bound verified-super-admin review/search/cancel,
  immutable evidence-bound supplier offers, server-calculated exact-money totals, exact request/
  offer-head acceptance, atomic alternative closure, safe multi-currency comparison, read-only
  Admin/CLI operations, privacy-erasure blocking, and complete EN/DE/ES/FR/sr-Latn Angular, Admin,
  and application-validation catalogs.
- Added Phase 8 accepted-offer transaction and commission ledgers: versioned commission disclosure
  with exact integer half-up calculation, acceptance-time term revalidation, atomic one-to-one
  transaction/commission opening, exact-head evidence-bound payment/order/shipping/delivery/
  completion transitions, pre-payment cancellation with commission waiver, earned-only settlement,
  safe tenant projections, localized Angular fulfillment timelines, localized read-only Filament
  resources, false-by-default production activation, and replay/immutability/authorization tests.
  Procura still does not execute payments, refunds, supplier orders, or carrier operations.
- Added Phase 8 evidence-derived broker reports: immutable dual-head source snapshots, first-party
  Dompdf A4 rendering in EN/DE/ES/FR/sr-Latn, private checksum-verified storage, short-lived
  tenant-authorized signed downloads, read-only localized Admin visibility, scheduled
  deletion-before-purge retention evidence, and fail-closed personal-account erasure integration.
  Reports exclude private supplier references, operator evidence, request notes, credentials,
  payment data, hashes, snapshots, replay keys, and storage coordinates from subject projections.
- Added the Phase 8 provider-independent broker payment-case foundation: false-by-default refund/
  dispute opening after exact payment-confirmed evidence, immutable case/event chains, strict
  review and type-compatible resolution outcomes, bounded exact amounts, transaction-scoped replay
  and logical-case uniqueness, safe tenant timelines, localized read-only Admin and CLI operations,
  personal-account erasure blocking, and privacy inventory v4. It does not move funds, submit a
  refund or chargeback, call a provider, or rewrite transaction/commission history.
- Added the typed `ApplicationValidationCode`/`ApplicationValidation` boundary and exact
  EN/DE/ES/FR/sr-Latn catalogs for 179 expected platform, Analysis, OwnedProducts, privacy,
  broker-request, and broker-offer validation failures across
  organizations, monitoring/notifications, Telegram, privacy, uploads, product search,
  outcome-money normalization, manual retry, comparable intake/normalization, cost/opportunity
  confirmation, buyer decisions, Sell/portfolio lifecycles, realized outcomes, and estimate
  accuracy, preserving Laravel's field-keyed `422` contract and adding enum/catalog/runtime/source
  regression checks.
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
  EN/DE/ES/FR/sr-Latn application/Admin copy. Added a disabled-by-default, verified-super-admin-only
  data-export completion operation with a reserved terminal state, exact inventory/checksum/size/
  expiry/delivery controls, immutable receipt, safe subject projection, and exact replay conflict.
  Added an independently disabled account-erasure executor with exact blocker clearances, live
  ownership/billing/admin recalculation, known-private-file absence checks, transactional personal
  tenant/access removal, invitation anonymization, pseudonymous user tombstone, immutable evidence
  receipt, bounded backup deadline, exact replay and five-language safe projections. Archive
  generation/delivery and external storage/processor/backup erasure remain explicitly gated work.
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
