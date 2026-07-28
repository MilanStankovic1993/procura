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
  opportunity confirmation, and buyer decisions (Analysis tranche complete; owned-product domain
  actions remain),
- audited self-service data-export/account-deletion request workflow with immutable events,
  read-only Admin visibility, and explicit production compliance handoff,
- dependency-readiness endpoint, per-pool queue heartbeats, strict deploy CLI, and localized Admin
  readiness projection (complete; production activation remains in the go-live register),
- deterministic scale fixture, versioned dashboard/operations/tenant-list query budgets,
  stampede-protected dashboard snapshot, and guarded staging capacity CLI (complete first
  baseline; concurrent load/soak scenarios remain launch work),
- test foundation.

## Phase 2 — Buy Analysis MVP

Duration: 3–5 weeks.

Deliverables:

- listing intake,
- images,
- AI extraction,
- product matching,
- manual comparables,
- price estimate,
- cost calculator,
- risk score,
- deal score,
- bounded queue recovery plus localized Analysis Operations, immutable manual-retry ledger,
  verified-super-admin retry action/CLI, and production runbook (complete).

## Phase 3 — Sell Analysis MVP

Duration: 2–4 weeks.

Deliverables:

- owned-product intake (complete: tenant-safe API, immutable snapshots, private images, localized
  list/create/detail UI),
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

## Commercial target

A chargeable pilot version should be possible after Phases 1–4.

Expected range for two experienced developers:

```text
10–14 weeks
```

provided the initial scope remains narrow.
