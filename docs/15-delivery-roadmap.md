# 15 — Delivery Roadmap

## Phase 0 — Product validation

Duration: 1–2 weeks.

Deliverables:

- landing page,
- product positioning,
- pilot-user list,
- 20 user interviews,
- 50 manually evaluated products,
- validation report.

## Phase 1 — Foundation

Duration: 2–3 weeks.

Deliverables:

- Laravel setup,
- user-first workspace action center (complete: two capability-aware Buy/Sell entry points,
  recent tenant work, explicit loading/error/empty states, responsive five-language interface,
  and no infrastructure diagnostics in customer-facing copy),
- authentication,
- organizations,
- roles,
- policies,
- Filament,
- global country and currency reference data,
- organization market preferences,
- plans,
- usage tracking,
- complete five-language Filament operations interface with one shared personal locale preference,
- central five-language API/Fortify request-validation catalogs, complete current FormRequest
  attribute coverage, authenticated-preference precedence, and request-scope isolation (complete),
- typed, language-neutral public API conflict codes with request-scoped EN/DE/ES/FR/sr-Latn
  presentation catalogs and raw-diagnostic exclusion (complete),
- typed five-language application-validation boundary for organizations, monitoring/notifications,
  Telegram, privacy, uploads, catalog search, outcome-money normalization, and manual retry
  (platform tranche complete), plus comparable identity/intake, market normalization, cost and
  opportunity confirmation, buyer decisions, and the complete owned-product assessment, Sell,
  portfolio, outcome, and estimate-accuracy lifecycle (complete),
- audited self-service data-export/account-deletion request workflow with immutable events,
  read-only Admin visibility, reserved fulfillment state, disabled-by-default evidence-bound
  data-export receipt and account-erasure/tombstone operations, live blockers, file-absence
  verification, and explicit production compliance handoff (complete; archive generation,
  external processor/object cleanup and backup purge remain go-live execution work),
- dependency-readiness endpoint, per-pool queue heartbeats, strict deploy CLI, and localized Admin
  readiness projection (complete; production activation remains in the go-live register),
- secret-free effective production-configuration preflight, trusted-host/proxy enforcement,
  sanitized production environment template, and fail-closed analysis-submission switch (complete;
  real production values/provider evidence remain in the go-live register),
- production deployment-contract verifier plus a bounded MySQL 8.4/Redis 7.4 CI lane that applies
  the real migration ledger, checks cached dependency readiness, and enforces a strict MySQL
  schema/session/index/query compatibility contract outside SQLite (complete; remote workflow
  evidence remains part of each release record),
- deterministic scale fixture, versioned dashboard/operations/tenant-list query budgets,
  stampede-protected dashboard snapshot, guarded read-capacity CLI, and bounded Redis
  queue-throughput/percentile workload plus a release-bound, vendor-neutral saturation/soak
  telemetry verifier (application harness complete; production-shaped staging execution and the
  resulting real Analysis/Sell/browser/infrastructure evidence remain launch work),
- test foundation.

## Phase 2 — Buy Analysis MVP

Duration: 3–5 weeks.

Deliverables:

- listing intake,
- guided Buy listing continuation (complete: one state-derived next action for first analysis,
  existing draft, live processing, required input, completed result, and safe failure recovery;
  three-step responsive progress model in all five languages),
- images,
- AI extraction,
- product matching,
- controlled global catalog ingestion (complete application boundary: private verified-super-admin
  CSV upload, source/license/version provenance, checksum audit, bounded queued processing,
  idempotent canonical creation, fail-closed identity conflicts, and localized row-level operations
  review, plus a five-language read-only explorer for categories, brands, models, variants, market
  applicability, and aliases; approved production datasets remain acquisition work),
- audited operator product-match review queue (complete: verified-super-admin confirmation or
  rejection, existing canonical model/variant selection, optional scoped alias creation,
  immutable idempotent review evidence, downstream Buy recalculation, and five-language Filament
  workflow),
- read-only analysis support explorer (complete: verified-super-admin-only tenant overview,
  relationship-bounded current product/price/risk/deal projections, five-language filters, and
  explicit exclusion of raw request/result/AI/error evidence),
- read-only listing support explorer (complete: verified-super-admin-only global intake overview,
  ISO-minor-unit-aware pricing, bounded relationship counts and filters, and explicit exclusion of
  source URLs, descriptions, seller/location details, notes, and raw input),
- manual comparables,
- price estimate,
- cost calculator,
- risk score,
- deal score,
- bounded queue recovery plus localized Analysis Operations, immutable manual-retry ledger,
  verified-super-admin retry action/CLI, and production runbook (complete),
- independent submission kill switch (complete; false by default in code) and approved non-fake AI/
  product-matching provider activation (pending provider, privacy, evaluation, and cost policy).

## Phase 3 — Sell Analysis MVP

Duration: 2–4 weeks.

Deliverables:

- owned-product intake (complete: tenant-safe API, immutable snapshots, private images, localized
  list/create/detail UI),
- guided Sell continuation (complete: first-incomplete-step coordination across preparation,
  assessment, pricing, listing, portfolio publication, and actual outcome without duplicate API
  requests; one action, four-phase responsive progress, and all five languages),
- owned-product identification and condition assessment (complete: exact snapshot/image
  provenance, canonical matching, append-only results, localized stale-evidence UI),
- Sell comparable evidence and price bands (complete: assessment-bound append-only evidence,
  deterministic native/explicit-normalization selection, three versioned bands, full provenance,
  localized UI),
- sale-speed selection (complete: explicit desired speed plus explicit price strategy),
- listing title (complete: deterministic, source-bound, versioned five-language draft),
- listing description (complete: structured disclosed facts without invented claims),
- photo checklist (complete: deterministic structural readiness and explicit semantic review),
- sale portfolio (complete: current review-complete draft gating, append-only manual publication,
  price/lifecycle history, optimistic concurrency, and localized five-language UI).

## Phase 4 — Outcome tracking

Duration: 2 weeks.

Deliverables:

- purchase records (complete: immutable versions, exact source/reporting money and evidence),
- sale records (complete: exact portfolio-event sold/cancelled/no-sale outcomes),
- actual costs (complete: eight explicit known/unknown categories with dated provenance),
- actual profit (complete: exact complete-chain calculation, duration and explicit unknowns),
- localized outcome workflow (complete: EN/DE/ES/FR/sr-Latn),
- estimate accuracy (complete: explicit immutable attribution, metric-level signed/absolute/bounded
  errors, dated FX provenance, explicit unavailability, and five-language UI).

## Phase 5 — Alerts and subscriptions

Duration: 3–4 weeks.

Deliverables:

- plans and backend saved-search limits (complete),
- immutable tenant-owned saved searches (complete),
- bounded deterministic matching pipeline (complete),
- append-only in-app alert and notification ledger (complete),
- localized saved-search and notification Angular workflows (complete: EN/DE/ES/FR/sr-Latn),
- queued localized email adapter with retry/failure operations (complete),
- secure personal Telegram connection, localized queued adapter, retry/recovery, and read-only
  operations visibility (complete),
- Stripe billing application boundary (complete: Laravel Cashier organization billing, owner-only
  idempotent hosted Checkout and portal, signed webhook projection, fail-closed entitlements,
  read-only operations visibility, and EN/DE/ES/FR/sr-Latn UI),
- Stripe test/live control-plane setup and full lifecycle acceptance evidence (pending; tracked only
  in `docs/19-production-go-live.md`).

## Phase 6 — Approved data connectors

Duration: source-dependent.

Deliverables:

- connector registry (complete: typed normalization contract, capability declaration, compliance
  gate, environment kill switch),
- CSV (complete application boundary: private tenant upload, bounded queued parsing, immutable row
  evidence, idempotency, quarantine/duplicate outcomes, five-language UI and Admin operations),
- email feeds (pending source and mailbox approval),
- partner feeds (pending contracted source),
- approved APIs (pending per-provider approval),
- compliance documentation (CSV production activation and per-source template complete in
  `docs/19-production-go-live.md`; external-source approvals pending).

## Phase 7 — Global market normalization

Duration: provider and policy dependent.

Deliverables:

- explicit Buy comparable cross-country/cross-currency normalization (complete: immutable
  compatibility evidence, dated FX provenance, bounded market factor, explicit landed costs,
  deterministic selector/estimator v2, five-language UI, and read-only operations visibility),
- approved live FX ingestion and provider monitoring (pending external provider approval),
- governed country/category normalization profiles (pending verified datasets and business policy),
- customs and tax calculation sources (pending jurisdictional/legal approval),
- explicit Sell comparable cross-country/cross-currency normalization (complete: independent
  assessment/target-scoped immutable evidence, dated FX provenance, bounded explicit factor and
  landed costs, atomic selector/price-band v2 replay, five-language UI, and read-only operations
  visibility).

## Phase 8 — Broker requests

Duration: 3–5 weeks for the complete commercial brokerage flow.

Delivered foundation:

- tenant-safe sourcing-request list/create/edit/detail API and Angular workflow;
- immutable previous-event-linked history, exact optimistic concurrency, and UUID idempotency;
- draft-only content mutation and subject submit/cancel lifecycle;
- atomic provider-independent `broker_requests.monthly` plan enforcement;
- verified-super-admin review/search/cancel command with required evidence;
- immutable supplier/broker offer aggregate with exact integer-minor-unit cost breakdown;
- atomic offer presentation and subject acceptance against exact request/offer event heads;
- safe multi-currency comparison UI, two-step acceptance, offer cancellation cleanup, and offer
  read-only Admin/CLI operations;
- atomic accepted-offer transaction/commission creation with disclosed exact commission terms;
- evidence-bound payment/order/shipping/delivery/completion lifecycle and independent commission
  settlement ledger, without payment or supplier-provider execution;
- immutable evidence-derived A4 PDF reports in all five locales, private checksum-verified signed
  delivery, bounded retention purge, read-only Admin visibility, and privacy-erasure integration;
- provider-independent refund/dispute case ledger with exact transaction/case heads, strict
  type/outcome/amount rules, safe tenant timelines, read-only Admin/CLI operations, and personal
  erasure blocking, without provider execution or commission mutation;
- bounded single-query lifecycle monitoring with reviewed age/grace thresholds, secret-free
  machine JSON, alerting exit semantics, a five-language Admin attention tile, and full
  application acceptance coverage;
- localized read-only Admin operations resource;
- EN/DE/ES/FR/sr-Latn interface and validation catalogs;
- production activation/rollback procedure and privacy-erasure blocker.

Remaining Phase 8 work:

- approved supplier communication/integration procedures;
- payment-provider execution, actual refund/chargeback submission, commission-remediation rules,
  and externally approved billing policy;
- production-shaped monitoring integration and external staging acceptance evidence for the
  complete brokerage lifecycle.

## Commercial target

A chargeable pilot version should be possible after Phases 1–4.

Expected range for two experienced developers:

```text
10–14 weeks
```

provided the initial scope remains narrow.
