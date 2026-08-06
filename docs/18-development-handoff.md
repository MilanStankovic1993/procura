# 18 - Development Handoff

Last updated: 2026-08-05

This document is the persistent handoff for continuing Procura development on another computer or
in a new Codex task. Read it after the preceding product and architecture documents and verify the
repository before making changes. `docs/19-production-go-live.md` is the separate mandatory source
of truth for environment configuration, external control-plane work, release verification,
monitoring, secret rotation, and rollback.

## 1. Source of truth

```text
Repository: https://github.com/MilanStankovic1993/procura
Default branch: main
Project name: Procura
Current development branch: develop
```

`main` is the protected production-ready branch. `develop` is the long-lived integration branch.
New work uses short-lived `feature/*` or `fix/*` branches from `develop`; release promotion enters
`main` through a green pull request. The complete policy and PR checklist live in
`CONTRIBUTING.md`. Direct development commits, force pushes, and deletion of long-lived branches
are prohibited.

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
- Professional power tools are the initial commercial data niche, not a geographic or architectural limit. The product domain, market selection, reference data, tenancy, pricing context, and connector contracts must support the whole world without hard-coded launch countries.
- Manual listing analysis may accept data from any country.
- Continent is a discovery and grouping filter.
- Country is the primary pricing, marketplace, shipping, customs, tax, and compliance boundary.
- A user will be able to select one or more countries and explicitly enable cross-border search.
- Global architecture does not imply that Procura can automatically search every marketplace.
- Marketplace coverage must come from manual input or explicitly authorized APIs, feeds, imports, and future connectors.
- The application will use a shared-database multi-tenant model with `organization_id` on tenant-owned records.
- Each registered user receives a personal organization and an explicit owner membership
  transactionally.
- The architecture style is a modular monolith.
- Slow work will use queues and must not run directly inside controllers.
- Prices, risk scores, and recommendations must be explainable and reproducible.
- AI must not be the sole authority for price, fraud, tax, customs, or transaction safety.

## 3. Current technical state

Installed and locked foundation:

```text
Laravel Framework 13.21.1
Local web PHP runtime 8.5.8 with Intl; project requirement ^8.3 and ext-intl
Composer 2.10.2
Filament 5.7.3
Laravel Fortify 1.37.3
Laravel Sanctum 4.3.3
Laravel Cashier 16.6.0
Stripe PHP 17.6.0
MoneyPHP 4.9.0
Symfony Intl 7.4.14 with ICU 78.3 reference data
Livewire 4.3.3
Flux 2.15.0
Pest 4.7.5
PHPUnit 12.5.30 (through Pest)
Node.js 24.18.0 LTS and npm 11.16.0 with `frontend/package-lock.json`
Angular 22.0.8 with TypeScript 6.0.3 and RxJS 7.8.2
Angular ESLint 22.1.0 and Vitest 4.1.10
```

`frontend/` is the primary browser application. It uses standalone Angular components, lazy feature
routes, strict TypeScript, SCSS design tokens, a responsive public landing page, the complete Phase 1
authentication experience, an authenticated workspace shell, a backend-authoritative organization
context service, a responsive workspace switcher, an explicit unavailable-workspace recovery
screen, and a foundation dashboard. Runtime localization uses a typed English fallback catalog plus
lazy German, Spanish, French, and Serbian Latin catalogs. It updates document language and route
titles, sends `Accept-Language`, resolves browser BCP 47 variants, and persists the signed-in user's
personal preference through the API without coupling it to organization or analysis market context.
The translated surface now covers the landing and authentication flows, shared shell, dashboard,
organization, market and subscription administration, listing intake/detail, owned-product
intake/list/detail, the complete buy analysis detail flow, and every evidence/decision panel. All
five catalogs implement the same typed key set without development-time fallback, while non-English
catalogs remain lazy chunks. Angular
is now the only first-party browser shell. The transitional Laravel views, Vite/Tailwind pipeline,
auth layouts, and root npm dependency tree have been removed. Named Laravel home, dashboard, and
Fortify GET routes remain as compatibility
redirects to Angular. Filament continues to use its own published assets and Livewire runtime.

Laravel now resolves locale centrally for API and Filament requests. Authenticated
`users.preferred_locale` takes precedence; guests use a supported regional `Accept-Language` value
with `sr`/`sr-*` mapped to `sr-Latn` and unsupported values falling back to English. Standard
FormRequest/Fortify validation, authentication, password-reset, JSON summary, and every current
request-field label have five server catalogs. Responses expose `Content-Language`, and the
middleware always restores the prior application locale after success or failure to protect
persistent PHP runtimes from cross-request language leakage. The six explicit public conflict
families (billing, marketplace import, privacy request, buyer decision, sale portfolio, and outcome
tracking) now use 22 closed `ApiErrorCode` cases and five safe `api_errors.php` catalogs; their
stable response codes are language-neutral and raw exception diagnostics are never rendered.

Filament now shares the same authenticated `users.preferred_locale` contract and is localized
across English, German, Spanish, French, and Serbian Latin. Its user menu exposes a native language
action backed by the existing locale-update application action. Custom resources, navigation,
columns, actions, modals, dashboard metrics, known values, localized country/currency names, and
required vendor accessibility/status strings are covered without modifying `vendor/`.

Phase 2 manual listing intake is now implemented in Angular with lazy list, create, and detail
routes. It includes cursor pagination, search and status filters, exact minor-unit price conversion,
global source/target market selection, retry-safe two-stage record/file submission, private
evidence management, lifecycle updates, immutable snapshot history, empty/loading/error states,
and active-organization reloads.

The first Phase 3 Sell boundary is now implemented separately as tenant-owned `OwnedProduct`
intake. It includes explicit unknown/empty/zero semantics, active category and same-continent
ordered-country validation, draft/ready/terminal-archived lifecycle, immutable actor-attributed
snapshots, private product/serial-label/defect/proof images, dedicated role capabilities, and
localized lazy Angular list/create/detail routes under `/app/sell`. Creation plus image upload is
retry-safe. That intake boundary deliberately does not calculate pricing by itself; the separate
current-assessment price-intelligence boundary described below owns comparable evidence and Sell
price bands. Generated listing copy, sale-portfolio records, and actual purchase/sale money remain
outside both boundaries.

The next Phase 3 boundary is also implemented: each owned-product identification/condition
assessment is append-only and bound to one exact immutable intake snapshot plus a stable hash of
the current private image manifest. It reuses the canonical versioned matcher without silently
creating or choosing catalog data, preserves candidates and language-neutral reason/unknown/action
codes, and returns `ready`, `needs_input`, or `review_required`. Identical evidence replays
idempotently; changed intake or images make the old result historical. The localized owned-product
detail panel exposes confidence, completeness, canonical identity, condition/accessory/defect
evidence, review candidates, verification actions, and stale-evidence warnings in all five
supported languages.

The production-safe Sell comparable-evidence and price-band boundary is now implemented separately
from Buy Analysis. `SellComparableRecord` accepts approved manual evidence only for the exact
current ready assessment and preserves source identity, original minor-unit asking price, market,
timestamps, condition/accessories, reliability, actor, raw input, and stable evidence hashes as
append-only tenant data. Versioned `SellComparableSelection` runs keep every bounded
include/exclude decision and admit another country/currency only through one exact compatible
`SellComparableMarketNormalization`. That independent append-only ledger freezes the current
assessment/comparable/target scope, compatibility, dated immutable FX provenance, bounded explicit
market factor, target-currency shipping/duty/tax/other costs, formula output, actor, evidence,
version, and hash. The shared Sell refresh command atomically recalculates every assessed default
scope plus observed alternate currency scopes. Append-only `SellPriceBand` runs derive quick-sale,
recommended, and ambitious ranges from Q1, Q3, and the weighted median, preserve MAD outliers,
confidence components, completeness, unknowns, actions, exact selected items, normalization
snapshots, and replay inputs without a second FX lookup. The isolated Angular panel supports full
comparable and normalization evidence intake plus current/historical explanation in all five
languages. The API projection is hard-bounded to 25 comparable records and the 10 newest
normalization decisions per record, while current selection remains assessment/target scoped in
SQL rather than inferred from the history window. Filament exposes the complete Sell normalization
ledger read-only. That pricing aggregate still performs no publication and records no actual
money.

The next production-safe Sell boundary is also implemented. `SellListingDraft` requires one exact
current ready matched assessment, one exact latest complete price band, the current private image
manifest, and the user's explicit target price, strategy, country/currency, and listing language.
Versioned first-party EN/DE/ES/FR/sr-Latn templates generate only disclosed facts.
`SellListingDraftFact` preserves each source field/value/disclosure, while
`SellListingPhotoCheckItem` evaluates product overview, angles, resolution, serial label, disclosed
defects, accessories, and private proof exclusion without pretending that image metadata proves
semantic visibility. The full template content hash and normalized photo-policy snapshot/hash are
part of replay evidence, so an unversioned template or policy change also makes prior drafts
historical. Parent and child records are append-only, exact replays are idempotent, stale upstream
versions disappear from current projections, and an out-of-range target price requires a recorded
reason. The isolated localized Angular panel shows copy, photo readiness, source facts, unknowns,
warnings, actions, and immutable history. It does not publish or create a sale portfolio.
The draft aggregate itself still performs neither operation; the portfolio is handled only by the
separate downstream aggregate below.

The Phase 3 sale-portfolio boundary is now implemented as a separate append-only aggregate.
`SalePortfolioEntry` accepts only one exact current `ready` listing draft whose photo evidence is
also `ready`, then snapshots the entire upstream evidence chain and initial asking price.
`SalePortfolioEvent` preserves manual marketplace identity/HTTPS URL, exact advertised
price/currency, occurrence and server recording time, actor, full publication snapshot, reason,
note, idempotency payload, prior event, and monotonic sequence. Server-owned transitions cover
publication, price change, reservation, withdrawal, expiry, and relisting; stale event heads return
`409`, withdrawal requires a reason, and stale source evidence blocks publication/relisting.
The five-language Angular panel exposes current/historical evidence, advertised-price history,
external links, allowed events, and explicit empty choices. No marketplace API, `sold` transition,
actual sale amount, or received-money record exists in this boundary.

The Buy Analysis request and queue boundary is also implemented. It includes tenant-owned ULID
analyses linked to exact listing snapshots, immutable request payloads and hashes, explicit
source/target markets, draft-without-quota behavior, atomic entitlement consumption on submit,
idempotent outbox dispatch, bounded provider attempts, queue-level failure reconciliation, stale
processing-lease recovery, append-only AI attempts, a documented provider interface, and a
deterministic fake extraction provider. Angular exposes recent requests on listing detail and a
separate draft/submit/status/result page with five-second polling for active jobs. Risk, profit,
and deal scoring remain explicitly pending whenever their required upstream evidence is absent;
completed downstream evidence is projected only after its own versioned boundary succeeds.

The canonical product-identification boundary is now implemented. A global ULID catalog separates
categories, brands, models, regional variants, market applicability, and scoped aliases from
tenant-owned analysis data. The pipeline's separate deterministic matcher writes append-only,
versioned `ProductMatch` evidence for the exact AI attempt, preserves bounded candidate snapshots
and reason codes, and returns explicit matched, unmatched, or review-required states. Ambiguous and
region-incompatible evidence never creates or silently selects a product. Authenticated bounded
catalog search/read endpoints are available, and Angular explains the chosen product, confidence,
matcher version, review state, reasons, and candidates. The real development catalog is
intentionally empty until a verified import or audited administration workflow is implemented;
test catalog fixtures are test-only.

The immutable comparable-evidence and deterministic-selection boundary is now implemented.
Tenant-owned `ComparableRecord` rows preserve the approved manual source, canonical model and
optional variant, original integer-minor-unit price, currency, country, source identity, source
timestamps, listing classification, accessories, reliability, raw input, and evidence hashes.
Updates and deletes are blocked at the model boundary. Confirmed matches produce append-only,
idempotent `ComparableSet` runs with a stable input hash, hard-bounded candidate pool, explicit
included/excluded items, rank and basis-point factor scores, reason codes, evidence snapshots,
minimum-count state, and no unproven cross-country or cross-currency mixing. An explicit immutable
`ComparableMarketNormalization` can now confirm or reject one exact cross-market comparable for
one analysis. Compatible evidence preserves dated FX provenance, a bounded analyst-confirmed
market factor, explicit target-currency landed costs, evidence reference/note/attestation, exact
formula results, actor, version, and hash. Angular supports both comparable intake and the localized
normalization workflow; Filament exposes the normalization ledger read-only.
The shared refresh command now holds the analysis and exact product-match locks across selection,
estimate, risk, and projections. Concurrent comparable or normalization writes therefore serialize
on one current evidence snapshot instead of allowing a late stale recalculation to become current.

The immutable exchange-rate and reproducible price-estimation boundary is now implemented. Global
`ExchangeRate` rows preserve exact base/quote decimals, provider/reference, effective,
published, and fetched timestamps, evidence hash, and raw evidence; updates and deletes are
blocked. The calculation-time resolver records identity, direct, or inverse direction and rejects
stale, missing, or not-yet-known rates. `PriceEstimate` and `PriceEstimateItem` are tenant-owned,
append-only, idempotent, hard bounded, and linked to one exact ready comparable set. The v2
algorithm records weighted median, median, Q1/Q3, median absolute deviation, explicit outlier
decisions, dispersion, confidence components, stable input hash, and full snapshots using exact
integer-minor-unit conversion. Cross-market items are admitted only by
`deterministic-comparable-selector:v2` with an exact compatible normalization snapshot;
`deterministic-weighted-median:v2` consumes the already normalized amount without re-resolving FX
or converting twice. Missing/stale rate evidence, incompatible decisions, or mismatched snapshots
fail closed. Angular exposes bands, statistics, confidence, algorithm/rate versions, every input
decision, normalization formula, and conversion provenance. No default market factor, customs/tax
estimate, condition adjustment, transaction price, or live FX source is inferred.

The append-only explainable risk-assessment boundary is now implemented. A bounded deterministic
evaluator consumes the exact listing, canonical-product, comparable-set, price-estimate, and market
scope evidence chain. `RiskAssessment` preserves evaluator version, stable input hash/snapshot, run,
score, exact documented level, confidence components, unknown count, reason codes, and required
verification actions; immutable `RiskSignal` rows preserve every contribution and source.
Recorded asking-price deviation, low-confidence price evidence, and cross-border context may add
points. Unknown seller, location, image, condition, ownership/serial, payment, shipping, and return
facts add zero points while reducing confidence and requiring verification. The tenant-safe API and
separate Angular panel expose all evidence and clearly state that this is transaction uncertainty,
not a fraud verdict.

The explicit cost and expected-profit boundary is now implemented. Tenant-owned `CostInput`,
`CostInputItem`, `ProfitEstimate`, and `ProfitEstimateItem` records preserve the exact current
analysis, price-estimate, and risk-assessment chain with append-only run numbers, stable hashes,
versioned snapshots, ordered line items, confidence components, and reason codes. The API accepts
purchase price plus transport, repair, platform fees, payment fees, customs, tax, other costs, and
safety reserve as bounded integer minor units. Null remains unknown while zero is a known zero.
V1 accepts only the exact price-estimate currency and requires explicit transport, customs, tax,
and regional compatibility for cross-border cases. The deterministic calculator preserves
negative profit, blocks false precision when inputs are unknown, and records gross margin, total
cost, expected net profit, margin, and return on invested capital with exact arithmetic. Immediate
identical submissions are idempotent; any changed input, including a return to an older historical
value, appends a new current run. The tenant-safe Angular panel exposes all inputs, formulas,
confidence, evidence, assumptions, and the estimate disclaimer.

The recorded logistics-simplicity and resale-demand boundary is now implemented. Tenant-owned
`OpportunityInput` and `OpportunityInputItem` evidence links one exact comparable, price, risk,
cost, and profit chain, preserving typed values, required/known state, source, snapshots, stable
hashes, run numbers, and user attribution. `deterministic-logistics-simplicity:v1` scores shipping
method, distance, pickup, transport-cost certainty, tracking, insurance, packaging, and route
readiness as bounded contributions totalling at most 100. `deterministic-resale-demand:v1` scores
comparable breadth, median evidence recency, explicit sold observations normalized by their time
window, and median sale velocity, also totalling at most 100. Asking-price comparables never prove
completed sales. Required unknown evidence produces `needs_input` with a null component score,
while confirmed false and numeric zero remain known facts. Both `OpportunityAssessment` component
runs and all `OpportunityAssessmentItem` contributions are append-only, independently versioned,
idempotent, tenant-safe, and invalidated from current API projections when upstream profit changes.
The isolated Angular panel exposes the inputs, component scores, confidence, contributions,
reasons, and the component disclaimer.

The final explainable DealScore boundary is now implemented. Tenant-owned append-only `DealScore`
and `DealScoreItem` records link the exact current product match, price estimate, risk assessment,
profit estimate, opportunity input, logistics assessment, and demand assessment. The documented
35/25/15/15/10 weights run through `deterministic-deal-score:v1` using integer basis-point and
half-up arithmetic. Net margin is explicitly normalized from 0% to 40%; negative and
above-ceiling raw evidence remains preserved. Critical risk, low price confidence, and unknown
product model produce persisted 40/60/50 cap decisions, with the lowest applicable cap winning.
Missing component scores return `needs_input` and `insufficient_data` without false precision.
Immediate replay is idempotent, changed and reverted component evidence appends runs, and stale
upstream chains are removed from the current projection without deleting history. The isolated
Angular panel exposes capped and uncapped scores, recommendation, all five raw/normalized/weighted
items, confidence, cap decisions, factors, assumptions, next checks, reasons, and disclaimer.

The buyer-decision/status event boundary is now implemented. Tenant-owned append-only
`BuyerDecisionEvent` records link one exact analysis and assessed current DealScore, preserve a
monotonic analysis sequence, previous event, immutable prior/next state, actor, server timestamp,
bounded reason/note, UUID idempotency key, and stable payload hash. Initial state may be
`interested`, `contacted`, `purchased`, `rejected`, or `archived`; every later transition follows
the explicit closed matrix in the functional and domain documentation. The action locks the
membership, analysis, entire current evidence chain, DealScore, and event head, rejects stale
scores, uses `expected_current_event_id` for optimistic concurrency, returns exact duplicate replay
without appending, and rejects idempotency-key reuse with changed payload. A newer DealScore exposes
no current decision while keeping all earlier events in history. The isolated Angular panel uses
only server-returned transitions, retains the same idempotency key across an unchanged failed
network retry, displays actor/score-run history, and states clearly that `purchased` creates no
purchase, payment, inventory, or financial outcome record.

Authentication currently includes:

- registration with normalized lowercase email addresses,
- login and logout through the session-based `web` guard,
- login rate limiting at five attempts per minute per email and IP address,
- passwords requiring at least 12 characters, uppercase, lowercase, a number, and a symbol,
- password reset by email notification,
- signed email verification links,
- password confirmation,
- a dashboard protected by both `auth` and `verified` middleware,
- responsive Angular login, registration, password recovery, reset, email verification, password
  confirmation, and dashboard layouts,
- Angular authentication through versioned API endpoints backed by Fortify and Sanctum's
  cookie/CSRF session flow,
- separate Angular guest, authenticated, and verified `CanMatch` boundaries backed by
  `GET /api/v1/me`,
- authenticated-but-unverified identity hydration while tenant APIs remain protected by
  `verified`,
- local-only return URL validation and strict client-side verification-path validation,
- account-enumeration-resistant password reset request responses,
- a versioned public `GET /api/v1/health` dependency-readiness endpoint with bounded database and
  shared-cache probes, optional fail-closed queue-worker heartbeats, sanitized aggregate output,
  and `503` failure semantics; `/up` remains the separate process-liveness boundary,
- a protected `GET /api/v1/me` API resource with the current organization context and personal
  `preferred_locale`,
- an authenticated `PATCH /api/v1/me/preferences` endpoint supporting `en`, `de`, `es`, `fr`, and
  `sr-Latn` independently from any tenant or market selection,
- credentialed HttpClient requests without browser-stored bearer tokens.

The `User` model implements `MustVerifyEmail`. Fortify enables only registration, password reset, and email verification. Two-factor authentication, passkeys, profile updates, and password settings are intentionally disabled.

The multi-tenant identity foundation now includes:

- ULID `organizations` with `personal` and `business` types,
- a unique `personal_user_id` for one personal organization per user,
- ULID `organization_user` membership records with minimal `owner` and `member` roles,
- unique membership and bidirectional lookup indexes,
- nullable `users.current_organization_id` as the persisted default context,
- transactional, idempotent personal organization creation during registration,
- a chunked, idempotent deployment backfill for pre-existing users,
- UTF-8/Unicode-safe organization names without country, currency, or marketplace assumptions,
- request-scoped active organization resolution that verifies membership before tenant-aware API
  controllers run,
- a constant-query normal resolver path and a transactionally locked recovery path for missing or
  stale pointers,
- an explicit `409 organization_context_unavailable` response when no safe membership exists,
- membership-scoped organization listing and activation endpoints,
- owner/administrator/analyst/viewer/outsider policy boundaries,
- an Angular organization context service and workspace switcher with loading, error, retry, and
  mobile states.

Organization management now includes:

- transactional business-workspace creation and owner membership,
- explicit owner, administrator, analyst, and viewer roles with capability mapping,
- tenant-scoped settings, member, invitation, ownership-transfer, and audit endpoints,
- normalized, hashed, single-use invitations with expiry and pending uniqueness,
- race-safe invitation acceptance and ownership changes,
- last-owner protection and administrator privilege boundaries,
- Angular creation, settings, membership, invitation, role, removal, ownership, and audit controls,
- an authenticated invitation landing page that preserves the invitation URL through login.

Global market reference data now includes:

- seven stable continent groups and all 249 officially assigned ISO 3166-1 countries,
- ISO alpha-2, alpha-3, and numeric country identifiers,
- ICU currency names, symbols, numeric codes, and standard/cash minor units,
- explicit active/inactive currency lifecycle support instead of deleting historical codes,
- ICU source-version tracking and an idempotent chunked `markets:sync-reference` command,
- a public, versioned, ETag-enabled reference endpoint with bounded queries and application cache,
- tenant-scoped home country, reporting currency, locale, IANA timezone, measurement-system,
  cross-border, and ordered multi-country defaults,
- an Angular Markets screen with continent grouping, search, multi-country selection, and
  capability-aware persistence.

Subscription entitlement enforcement now includes:

- stable Free, Starter, Pro, and Business plan codes with versioned plan rows,
- feature-code entitlements independent of Stripe product and price identifiers,
- idempotent seed data matching the documented plan capabilities,
- tenant-scoped plan assignments with Free as the backend default,
- indexed monthly counters and an append-only idempotency event ledger,
- transactionally locked limit checks before paid operations,
- null limits for unlimited Business entitlements instead of magic numbers,
- Laravel Cashier organization customers, subscriptions, and subscription items,
- owner-only, rate-limited and tenant-idempotent hosted Checkout plus billing-portal APIs,
- encrypted expiring Checkout-session replay state with cluster locking,
- one custom rate-limited Stripe webhook that requires configuration and verifies every signature,
- Cashier-first local subscription persistence followed by duplicate/stale-safe entitlement
  projection,
- append-only provider-event evidence with fail-closed unknown-price and non-entitled-state handling,
- manual-assignment isolation and active-subscription conflict detection,
- a protected active-workspace subscription API and five-language Angular Plan & usage screen.

Filament platform administration now includes:

- Filament 5.7.3 at `/admin`, with a locale-aware Procura operations brand,
- a verified `is_super_admin` panel-access boundary with `403` for ordinary tenant users,
- complete EN/DE/ES/FR/sr-Latn resources and dashboard copy, an authenticated personal language
  action, guest browser-locale resolution, and application-owned overrides for upstream Filament
  catalog gaps,
- read-only resources for users, organizations, memberships, plans, entitlements, explicit plan
  assignments, subscription usage, countries, currencies, platform audit events, notification
  deliveries, Telegram connections, safe billing-provider events, marketplace CSV imports, and
  immutable comparable market-normalization, privacy-request, and analysis-retry evidence,
- dashboard metrics for users, organizations, explicit subscriptions, current-month analysis usage,
  active country markets, notification delivery failures, Telegram state, and billing projections
  requiring attention, plus open privacy requests, analyses requiring operator review, and a
  five-language Ready/Core ready/Unavailable operational-readiness projection,
- a validated 30-second shared-cache snapshot for the twelve non-readiness overview counters with
  distributed anti-stampede locking, bounded cold fallback, and no cached readiness decision,
- a transactional, idempotent organization-plan assignment action with a mandatory operational
  reason,
- append-only platform audit events containing administrator, organization, subject, old and new
  values, reason, IP address, user agent, and timestamp,
- stable ULID identifiers for individual plan entitlement records while preserving the documented
  unique plan/feature boundary,
- a one-time `admin:bootstrap-super-admin` command that requires an existing verified user and an
  operational reason, refuses to run after the first super administrator exists, and audits the
  bootstrap.

`users.current_organization_id` remains a convenience pointer rather than an authorization grant.
The request-scoped `OrganizationContext` is authoritative for tenant-aware application services.

Local verification completed for this task:

```text
php artisan migrate:status          passed through batch 46 on MySQL 8.4.3; 49 migrations retained
$env:XDEBUG_MODE='off'; php -d memory_limit=512M vendor/bin/pest --compact
                                    362 passed (4928 assertions) in 180.25 seconds
production preflight targeted       12 passed (348 assertions), including cached-config inspection,
                                    secret-safe JSON, strict warning enforcement, sanitized
                                    production-template/UTC validation, and trusted proxy rejection
deployment/safety targeted          10 passed (32 assertions), including bounded test-database
                                    opt-in and the MySQL/Redis GitHub Actions contract
broker payment-case targeted        17 passed (268 assertions), including payment-head eligibility,
                                    exact replay/conflict, reviewed refund/dispute outcomes,
                                    immutable evidence, safe projections, erasure blocking, and CLI
broker-report regression targeted   5 passed (65 assertions), including real PDF generation,
                                    exact replay, signed tenant-safe download, integrity failure,
                                    expiry purge/privacy blocking, stale-source rejection, and CLI
commission calculator targeted      2 passed (5 assertions), including half-up boundaries and
                                    JavaScript-safe payable overflow
capacity/Admin/Analysis/readiness   53 passed (439 assertions), including a 2,000-row capacity
targeted                            fixture, cache recovery, tenant query bounds, queue dispatch,
                                    exact JSON, catalog parity, and safe rendered projections
performance contracts targeted      15 passed (76 assertions), including 2,000-row `12/1/2` query
                                    budgets plus queue receipt integrity, cleanup, JSON, staging/
                                    production gates, stricter percentile budgets, and timeout
API/Admin localization targeted     33 passed (1454 assertions), including regional browser tags,
                                    authenticated preference, fallback, request-state reset, every
                                    current validator rule/field, 22 typed conflict codes, 179 typed
                                    application-validation codes, safe request-scoped rendering,
                                    migrated-source guards, payment-case Admin access/redaction, and
                                    Filament rendering
platform validation regression      61 passed (835 assertions) across organizations, listings,
targeted                            privacy, monitoring/Telegram, product search, sale outcomes,
                                    and manual analysis retry
Analysis validation regression      26 passed (535 assertions) across comparable identity/intake,
targeted                            market normalization, cost/opportunity confirmation, buyer
                                    decision transitions, concurrency, authorization, and tenancy
OwnedProducts validation regression 37 passed (707 assertions) across intake/assessment, images,
targeted                            Sell comparables/normalization, listing drafts, sale portfolio,
                                    realized outcomes, estimate accuracy, authorization, and tenancy
conflict domain regression targeted 48 passed (741 assertions) across billing, marketplace import,
                                    privacy, buyer decision, sale portfolio, and outcome tracking
vendor/bin/pint --test              passed
npm run lint:frontend               passed
npm run check:i18n                  passed; all application screens use the localization boundary
npm run test:frontend               81 passed across 32 files
npm run build                       passed on Node 24.18 without warnings (419.88 kB initial;
                                    broker-request detail 38.54 kB lazy;
                                    owned-product detail 189.18 kB lazy;
                                    analysis detail 160.84 kB lazy)
composer validate --strict          passed
php artisan optimize                passed, then cache cleared
php artisan schedule:list           passed; analysis, CSV import, email/Telegram recovery,
                                    challenge expiry, and singleton queue heartbeat dispatch run
                                    every minute; expired broker-report artifacts purge daily at
                                    02:30 with overlap/single-server protection
npm audit --prefix frontend --omit=dev --audit-level=high passed; 0 vulnerabilities
optimized runtime                   200 `/up`, 200 API health with database/cache `ok` and queue
                                    monitoring intentionally `not_monitored`, and guest Analysis
                                    Operations 302 to authentication; privacy production gates
                                    verified false and broker requests verified enabled
production serving contract         passed for built Angular output and nginx routing
localized runtime                  422 Serbian validation with `Content-Language: sr-Latn` for
                                    `sr-RS`; unsupported Italian returns English `422`; neither
                                    request creates an account or workspace
```

Current browser QA followed `procura.test` into the restarted local Angular server, rendered the
Serbian-Latin landing page, and opened `/app/owned-products` as a guest. The authentication guard
preserved `/app/owned-products` in the encoded `returnUrl`, rendered the fully localized login
screen, and produced no browser console warnings or errors.

The Buy comparable market-normalization migration completed directly as MySQL batch 36. Schema
inspection confirmed 35 columns, nine foreign keys, the unique normalization key, evidence-hash
index, and bounded analysis/comparable, tenant/status, and target-market indexes. The migration
retained 6 users, 2 organizations, 6 memberships, all prior business rows, and created an empty
normalization ledger as expected.

The Sell comparable market-normalization migration completed as MySQL batch 37. The first attempt
stopped safely on MySQL's 64-character generated foreign-key-name limit after creating only the
new empty table. Row inspection confirmed zero normalization rows while the retained 6 users,
2 organizations, and 6 memberships were unchanged; only that empty partial table was dropped.
The migration now uses explicit bounded constraint names and reapplied cleanly. Schema inspection
confirmed 36 columns, nine foreign keys, the unique normalization key, evidence-hash and exact
assessment/comparable/target indexes, and an empty append-only ledger. No existing business table
or row was removed. The database now retains 40 migration rows, 6 users, 2 organizations, and 6
memberships.

`tests/TestCase.php` now fails before `RefreshDatabase` can run unless the application environment
is exactly `testing`, the default connection is `sqlite`, and its database is `:memory:`. A
deliberately cached local MySQL configuration was used to verify the guard: the test stopped with
zero assertions, `optimize:clear` removed the fixture, and the development database retained 6
users, 2 organizations, and all 38 migration rows.

On 2026-07-26, before that guard existed, one interrupted targeted test inherited a cached local
MySQL configuration and began `migrate:fresh`. The pre-incident state was recovered from the
row-based MySQL binlog up to the exact first destructive event at position `4282399`, first into
the isolated `procura_recovery_20260726` database. All 78 tables passed `mysqlcheck`; every table
had the same row count after restoration to the active `procura` database, including the retained
6 QA users, 2 organizations, 6 memberships, 2 analyses, source listing, and owned product. SQL
copies of both the interrupted state and verified recovery are retained under the ignored
`storage/app/recovery/2026-07-26/` directory. Keep those backups and the recovery database until a
later intentional cleanup.

Runtime verification returned `200` from `procura.test/up`, `procura.test/api/v1/health`, and the
production Angular shell, plus `401` from a guest outcome API request. Browser QA signed
in as the retained Owner, opened the prepared Sell record, rendered the actual
purchase/cost/sale outcome panel with explicit missing evidence, switched it to Serbian Latin, and
reported no console warnings or errors. The Phase 4 accuracy implementation additionally exposes a
bounded server-side historical-estimate search rather than assuming the first 50 candidates are
sufficient. The separately approved Stripe application boundary is now implemented after the
saved-search, in-app, queued email, and Telegram increments. Test/live Stripe control-plane
activation and complete provider lifecycle evidence remain deployment work in
`docs/19-production-go-live.md`.

Phase 5 browser QA then opened the retained Owner workspace through the direct
`/app/saved-searches`, `/app/saved-searches/new`, and `/app/notifications` routes. It verified the
full monitoring form, explicit unknown-evidence boundary, entitled localized-email opt-in, queued
delivery copy, Telegram boundary, the empty inbox, and the Serbian Latin variants of all new
navigation and domain copy. The retained super administrator's
`/admin/notification-deliveries` page rendered the new read-only delivery ledger, recipient,
organization, search, sequence, attempt, and bounded-error columns in Serbian Latin; the dashboard
also exposed the failed-email delivery indicator. Neither surface reported console warnings or
errors.

The completed Telegram increment repeated browser QA with the retained Serbian-Latin Owner and
super-administrator sessions. `/app/notifications` rendered the localized personal-connection
panel, explicit provider-not-configured boundary, encrypted-identifier/revocation copy, and no
console warnings or errors. `/app/saved-searches/new` kept Telegram explicitly opt-in and disabled
while the provider/connection boundary was unavailable, with a translated management link and
independent queue/ledger copy. `/admin/telegram-connections` rendered a translated read-only empty
resource with no ciphertext or credentials; the dashboard showed active Telegram connections plus
failed email and Telegram delivery heads. The Angular dev server was restarted under the documented
Laragon Node 24 runtime before this final check so QA did not inspect a stale development bundle.

The Stripe application increment then opened `/app/subscription` with the retained Serbian-Latin
Owner. The page rendered the current Free plan, authoritative usage, every entitlement, the secure
billing boundary, and an explicit localized not-configured state because no local Stripe secrets or
Price IDs are intentionally present. `/admin/billing-provider-events` rendered the translated
read-only empty provider ledger and all safe operational columns without provider identifiers.
Both browser consoles were free of warnings and errors. Runtime checks returned `200` for `/up`,
`/api/v1/health`, the Angular subscription route, and the production SPA artifact; the protected
admin resource redirected unauthenticated HTTP checks to login. The localized title strategy now
retains the deepest declared title across wrapper routes, with a dedicated English/Serbian
subscription-title regression test.

The Buy Analysis flow was verified end to end at `http://localhost:4200` against MySQL and the
database queue. A temporary verified tenant created a EUR 129.99 AT-to-DE source listing and an
immutable draft without consuming quota. Explicit submission produced one `analyses` dispatch and
changed Free usage to exactly `1 / 5`. A real `queue:work --once` invocation ran one deterministic
provider attempt; Angular polling rendered validated normalized output, 65% confidence, the
`images_insufficient` requirement, and a terminal `needs_input` state while clearly listing price,
risk, profit, and scoring as unexecuted. The temporary user, organization, listing, snapshots,
analysis, dispatch, AI attempt, usage, event, and sessions were removed. Both `jobs` and
`failed_jobs` were empty afterward.

The comparable-evidence flow was verified end to end at `http://127.0.0.1:4200` against MySQL. A
temporary verified tenant processed an exact catalog match with zero comparable records, then added
a EUR 219.50 DE manual source record. Angular displayed the immutable evidence, selector
`deterministic-comparable-selector:v1`, run `#2`, rank `#1`, factor scores, reason codes, and the
correct `1 / 3` insufficient state while price, risk, profit, and deal score remained unexecuted.
The analysis correctly stayed in `needs_input`. Desktop at 1280 px and mobile at 390 x 844 had no
horizontal overflow. Browser QA exposed two Angular `NG0956` warnings when one-element text reason
lists changed; stable index tracking removed them, and a fresh reload produced no new warning or
error. The temporary tenant, listing, catalog fixture, analysis, comparable evidence, selection
runs, sessions, queue jobs, and unique-job cache locks were removed afterward.

The reproducible price-estimation flow was verified end to end at `http://127.0.0.1:4200` against
MySQL batch 15. A temporary verified tenant processed an exact catalog match, appended three
EUR/DE manual comparables at EUR 210, EUR 220, and EUR 230, and reached `completed` through
pipeline v4. Angular rendered the EUR 210/EUR 220/EUR 230 bands, median and weighted median,
Q1/Q3, median absolute deviation, 9.1% dispersion, 72.0% medium confidence, confidence
components, algorithm and resolver versions, identity conversions, original/normalized amounts,
and the explicit no-shipping/tax/customs/condition/profit boundary. The browser console contained
no errors; desktop 1280 px metrics showed document and client widths equal and no price-panel
overflow. Browser QA also exposed an obsolete extraction warning that claimed price had not run;
the provider warning now describes independently recorded downstream evidence and the corrected
copy was reverified. The temporary tenant, listing, catalog fixture, analysis, comparables,
selection runs, price estimate, sessions, and QA servers/files were removed afterward.

The risk-assessment flow was verified end to end at `http://localhost:4200` against MySQL batch 16.
The retained manual QA tenant has one exact catalog match, three EUR/DE comparables at EUR 210,
EUR 220, and EUR 230, a EUR 220 estimate, and a score-5 low-risk assessment with 70.25% medium
confidence, four explicit unknowns, five immutable signals, source evidence, and verification
actions. Pipeline v5, idempotent upstream links, the non-fraud disclaimer, and empty queue/failure
tables were verified. Desktop 1280 px and mobile 390 x 844 had no horizontal overflow and the
browser console contained no warnings or errors. Stable Owner, Administrator, Analyst, Viewer,
platform super-admin, and isolated personal-owner accounts are retained for manual role and tenant
testing; their local-only credentials are stored in ignored
`storage/app/private/manual-qa-accounts.md` and are never committed.

The explicit cost-and-profit flow was verified through the real Angular application against MySQL
using the retained Owner tenant and its existing pipeline-v5 analysis. A complete EUR input
produced EUR 220 expected sale price, EUR 70 gross margin, EUR 177 total cost, EUR 43 expected net
profit, 19.55% margin, 24.29% return on invested capital, and 76.2% high confidence. An immediate
identical submission remained idempotent at run `#1`. Clearing repair cost created run `#2` with
one explicit unknown and no falsely precise total or profit; restoring explicit zero created a new
current run `#3`, proving historical-value reversion is append-only. A full reload preserved run
`#3` and all formula evidence. Desktop 1280 px metrics showed equal client/document width and no
horizontal overflow in the isolated panel.

The logistics-simplicity and resale-demand flow was verified through the real Angular application
against MySQL batch 20 using that retained Owner analysis and its exact current comparable, price,
risk, cost, and profit chain. Parcel delivery over 50 km with confirmed pickup, tracking, insurance,
packaging, six observed sales, a 20-day median, and a 90-day observation window produced separate
scores of 93/100 and 69/100 with every bounded contribution, confidence component, reason code, and
disclaimer visible. A deliberately future observation timestamp was rejected before persistence;
the corrected evidence created immutable run `#1`, and an immediate identical submission remained
at run `#1`. The browser console was clean and the component width equalled its scroll width.

The final DealScore flow was verified through the same real Owner session against MySQL batch 21.
The exact current evidence produced an uncapped and final score of 68/100, a
`potential_opportunity` recommendation, 74.38% medium confidence, no applicable cap, and all five
raw, normalized, weighted, and confidence components under
`deterministic-deal-score:v1`. All three persisted safeguards, increasing/reducing factors,
assumptions, recommended checks, reasons, and the disclaimer were visible in the isolated panel.
An immediate identical submission retained DealScore run `#1`; a fresh API reload moved
`deal_score` into completed steps and left no pending-step heading. At 1280 px, document width
remained below viewport width with no horizontal overflow.

The buyer-decision flow was verified through the retained real Owner session against MySQL batch
22. DealScore run `#1` initially exposed all five documented initial states and no decision. A real
`interested` command recorded event sequence `#1`, actor `Procura QA Owner`, the exact score link,
reason `manual_qa_verified`, server timestamp, and browser-QA note. The response immediately
reduced the available transitions to `contacted`, `purchased`, `rejected`, and `archived`; history
showed `No decision -> Interested`, score run `#1`, and `1 of 1` events. Backend coverage also
verified exact replay, changed-payload idempotency conflict, stale expected event conflict, invalid
transition rejection, old-score rejection after DealScore run change, per-score reset with retained
history, role and tenant boundaries, immutability, and aggregate cascade. Desktop rendering was
contained, the 390 x 844 document had equal client/scroll width, and the browser console contained
no warnings or errors. No purchase/outcome table or money record was introduced.

The localization foundation was verified through the real browser on public and authenticated
routes. English, German, Spanish, French, and Serbian Latin were available; Serbian updated rendered
copy, `<html lang="sr-Latn">`, and route titles. Guest choice survived reload, while the authenticated
user's server preference correctly took precedence and persisted through the preferences API and a
full reload. Browser QA exposed a native-select synchronization edge case after lazy initialization;
explicit option selection fixed it. Desktop and 390 x 844 layouts had no horizontal overflow and the
console was clean. The retained owner account was restored to English after verification.

The Phase 2 manual listing flow was verified end to end at `http://127.0.0.1:4200` against MySQL.
A real registration, email verification, and tenant session created a EUR 129.99 AT-to-DE source
listing, uploaded a 1254 x 1254 PNG to private storage, loaded it through a relative signed URL,
and changed the lifecycle from `active` to `reserved`. The detail page showed two immutable
snapshots. A fresh browser load exposed and then verified the fix for an Angular `NG0203`
`toSignal()` injection-context error; a component regression test now covers construction with
route parameters. At 390 x 844 the detail page had no horizontal overflow, and the final browser
console contained no warnings or errors. The temporary account, organization, listing, snapshots,
session, fixture, and private image were removed after verification.

The first Phase 3 owned-product flow was verified end to end at `http://127.0.0.1:4200` against
MySQL batch 23 with the retained Owner workspace. The browser created a Bosch GSR 18V-55 QA intake
with known age, confirmed accessories, confirmed no defects, explicit purchase context, Europe/RS
scope, and no price or financial record. Draft-to-ready produced snapshot `#2`; an identical replay
kept the history at two snapshots. Browser QA exposed and fixed number-input normalization plus
single-brace translation placeholders; the localization source check now rejects that placeholder
shape. Serbian Latin updated the route title, document language, navigation, domain values,
interpolated counts, and post-save status before the account was restored to English. At 1280 px,
document and client widths matched with no horizontal overflow, and the post-fix console contained
no warnings or errors. The retained record URL is stored with the ignored local QA credentials for
manual Owner/Administrator/Analyst/Viewer checks.

The assessment migration completed directly as MySQL batch 24, including its composite
snapshot/product and variant/model foreign keys. The retained owned-product route returns HTTP 200
from the Angular dev server. Automated assessment API/component coverage and the production build
are clean. Interactive assessment-panel QA remains for the next browser session because the in-app
browser first opened the route before the dev server was listening and its security policy then
blocked reuse of the generated network-error tab; no browser-policy workaround was attempted.

The Sell comparable and price-band migration completed as MySQL batch 25 after a safe recovery from
MySQL's identifier-length boundary. All three partially created Sell tables were confirmed empty,
then only those tables and the two migration-owned composite helper indexes were removed before the
corrected migration was reapplied. Real MySQL now contains all five append-only Sell
price-intelligence tables. Both API routes are registered, the retained Sell route returns HTTP 200
through the Angular development host, and the full backend/frontend/audit suite is green.

The Sell listing-draft migration completed directly as MySQL batch 26. Real MySQL contains the
tenant parent, disclosed-fact children, and photo-checklist children with all composite and
single-column foreign keys. The latest current price band and listing draft are selected in SQL per
bounded market/language scope rather than being inferred from the 25-row history window.

The sale-portfolio migration completed directly as MySQL batch 27. Real MySQL contains immutable
tenant-owned portfolio entries and lifecycle events with bounded foreign-key/index names,
per-product/per-entry sequences, tenant-scoped idempotency, external-listing lookup, advertised
currency provenance, and optimistic event-head links.

Runtime verification also passed at `http://localhost:4200`: a real session preserved the full
invitation return URL through login, rejected an invalid invitation with a field-level message,
created a business workspace, loaded its capability-aware management controls and audit event, and
kept the business workspace selected after a full reload. At a 390 x 844 responsive viewport the
organization screen had no horizontal overflow. Browser console warnings and errors were empty. The
temporary test account and organizations were removed after verification.

The Plan & usage route was also verified with a real session against MySQL. It displayed the
backend-seeded Free v1 plan, the July 2026 UTC usage period, all enabled and disabled entitlements,
and the `0 / 5` monthly analysis allowance. Desktop rendering had no overflow or console errors.
At 390 x 844, the workspace navigation remained contained and the document width stayed below the
viewport. The temporary subscription QA account and personal organization were removed afterward.

The Filament panel was verified through a real MySQL-backed session at `http://procura.test/admin`.
A temporary verified super administrator loaded the operational dashboard, 249-country and
294-currency resources, assigned Pro v1 to a temporary business workspace, and confirmed the
resulting platform audit row contained the administrator, organization, reason, action, subject,
IP address, and timestamp. A 390 x 844 viewport had no horizontal overflow, browser warnings and
errors were empty, and all temporary QA users, organizations, plan assignments, sessions, and audit
events were removed afterward.

The retained QA super administrator was later used to verify the complete admin locale lifecycle.
The real panel changed from English to Serbian Latin through the user-menu modal, localized the
dashboard, navigation, Filament accessibility labels, success notification, Users table columns,
boolean states, and pluralized result count, and preserved `/admin/users` after saving. The account
was originally returned to English after verification. The current Phase 4 handoff repeated this
check against the production-served build, found no browser-console errors, and intentionally left
the QA super-administrator preference on Serbian Latin with the translated Users resource open for
manual inspection.

The complete Angular authentication lifecycle was verified against the local Laravel application.
A real registration created a personal workspace and authenticated session; `/app` redirected the
unverified account to `/verify-email`; the notification's relative signed API path verified the
account and unlocked the tenant workspace. Incorrect and correct password confirmation states were
verified with a protected return URL. Password recovery returned the same generic response used for
unknown accounts, and the emitted reset URL rendered the token-bound Angular completion form. The
final reset mutation is covered by feature tests. At 390 x 844 the shared auth layout had no
horizontal overflow, and browser warnings and errors were empty. The temporary auth QA account,
personal organization, sessions, password-reset token, and related records were removed afterward.

Production serving now has one reproducible output and an explicit web-server contract.
`npm run build:frontend` writes content-hashed Angular assets to ignored `public/spa` output and
automatically runs both `tools/verify-production-serving.mjs` and
`tools/verify-production-deployment.mjs`. The checks verify every referenced bundle, reject public
source maps and un-hashed bundles, and ensure that the nginx example keeps API, Sanctum, Filament,
Flux, fingerprinted Livewire, storage, and health paths out of the SPA fallback. They also lock the
reviewed security headers, TLS boundary, Supervisor pool/queue/timeout/attempt/recycle contract,
Redis `retry_after`, heartbeat queue set, scheduler recovery set, and fail-closed production
template. Target-specific domain, certificate, release path, socket values, and `nginx -t` remain
deployment-environment responsibilities.

The development proxy targets `127.0.0.1` and sets the Laragon virtual host explicitly, so it does
not depend on local `procura.test` DNS resolution. Use the `localhost:4200` browser origin from
`.env.example`; additional explicit origins can be supplied as a comma-separated
`FRONTEND_URLS` allowlist.

Direct local links through `procura.test` are also bounded explicitly. Laravel redirects only the
known Angular application, recovery, verification, invitation, and workspace paths to
`FRONTEND_URL` while preserving their path and query. It does not install a broad fallback that
could swallow unknown API routes, backend prefixes, or mutating requests. Production nginx
continues to serve the same client paths from its internal no-store Angular shell.

GitHub Actions targets PHP 8.3, 8.4, and 8.5 and runs Pest directly with a 512 MB process-only
memory ceiling. The PHP 8.4 job explicitly runs the complete deterministic performance-contract
directory (the 2,000-row capacity/query budget and queue-throughput safety suite), installs the
Angular lockfile, audits production Angular dependencies, lints and tests
Angular, builds `public/spa`, and runs the complete production deployment contract. A separate
bounded job provisions MySQL 8.4 and Redis 7.4, applies every migration, verifies cached Redis/MySQL
readiness, and runs a dedicated strict-MySQL contract for UTC/session SQL mode, `utf8mb4`, InnoDB,
foreign keys, migration completeness, index-name bounds, and the reserved `rank` query. The complete
functional suite remains in the PHP-version SQLite matrix; a local experiment proved duplicating
all tests on MySQL would exceed ten minutes after only the first bounded tranche, so CI uses the
focused compatibility gate instead of an impractical claim. Workflow concurrency cancels
superseded runs, and both jobs have explicit 20-minute limits. Confirm the remote workflow result
after this branch is pushed or opened as a pull request.

## 4. Not implemented yet

The following remain intentionally unimplemented:

- production email delivery configuration,
- production Telegram bot token, username, webhook secret, stable identity-hash key, public HTTPS
  webhook URL/registration, and provider monitoring,
- actual production host provisioning, TLS certificates, and release activation,
- production shared-cache selection, singleton scheduler activation, worker-pool heartbeat
  activation, external readiness monitoring, and controlled staging failure/recovery evidence,
- production-shaped execution of the implemented bounded Redis queue-throughput/percentile
  harness, plus concurrent Analysis/API load, real pipeline capacity, saturation, and soak evidence
  beyond the deterministic dashboard/operations/tenant-list query baseline,
- a real external AI provider and production provider credentials/budgets,
- verified production catalog import/administration and operator match review,
- approved external exchange-rate ingestion, provider monitoring, and retention operations beyond
  the explicit immutable manual recording command,
- authorized email-feed, contracted partner-feed, and approved official-API marketplace
  connectors; CSV is the only implemented non-manual ingest boundary,
- approved automated market-factor/customs/tax profiles; the explicit evidence-bound Buy and Sell
  normalization commands are implemented, but no reusable profile or automated source is active,
- approved production identity verification, retention/legal-hold matrix, secure data-export
  assembly/delivery, external storage/processor/log/analytics/queue cleanup, backup purge,
  evidence repository, and processor inventory; the audited workflow can execute the database
  erasure/tombstone boundary and record verified receipts but deliberately performs none of those
  external assembly, delivery, provider, storage, or backup steps,
- automatic marketplace publication or remote listing mutation,
- payment/refund/dispute execution and supplier communication/integration;
  tenant requests, immutable offers, exact subject acceptance, fulfillment-evidence transactions,
  commission earning/waiver, settlement ledgers, and private evidence-derived PDF reports are
  implemented,
- Stripe test/live products and Prices, tax/payment-method/portal policy, production credentials,
  public webhook registration, lifecycle acceptance evidence, and separately approved live
  activation,
- marketplace automation,
- scraping,
- browser extensions.

Do not assume that a package is installed because it appears in the architecture documents. Verify `composer.json` and installed package versions first.

## 5. Database state

The Laragon development environment uses MySQL 8.4 LTS with a local `procura` database. The ignored `.env` contains the computer-specific connection and a generated application key. The default Laravel, Sanctum, organization, membership, and user-backfill migrations were run successfully.

- `organizations` uses a ULID primary key, unique nullable `personal_user_id`, indexed type, and
  `utf8mb4_unicode_ci`.
- `organization_user` uses a ULID primary key, unique organization/user pair, user-to-organization
  and organization-to-role indexes, and cascading membership foreign keys.
- `users.current_organization_id` is a nullable indexed ULID foreign key that becomes null if its
  organization is deleted.
- `users.preferred_locale` stores the validated personal interface language with English as the
  default. It is independent from organization locale and analysis market scope.
- `plan_features.id` is a required unique ULID used by Filament while the existing plan/feature
  composite primary key remains the entitlement uniqueness boundary.
- `platform_audit_events` stores immutable administrative change context and is indexed by subject,
  organization, administrator, action, and creation time.
- `privacy_requests` stores the nullable subject link, hashed requester identity, type/status,
  unique active subject/type key, current event head, residence/locale, blocker snapshot,
  workflow/privacy-notice versions, request/response/resolution times, and idempotency/payload
  evidence. `privacy_request_events` is its immutable actor-attributed previous-event chain.
- `subscriptions` and `subscription_items` are organization-owned Cashier projections with unique
  Stripe identifiers and cascading organization/subscription foreign keys.
- `billing_checkout_sessions` stores tenant-idempotent hosted-session evidence and an encrypted
  expiring URL; `billing_provider_events` is an append-only deduplicated webhook projection ledger.
- `organization_plan_assignments.source` distinguishes manual from Stripe-owned plan state and
  stores only the provider reconciliation head needed for enforcement.
- `marketplace_sources` stores global connector capability, compliance, coverage, and quality
  metadata. Application sources are the policy-limited `manual` connector and the production
  environment-gated `authorized_csv` connector.
- `marketplace_imports` stores tenant, actor, connector, idempotency, private file metadata/hash,
  authorization attestation, schema/delimiter, processing lease, counters, and sanitized failure
  head. `marketplace_import_rows` is the immutable per-row raw/normalized validation and listing
  outcome ledger.
- `listings` is tenant-owned, uses a ULID, indexes organization/lifecycle/market-route access, and
  scopes external identifiers by organization, connector, and preserved marketplace context.
- `listing_snapshots` stores immutable current facts plus raw event payload and a SHA-256 content
  hash. `listing_images` stores only private storage keys and validated raster metadata.
- `analyses` stores an immutable tenant request anchored to one listing snapshot, explicit market
  scope, pipeline version, request hash, result, workflow timestamps, and bounded retry metadata.
- `analysis_dispatches` is the idempotent outbox/recovery boundary; `ai_analyses` stores append-only
  structured provider attempts, validation, confidence, timing, cost, and errors.
- `product_categories`, `brands`, `product_models`, `product_variants`,
  `product_variant_markets`, and `product_aliases` form the global canonical catalog with normalized
  search fields, active lifecycle, regional attributes, and explicit alias market scope.
- `product_matches` stores tenant-owned append-only evidence linked to an analysis and exact AI
  attempt. Composite foreign keys keep any selected variant attached to its selected model.
- `comparable_records` stores tenant-owned immutable source evidence with deduplication and evidence
  hashes, original money and market facts, normalized classifications, and a canonical model plus
  optional compatible variant.
- `comparable_sets` stores one append-only, versioned, input-hashed selection run for an analysis and
  exact product match. `comparable_set_items` stores every bounded include/exclude decision, rank,
  factor scores, reason codes, and source snapshot.
- `comparable_market_normalizations` stores tenant-owned append-only compatibility decisions and
  exact source/target market facts, dated FX provenance, bounded market factor, target-currency
  landed costs, normalized amount, evidence reference/note, actor, version, hashes, and replay
  snapshot for one analysis/comparable pair.
- `exchange_rates` stores immutable global exact-decimal rate evidence with base/quote currency,
  provider/reference, effective/published/fetched timestamps, raw evidence, and evidence hash.
- `price_estimates` stores tenant-owned append-only algorithm/resolver versions, stable input hash
  and snapshot, exact analysis/comparable-set ownership, target market, weighted-median bands,
  statistics, dispersion, confidence, and reason codes. `price_estimate_items` stores every
  original/normalized amount, rate decision and provenance, weight, inclusion/outlier decision,
  and comparable evidence snapshot.
- `risk_assessments` stores tenant-owned append-only evaluator versions, stable input hashes and
  snapshots, exact upstream evidence links, score/level, confidence, unknowns, reason codes, and
  verification actions. `risk_signals` stores every immutable category/severity contribution,
  evidence snapshot, source, confidence, unknown state, and next check.
- `cost_inputs` and `cost_input_items` store tenant-owned append-only explicit cost confirmations,
  exact upstream price/risk links, known-versus-unknown state, input versions/hashes/snapshots, and
  ordered category evidence.
- `profit_estimates` and `profit_estimate_items` store tenant-owned append-only deterministic
  expected-profit runs, exact formula contributions, confidence, reasons, snapshots, and cascade
  safely with their analysis evidence chain.
- `opportunity_inputs` and `opportunity_input_items` store tenant-owned append-only typed logistics
  and demand evidence with exact comparable/price/risk/cost/profit links, required/known state,
  source attribution, stable hashes, snapshots, and ordered component items.
- `opportunity_assessments` and `opportunity_assessment_items` store independently versioned
  logistics and demand scores, nullable unknown results, confidence, reasons, and every bounded
  source-linked contribution. The entire chain cascades safely with its analysis.
- `deal_scores` and `deal_score_items` store tenant-owned append-only exact-chain final scores,
  capped/uncapped basis points, recommendations, confidence, cap decisions, explanations, and all
  five raw/normalized/weighted components. The records cascade with their analysis evidence chain.
- `buyer_decision_events` stores the tenant-owned append-only buyer workflow stream, exact analysis
  and DealScore links, monotonic sequence, prior/next state, previous-event chain, actor, bounded
  reason/note, optimistic-concurrency head, idempotency key/hash, and server decision timestamp.
  Aggregate deletion cascades safely while individual event mutation/deletion is blocked.
- `owned_products`, ordered targets, snapshots, and images store the tenant-owned Sell intake;
  `owned_product_assessments` stores exact snapshot/image-bound canonical identity and condition
  evidence.
- `sell_comparable_records` stores assessment-bound immutable manual asking-price evidence.
  `sell_comparable_selections` and items store every versioned market/currency include/exclude run.
  `sell_comparable_market_normalizations` stores assessment/comparable/target-scoped append-only
  compatibility decisions, exact original/converted/adjusted/normalized money, immutable dated FX
  provenance, explicit landed costs, reference/note, actor, calculation version, raw evidence, and
  evidence hash.
  `sell_price_bands` and items store all three evidence-derived bands, statistics, confidence,
  unknowns, actions, selected evidence, and outlier decisions.
- `sell_listing_drafts`, disclosed facts, and photo-check items store versioned five-language
  source-bound listing content plus exact template/photo-policy evidence.
- `sale_portfolio_entries` and `sale_portfolio_events` store review-complete intake and immutable
  manual external publication, advertised-price, and lifecycle evidence. They contain no sold
  transition or realized money.
- `actual_purchases` stores product-level versioned purchase money/evidence; `actual_cost_snapshots`
  and `actual_cost_items` store eight ordered known/unknown real-cost categories and their exact
  dated conversions; `actual_sales` stores sold/cancelled/no-sale evidence linked to one exact
  portfolio event.
- `realized_profits` stores only complete-chain same-reporting-currency calculations with exact
  purchase/cost/sale IDs and hashes, signed net profit, basis-point ratios, and sale duration.
- Automated feature tests use an in-memory SQLite database through `phpunit.xml`.
- `tests/TestCase.php` rejects any cached or overridden configuration that would run an automated
  test against a non-testing environment, a non-SQLite connection, or a database other than
  `:memory:`. Run `php artisan optimize:clear` before tests; never weaken this safety boundary.
- Production and primary local development use MySQL 8.4 LTS or a compatible supported successor.
- Local processing uses Laravel's database queue. Independent analysis, connector, and notification
  Supervisor pools plus a once-per-minute cron scheduler contract exist in `deploy/supervisor/`.
  The scheduler can emit short-lived shared-cache heartbeats to all four documented queues, but the
  feature remains off locally and until production activation. Redis, Horizon, external production
  monitoring, and target-host provisioning are not configured yet.

Before creating organization migrations on another computer, confirm its local MySQL database name and credentials. Keep in-memory SQLite available for fast automated tests.

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
npm ci --prefix frontend
npm run build
```

Configure the local MySQL database in `.env` and run:

```powershell
php artisan migrate
php artisan optimize:clear
php -d memory_limit=512M vendor\bin\pest
vendor\bin\pint --test
composer validate --no-check-publish
composer audit
npm run lint:frontend
npm run check:i18n
npm run test:frontend
npm audit --prefix frontend --omit=dev --audit-level=moderate
git status -sb
```

After the intended first administrator has registered and verified their email, and only while no
super administrator exists, run:

```powershell
php artisan admin:bootstrap-super-admin "admin@example.com" --reason="Initial production administrator bootstrap."
```

The command deliberately has no force override. Later administrator-role management must use a
separate authenticated and audited workflow.

For daily development, keep Laragon running and use `npm run dev`. Open
`http://localhost:4200`; the Angular proxy sends `/api` and `/sanctum` to the local Laravel virtual
host.

Run a queue worker in another terminal whenever submitted analyses should process:

```powershell
php artisan queue:work --queue=analyses,default --tries=3 --timeout=60
```

Run `php artisan schedule:work` in a third terminal to exercise pending-dispatch and stale-lease
recovery locally. Production must use the Supervisor and cron contract in `deploy/supervisor/`.

Operational readiness is safe to inspect locally without queue monitoring:

```powershell
php artisan operations:readiness
```

Effective production configuration can be rehearsed locally without pretending it is launch-ready:

```powershell
php artisan operations:production-preflight --allow-non-production --json
```

The command is intentionally blocked by the local HTTP, MySQL identity, Redis TLS, session, private
storage, fake-provider, and mail configuration. In an inactive production release, run it after
`php artisan optimize` without `--allow-non-production`; use `--strict` for the complete advertised
feature scope. Stable output contains no configured secret values. The sanitized starting template
is `deploy/env/procura.production.env.example`; never commit its completed form.

Production and staging release verification must instead use
`php artisan operations:readiness --require-queue-heartbeats --json` after the shared cache,
singleton scheduler, and all worker pools are active. Do not enable the flag locally merely to make
the Admin status green.

The bounded local capacity baseline performs no fixture generation and is safe to run against the
existing development database:

```powershell
php artisan operations:capacity-baseline --enforce-duration --json
```

The strict local MySQL run passed the original eleven-query dashboard budget and one-query
Analysis Operations budget while the optional tenant probe remained explicitly skipped. The same
command also passed after Laravel configuration, route, view, icon, and Filament metadata were
cached.

The tenant Analysis probe is included only when a reviewed local/staging organization ULID is
passed through `--organization`. Production execution is refused without
`--allow-production-read-only`; follow the stricter go-live procedure rather than bypassing it.

The queue-throughput contract can be rehearsed locally with the matching local queue worker
running, but the output is deliberately not launch evidence because local workers do not reproduce
the staging Redis/Supervisor topology:

```powershell
php artisan operations:queue-throughput --queue=analyses --jobs=100 `
  --timeout=60 --acknowledge-load --allow-non-staging --json
```

For release evidence, omit `--allow-non-staging`, run once per configured worker queue in staging,
and follow `docs/19-production-go-live.md`. The command sends only bounded no-op jobs and emits
completion, jobs/second, and p50/p95/p99 latency. It stores no business rows, automatically removes
accepted shared-cache receipts, allows only stricter budget overrides, and has no production
bypass. It proves queue transport and worker scheduling, not Analysis/provider processing.

Do not copy another computer's `.env` or `APP_KEY` through Git. Open the cloned Procura directory itself as the Codex workspace.

## 7. Current implementation boundary

Phase 4 estimate-accuracy reporting is complete:

1. `OutcomeEstimateAttribution` explicitly and immutably links one complete current
   `RealizedProfit` to one exact current Buy `Analysis`/`ProfitEstimate`; it never infers the link
   from names, catalog matches, marketplace IDs, or buyer-workflow state.
2. The transactional command locks and revalidates both realized/estimate chains and both
   attribution/report heads, requires actor/reason/provenance, UUID idempotency and stable hashes,
   and appends corrections without mutating history.
3. `EstimateAccuracyReport` compares purchase, additional costs, sale proceeds and signed net
   profit in the realized reporting currency with exact dated FX provenance or explicit
   unavailable status.
4. Every metric preserves source expected, converted expected, actual, signed/absolute minor error,
   bounded signed/absolute basis-point error, and safe zero-denominator behavior. Cost-category and
   expected-duration mismatches remain explicit.
5. Tenant/role, attribution, idempotency, head-conflict, correction, immutability, currency and
   formula coverage plus EN/DE/ES/FR/sr-Latn API/UI contracts are implemented. No accuracy evidence
   feeds current scoring, price intelligence, AI, or training.

The Phase 5 tenant-owned saved-search and notification foundation is now complete:

1. `SavedSearch` has an immutable owner and terminal archive lifecycle; every criteria/state change
   appends `SavedSearchVersion` with expected-head concurrency, actor/reason, normalized snapshot,
   stable hashes, and UUID idempotency.
2. Backend plan limits and organization roles are authoritative. Owner, administrator, and analyst
   may manage searches; viewers may inspect them; all reads and writes remain active-tenant bounded.
3. `MatchSavedSearch` and `MatchListingSnapshot` use bounded continuation jobs. Listing snapshot and
   product/profit/risk/deal-score evidence changes dispatch reevaluation after commit.
4. `DeterministicSavedSearchMatcher` records exact matched/not-matched/insufficient-evidence
   decisions without cross-currency assumptions, invented distance, or fabricated analysis facts.
5. A match creates one deduplicated recipient `Alert` and an append-only in-app
   `NotificationLog`; read/unread/archive commands require the exact current ledger head and UUID
   idempotency. Archive is terminal.
6. Angular provides localized list/create/edit/detail/history/match-ledger and notification-center
   workflows in EN/DE/ES/FR/sr-Latn. Exact category/brand/model selection uses the canonical
   catalog, and the UI explains evidence boundaries.
7. Email is now an optional plan-entitled saved-search channel. `SendAlertEmail` has a stable unique
   key, after-commit dispatch, four bounded attempts, exponential backoff, timeout, recipient-locale
   content, and an append-only queued/attempting/delivered/failed/exhausted/suppressed ledger.
8. Delivery rechecks verified email, membership, and current entitlement immediately before
   sending. Successful replay is inert; stale uncertain attempts stop instead of risking an
   untracked duplicate; the scheduled recovery command redispatches orphaned retryable heads in
   bounded chunks.
9. The Angular inbox exposes the current email delivery projection. Filament adds a fully
   localized, read-only Notification Deliveries resource with recipient, organization, search,
   channel, event, attempt, timestamp, and bounded failure evidence.
10. Telegram uses a platform-managed bot and a personal connection shared only across that user's
    entitled workspaces. A short-lived one-time `/start` challenge and Telegram identifiers are
    encrypted at rest; dedicated-key HMAC indexes enforce identity ownership without exposing
    plaintext.
11. The secret-authenticated, rate-limited webhook accepts only the same Telegram user/private-chat
    identity. Link replay is inert; connection history is append-only; revocation blocks later
    attempts without deleting audit evidence.
12. `SendAlertTelegram` uses after-commit dispatch, a stable unique key, four bounded attempts,
    localized immutable alert evidence, append-only delivery states, sanitized provider errors,
    stale-attempt protection, and bounded scheduler recovery.
13. The notification center exposes connection/link/revoke controls and email/Telegram delivery
    projections in all five locales. Filament adds a localized read-only Telegram Connections
    resource and active/failed Telegram dashboard indicators without exposing ciphertext or
    credentials.
14. Production operations use an independently scalable `notifications` Supervisor pool. Telegram
    deployment is explicit through `notifications:configure-telegram-webhook`; no real provider
    credentials or network calls are part of the local implementation.

The subject-scoped privacy-request foundation is now complete:

1. Verified users can submit, list, and cancel personal data-export/account-deletion requests
   without an active organization context, with notice attestation, independent throttling, UUID
   idempotency, one active request per type, and bounded history.
2. Each request snapshots workflow/notice versions, locale, optional residence, a 30-day
   operational target, and deterministic account-deletion blockers. Clear-text requester email,
   active-key, payload, and idempotency hashes are never exposed by API or Admin.
3. Request events are immutable, monotonically sequenced, previous-event linked, actor-attributed,
   payload hashed, and optimistic-concurrency protected. Exact replay is inert and stale writes
   fail with `409`.
4. Verified super administrators append review/approval/rejection transitions only through
   `privacy-requests:transition`; the generic action now refuses `fulfilled`. Filament provides a
   localized read-only ledger and open-request dashboard.
5. `privacy-requests:complete-export` is the only data-export terminal operation. It is disabled by
   default and requires an approved exact event head, UUID replay key, configured inventory
   version, identity evidence, private artifact reference/SHA-256/byte size/bounded expiry, secure
   delivery evidence, and reviewed note.
6. Export completion atomically appends the terminal event, immutable `privacy_request_fulfillments`
   receipt, and `privacy_request.export_fulfilled` audit event. Exact replay is inert; changed
   replay conflicts. The subject API and localized Admin projection omit the artifact location,
   checksum, identity reference, payload hash, and idempotency key; the existing event timeline
   retains its bounded delivery receipt reference.
7. `privacy-requests:complete-erasure` is the only account-deletion terminal operation and has an
   independent false-by-default switch/inventory. It requires an approved exact head, identity,
   isolated-run, storage and processor evidence, exact snapshot-clearance set, bounded backup-purge
   deadline, empty live ownership/billing/admin blockers, and verified absence of known personal
   tenant files. It atomically removes the personal tenant and revocable access/preferences,
   anonymizes invitations, detaches business memberships, writes an unverified non-admin user
   tombstone, and records `privacy_request.account_erased`.
8. Exact erasure replay is inert after the tombstone, deadline or configuration changes; changed
   replay conflicts. Retained business/audit rows use the pseudonymous user key. Receipt projections
   expose the backup deadline but never identity/run/storage/processor evidence or clearances.
9. Angular provides the complete request form, notice boundary, blocker/status/history timeline,
   safe fulfillment receipt projection, and two-step cancellation in EN/DE/ES/FR/sr-Latn.
10. The implementation never builds or delivers an export and never infers external object-store,
    processor, log, analytics, queue, or backup erasure. Production legal, retention, identity,
    inventory, delivery, artifact disposal, external erasure evidence, backup restoration handling,
    and processor procedures remain mandatory go-live work.

The Phase 8 broker-request, offer, transaction, commission, report, and payment-case ledgers are now
complete at the provider-independent application boundary:

1. Verified members read only the active organization's requests. Owner, administrator and analyst
   roles can create/update/submit/cancel; viewers remain read-only. Cross-tenant identifiers resolve
   as `404`.
2. Drafts capture bounded product criteria, condition, quantity, optional exact budget/currency,
   target ISO countries, needed-by date and notes. Content updates are allowed only in `draft`.
3. Every create/update/submit/cancel operation appends an immutable full request snapshot with a
   monotonically sequenced previous-event link, exact current-head check, UUID idempotency and
   stable hashes. Exact replay is inert; changed replay and stale heads fail closed.
4. First submission consumes `broker_requests.monthly` inside the same transaction. Free-plan
   exhaustion and the module switch fail closed without appending an event or usage.
5. Verified super administrators use `broker-requests:transition` with an exact head, reason and
   evidence to enter `reviewing`, then `searching`, or cancel. The generic command refuses
   `offers_available`, `accepted`, and `completed`.
6. A verified super administrator presents immutable, evidence-bound supplier terms only through
   `broker-offers:present`, against the exact request head. The server calculates every subtotal,
   total, versioned commission, and customer-payable amount in integer minor units and enforces
   half-up basis-point rounding, safe-integer, currency, validity, country, quantity, and
   bounded-history constraints.
7. Tenant acceptance requires both exact request and selected-offer heads and revalidates the
   immutable commission arithmetic. One database transaction accepts the selected offer, marks
   every alternative `not_selected`, moves the request to `accepted`, and opens one
   `awaiting_payment` transaction plus one `pending` commission with immutable opening events.
   Exact replay is inert; stale, expired, inconsistent-commission, changed-key, viewer, and
   cross-tenant attempts fail closed.
8. Verified super administrators use `broker-transactions:transition` with exact heads, UUID
   replay, bounded reason, and mandatory external evidence for
   `payment_confirmed -> supplier_ordered -> shipped -> delivered -> completed`. Only
   `awaiting_payment` may be cancelled; post-payment exceptions use a separate payment-case ledger
   and never overload the fulfillment state.
9. Completion atomically completes the request and earns the commission; pre-payment cancellation
   atomically cancels the request and waives it. `broker-commissions:settle` separately settles only
   an earned commission with its own exact-head evidence and replay contract.
10. Angular supplies list/create/edit/detail/history, submit/cancel, safe offer comparison, exact
    supplier/commission/payable disclosure, explicit cross-currency warning, two-step acceptance,
    and fulfillment/commission/payment-case timelines in EN/DE/ES/FR/sr-Latn. Filament supplies
    separate localized read-only request, offer, transaction, commission, payment-case, and report
    resources. Subject projections omit private supplier/external-case references, snapshots,
    hashes, idempotency keys, and operator evidence.
11. `broker-reports:generate` accepts only a completed transaction plus earned/settled commission,
    both exact event heads, verified-super-admin reason/evidence, UUID and supported locale. It
    commits one immutable subject-safe snapshot and Dompdf A4 artifact with private
    organization/transaction/ULID path, SHA-256, size, page count and retention deadline. Subject
    API/Angular expose only safe metadata and a short-lived relative signed URL; every download
    repeats tenant policy, status, expiry, size and checksum checks. The daily bounded singleton
    purge deletes the artifact before appending its immutable `purged` event. The localized
    read-only Broker Reports resource omits storage/source/evidence/hash/replay internals. Poppler
    visual QA confirmed both Serbian-Latin A4 pages, diacritics, localized country/status labels,
    clean page breaks and numbering.
12. `broker-payment-cases:open` opens one immutable refund or dispute investigation against an exact
    payment-confirmed-or-later transaction head. `broker-payment-cases:transition` enforces
    `open -> under_review -> resolved` or cancellation, type-compatible reviewed outcomes, exact
    minor-unit amounts, UUID replay, bounded evidence, logical-case uniqueness, and a 100-case
    transaction history cap. It records evidence about an external procedure; it never calls a
    payment provider, refunds funds, files a chargeback, or reverses commission.
13. An active request, available report artifact, or open/under-review payment case in a user's
    personal organization blocks account erasure until lifecycle resolution/purge. The private-file
    absence inventory and both privacy inventory versions are now `v4`. Payment/refund/chargeback
    execution and supplier communication/integration remain explicit later Phase 8 boundaries.
    Transaction/commission/report/payment-case rows are evidence ledgers and never claim that
    Procura handled funds or executed an external operation.
14. `BrokerOperationsMonitor` classifies seven bounded attention signals in one SQL query: aged and
    past-needed-by requests, expired offers, delayed non-terminal transactions, earned commissions,
    overdue report purges, and aged open payment cases. `broker-operations:status --json` is a
    secret-free report contract; `--fail-on-attention` adds alerting exit semantics. The cached
    dashboard exposes the same total in all five Admin locales, production preflight validates the
    reviewed thresholds, and no tenant, supplier, money, storage, payment, evidence, hash, snapshot,
    or replay data enters the output.

The Analysis Operations boundary is now complete and production-gated:

1. One shared query classifies terminal failed analyses, stale processing leases, failed dispatch
   heads, and stale dispatch claims for the Filament resource and dashboard.
2. Verified super administrators can create a manual retry only through
   `RequestManualAnalysisRetry`, used by the localized Admin modal and `analyses:manual-retry`.
   Exact current dispatch, UUID idempotency, a bounded reason, row locks, and total/per-run limits
   are enforced inside the action.
3. Each accepted retry appends one new dispatch, immutable `AnalysisRetryEvent`, and
   `analysis.manual_retry_requested` platform audit event. It preserves the original immutable
   request, cumulative AI attempt history, and the single existing subscription-usage charge.
4. The Admin queue exposes safe localized status/error codes, timing, run/attempt metadata, and the
   last reviewed reason/operator. It never renders raw analysis/dispatch failures or internal
   error/payload/idempotency hashes.
5. `ANALYSIS_MANUAL_RETRY_ENABLED` is false by default. Production activation, monitoring,
   incident disablement, operator procedure, and additive-schema rollback are mandatory in
   `docs/19-production-go-live.md`.

The base operational-readiness boundary is now complete and production-gated:

1. `/up` remains a lightweight PHP liveness check. `GET /api/v1/health` performs bounded database
   and cache probes and returns a sanitized `503` when a required check is unavailable.
2. The singleton scheduler can dispatch one `RecordQueueHeartbeat` job each minute to `analyses`,
   `connectors`, `notifications`, and `default`. The exact worker that consumes real work therefore
   also proves its own processing path.
3. Short-lived shared-cache evidence records dispatch/processing time and latency only.
   Distributed locking prevents older backlog jobs from replacing newer evidence.
4. Missing, stale, future, corrupt, or above-latency evidence fails closed. The public response
   exposes no topology; the local CLI shows exact queue age/latency and has strict deployment exit
   codes plus a one-document JSON mode.
5. Filament renders only a localized Ready/Core ready/Unavailable aggregate in
   EN/DE/ES/FR/sr-Latn. Machine health JSON is intentionally language-neutral.
6. `OPERATIONS_QUEUE_HEARTBEATS_ENABLED` is false by default. Production activation, alerting,
   controlled failure/recovery, temporary incident fallback, and load-balancer routing are
   mandatory in `docs/19-production-go-live.md`.

The production-configuration preflight boundary is now complete:

1. `operations:production-preflight` inspects effective configuration after caching and emits a
   stable table or exactly one secret-free JSON document.
2. Blocking checks cover production/debug/key, same-origin HTTPS/CORS/Sanctum, bounded trusted
   proxies and trusted hosts, strict non-root MySQL, TLS Redis cache/queue, queue timeout/failure
   storage, encrypted secure sessions, private fail-loud S3 disks, mail, analysis providers, and
   broker/privacy dependency consistency.
3. Safe disabled external integrations remain explicit warnings; `--strict` promotes every warning
   to a failed launch decision. Non-production rehearsal requires `--allow-non-production`.
4. `ANALYSIS_SUBMISSION_ENABLED` defaults false in code. With it false, draft submission fails
   before quota consumption, dispatch creation, or provider work; local/test configuration opts in.
5. Laravel host validation is active outside local/testing, proxy trust comes only from explicit
   `TRUSTED_PROXIES`, and catch-all proxy ranges fail preflight.

The current performance/capacity application boundary is now complete:

1. `PlatformOverviewMetrics` owns twelve global non-readiness counts. A cold snapshot is exactly
   twelve queries; a warm validated shared-cache snapshot performs zero database queries.
2. A distributed lock prevents concurrent cold Admin requests from stampeding MySQL. Corrupt or
   unavailable cache state falls back to the cold query path, while live readiness independently
   reports cache health.
3. `AnalysisIndexQuery` is shared by the tenant API and capacity harness, preserving tenant scope,
   deterministic ordering, listing eager loading, filters, and the 50-row hard ceiling.
4. `operations:capacity-baseline` measures the cold dashboard, Analysis Operations count, and an
   optional tenant Analysis page. It always enforces versioned `12/1/2` query budgets and can
   enforce database/wall duration budgets in staging.
5. The CI fixture inserts 2,000 synthetic Analysis rows and proves constant query counts, cache
   reuse, strict JSON, invalid-input rejection, production acknowledgement, and fail-closed budget
   regression.
6. `operations:queue-throughput` sends a bounded set of unique no-op jobs through one configured
   queue and measures completion, jobs/second, and nearest-rank p50/p95/p99 from expiring shared
   cache receipts. Staging requires real Redis queue/cache drivers; production is permanently
   forbidden and optional thresholds may only tighten the versioned baseline.
7. CI proves receipt validation, duplicate/late rejection, cleanup, strict JSON, environment/load
   gates, Redis staging enforcement, timeout failure, and budget tightening with sync/fake drivers.
   CI output is not throughput evidence.
8. Production-shaped staging execution plus full Analysis/API concurrency, real pipeline
   percentiles, saturation, and soak scenarios remain mandatory in
   `docs/19-production-go-live.md`.

Do not expand payment processing beyond the reviewed Stripe hosted-subscription boundary, or add
escrow, marketplace mutations, scraping, external AI credentials, browser extensions, or
outcome-driven model training without a separately approved boundary.

## 8. Phase 1 sequence after authentication

```text
personal organizations and memberships (complete)
-> active organization context, switching, and base organization policies (complete)
-> organization management, invitations, audit, and broader tenant-aware policies (complete)
-> global country and currency reference data
   (complete)
-> plans, plan features, and subscription usage (complete)
-> Filament administration (complete)
-> migrate the remaining auth screens to Angular (complete)
-> production Angular serving and deployment boundary (complete)
-> database/cache readiness, per-pool worker heartbeats, deploy CLI, and localized Admin status
   (complete; production heartbeat switch off)
-> effective production-config preflight, trusted host/proxy boundary, and analysis submission kill
   switch (complete; external provider and production values pending)
-> deterministic capacity fixture, dashboard snapshot, `12/1/2` query budgets, guarded read CLI,
   and bounded Redis queue throughput/p50/p95/p99 harness (application complete; staging execution,
   full Analysis/API load, saturation, and soak evidence pending)
-> central five-language API/Fortify validation and request-locale isolation (complete)
-> typed five-language API domain-conflict presentation and raw-message exclusion (complete)
-> Phase 2 manual listing intake foundation (complete)
-> Buy Analysis request and queue boundary (complete)
-> audited Analysis Operations and manual-retry boundary (complete; production kill switch off)
-> canonical product identification and matching boundary (complete)
-> immutable comparable records and deterministic selection boundary (complete)
-> exchange-rate provenance and reproducible price-estimation boundary (complete)
-> explicit Buy cross-market comparable normalization boundary (complete)
-> explicit Sell cross-market comparable normalization boundary (complete)
-> explainable risk-assessment evidence boundary (complete)
-> explicit cost and expected-profit calculation boundary (complete)
-> recorded logistics-simplicity and resale-demand evidence boundary (complete)
-> explainable final deal-score boundary (complete)
-> buyer decision/status event boundary (complete)
-> Phase 3 owned-product intake boundary (complete)
-> owned-product identification and condition-assessment boundary (complete)
-> Sell comparable-evidence and price-band boundary (complete)
-> Sell listing-content draft and photo-readiness boundary (complete)
-> sale-portfolio and manual publication-record boundary (complete)
-> Phase 4 transaction-outcome foundation (complete)
-> immutable estimate-accuracy reporting (complete)
-> Phase 5 saved-search and in-app alert foundation (complete)
-> Phase 5 queued localized email delivery and operations boundary (complete)
-> secure Telegram connection and localized delivery boundary (complete)
-> Stripe billing application and subscription lifecycle boundary (complete)
-> Stripe test/live control-plane activation and full lifecycle evidence (production register)
-> Phase 6 connector registry and authorized CSV ingest boundary (complete)
-> Phase 6 email, contracted partner-feed, and approved API connectors (source-dependent)
-> Phase 7 explicit Buy/Sell market normalization boundary (complete)
-> Phase 7 approved live FX/profiles/customs sources (provider/policy-dependent)
-> Phase 8 broker/sourcing request foundation (complete)
-> Phase 8 immutable offers and subject acceptance (complete)
-> Phase 8 transaction, fulfillment-evidence, and commission ledgers (complete)
-> Phase 8 evidence-derived PDF reports and secure retention-bound delivery (complete)
-> Phase 8 provider-independent refund/dispute investigation ledger (complete)
-> Phase 8 bounded broker lifecycle monitoring and application acceptance evidence (complete)
-> Phase 8 payment/refund/chargeback execution and supplier integrations (provider/policy-dependent)
```

## 9. Known non-blocking notes

- Fortify 1.37.3 installs the passkey/WebAuthn dependency tree, but passkey features remain disabled and outside the current phase.
- The organization deployment backfill processes users in chunks of 500 and is idempotent. Its
  `down()` intentionally preserves ownership data on a single-step rollback; rolling back the
  preceding schema migration removes the new tables and pointer.
- The Angular 22.0.8 CLI development dependency currently reports a moderate Windows path traversal advisory through its MCP SDK and `@hono/node-server` 1.x. npm's suggested fix downgrades Angular CLI to 21, so keep Angular 22 and upgrade when the Angular CLI publishes a compatible patch. The production-only audit completed again on 2026-07-29 with zero vulnerabilities, confirming that this advisory is absent from the production dependency tree.
- The 8 kB warning and 12 kB error component-style budgets remain active. The listing detail page is
  currently 7.13 kB. Price-estimate and risk-assessment rendering are isolated in their own
  components rather than increasing the analysis-detail budget, and the production build completes
  without warnings.
- The first-party Angular surface is localized end to end in English, German, Spanish, French, and
  Serbian Latin, including organization, markets, subscription, listing, owned-product intake, and
  owned-product assessment, Sell price intelligence, and Sell listing-draft/photo-readiness, plus
  complete buy-analysis domain screens, saved-search management, match evidence, and the in-app
  notification inbox.
  Every catalog currently implements the same 2189-key
  `TranslationDictionary`;
  missing keys fail TypeScript compilation. `npm run check:i18n` also rejects common hard-coded
  application copy.
  Laravel now resolves every API request through the same authenticated-preference/guest-header
  boundary as Filament. Standard FormRequest/Fortify validation, authentication, password-reset,
  JSON summary, and all current field attributes have EN/DE/ES/FR/sr-Latn server catalogs.
  Regional browser tags, Serbian `sr-*` mapping, unsupported-language fallback,
  `Content-Language`, and request-state reset are regression tested. All 22 codes across the six
  explicit API conflict exception families are enum-backed, have exact five-catalog parity, retain
  the same language-neutral response code, and render no raw diagnostic. The first application
  validation platform, Analysis, OwnedProducts, privacy, broker-request, broker-offer,
  broker-transaction, broker-commission, broker-report, and broker-payment-case tranches are
  complete: 179
  `ApplicationValidationCode` cases and exact
  EN/DE/ES/FR/sr-Latn `application_validation.php` catalogs now cover organizations,
  monitoring/notifications, Telegram, privacy, listing uploads, product search, outcome-money
  normalization, manual analysis retry, comparable identity/intake, cross-market normalization,
  cost and opportunity confirmation, buyer decisions, owned-product intake/assessment and images,
  Sell evidence/listing/portfolio lifecycles, realized outcomes, and estimate accuracy while
  preserving field-keyed `422` responses. A source contract prevents all 34 migrated
  services/controllers from returning to embedded English validation strings. No direct
  `ValidationException::withMessages` call remains in `app/Actions/OwnedProducts`; stored evidence
  identifiers remain language-neutral.
- The separate Filament surface now follows the same five authenticated personal locales. Its
  server catalogs have an exact shared key contract, and application-owned translation overrides
  cover known missing Filament strings for German, French, and Serbian Latin. Keep those overrides
  during Filament upgrades until the upstream catalogs provide equivalent keys.
- German, Spanish, French, and Serbian Latin catalogs remain lazy chunks and do not increase the
  initial JavaScript bundle with every translation. Complete domain catalogs are currently
  143.91–152.70 kB raw per lazy chunk. No localization dependency was added.
- npm install scripts are explicitly approved and version-pinned in `frontend/package.json` for esbuild, Parcel watcher, lmdb, and msgpackr extraction.
- The first auth package install encountered an incomplete copied `vendor` directory. The generated directory was safely rebuilt with a clean Composer dist install; no repository file or user change was removed.
- Local email delivery is not a production mail service. Registration, reset, verification, and
  saved-search alert notifications are covered with Laravel notification/provider fakes; generated
  Angular URLs were additionally verified through the local log mailer during browser QA. A
  production mail provider, credentials, sender-domain authentication, bounce handling, and
  provider-level idempotency remain deployment work.
- Telegram is implemented behind a provider contract, but the local environment intentionally has
  no real bot token, webhook secret, identity-hash key, or public HTTPS webhook. Automated coverage
  uses HTTP/provider fakes. Production must supply stable secrets, register the webhook explicitly,
  protect provider request logging, and monitor rate limits, latency, queue depth, and failed heads.
- Stripe is implemented behind Laravel Cashier and an application provider contract, but local
  Checkout remains intentionally disabled with no real keys, webhook secret, Price IDs, or display
  amounts. Automated coverage uses a fake provider and generated valid/invalid signatures. Follow
  `docs/19-production-go-live.md` for test-mode control-plane setup and never enable live Checkout
  from code alone.
- GitHub CLI authentication is local to each computer and must include permission to update workflow files before pushing CI changes.
- Windows has an older system Node on `PATH`; use the Laragon Node 24.18.0 runtime documented above
  when running Angular CLI 22. The Angular development server runs from
  `C:\laragon\www\procura\frontend` on port 4200.
- The first real MySQL attempt for the listing migration exposed a generated index identifier over
  MySQL's 64-character limit. The two empty partial tables were verified, removed, the index was
  given the explicit name `listings_market_route_index`, and the migration then completed on MySQL
  8.4.3.
- The first real MySQL attempt for the opportunity-assessment migration exposed two generated unique
  index identifiers over MySQL's 64-character limit. All four newly created partial tables were
  verified to contain zero rows, removed in reverse dependency order, the indexes were given
  explicit bounded names, and the migration then completed as batch 20 without removing user data.
- The first real MySQL attempt for the owned-product migration exposed generated target-country
  unique/index names over MySQL's 64-character limit. The two partial tables were verified to
  contain zero rows, removed in reverse dependency order, both indexes received explicit bounded
  names, and the migration then completed as batch 23 without removing user data.
- SQLite accepted `rank` unquoted in the comparable-item relationship ordering, while MySQL 8.4.3
  treats it as a window-function keyword. Real MySQL QA exposed the difference; the relationship now
  qualifies and quotes the column explicitly.
- A disposable local `procura_ci_codex` database was created only after proving the name did not
  exist, and was removed after each attempt. No SQL assertion failed, but the Windows MySQL host
  spent 20â€“55 seconds on several large DDL migrations and a clean ledger reached only its midpoint
  before the five-minute diagnostic limit. The CI contract therefore performs one explicit
  `migrate:fresh` followed by a read-only integration check without `RefreshDatabase`; the required
  Linux MySQL/Redis workflow result remains the authoritative release evidence after push.
- Replacing one-element comparable reason arrays initially produced Angular `NG0956` development
  warnings because text values were tracked by identity. The short ordered text lists now track by
  index; the full component suite, production build, and post-fix browser reload are clean.
- A slow parallel validation run crossed a one-second boundary between initial and duplicate
  comparable fixture timestamps, correctly producing a new evidence record and exposing a flaky
  test. The test now derives both payloads from one fixed base timestamp. That checkpoint passed
  163 tests and 1139 assertions; the current suite passes 362 tests and 4928 assertions after the
  later Sell, outcome, monitoring, billing, connector, normalization, privacy, Analysis
  Operations, operational-readiness, deterministic capacity/queue throughput, server/API
  localization, typed
  platform-validation, privacy fulfillment/erasure, broker workflow/report/payment-case, and
  production-preflight boundaries.
- The local Wamp PHP CLI loads Xdebug in `develop` mode and defaults to a 128 MB memory limit.
  Repeated bare `php artisan test` attempts exhausted that local profile while Pest retained a
  large historical result cache; no assertion failed. The documented CI-equivalent command
  (`php -d xdebug.mode=off -d memory_limit=512M vendor/bin/pest`) passed the then-current 304-test
  checkpoint. The current 350-test suite also passes with the documented 512 MB boundary. Keep
  using that runner instead of treating the machine-specific 128 MB/Xdebug profile as the project
  test contract.
- The DealScore migration uses explicit bounded index names and completed directly as MySQL batch
  21. Its evaluator is framework-independent, uses integer basis-point arithmetic and preserves
  every exact source snapshot; the recording action locks the entire immutable analysis, product,
  price/risk/profit, logistics, and demand evidence chain before idempotent persistence.
- The buyer-decision migration also uses explicit bounded index names and completed directly as
  MySQL batch 22. The event action holds the analysis and evidence-chain locks used by upstream
  recalculation, so a concurrent newer score or event cannot accept a stale browser command.
- The owned-product assessment migration uses bounded index names and completed directly as MySQL
  batch 24. Real MySQL confirmed the composite snapshot/product and nullable variant/model foreign
  keys, tenant/status and provenance indexes, and append-only run uniqueness.
- The first real MySQL attempt for the Sell price-intelligence migration reached the
  64-character limit on an automatically generated foreign-key name after creating three empty
  partial tables. Their zero-row state was verified before removing only those tables and the two
  new migration-owned composite helper indexes. All long foreign keys now have explicit bounded
  names, and the migration completed as batch 25 with all five Sell tables and no user-data loss.
- The Sell listing-draft migration used explicit bounded foreign-key and index names from its first
  MySQL run and completed directly as batch 26. Schema inspection confirmed 38 parent columns, 12
  disclosed-fact columns, 15 photo-check columns, and all eight table-level foreign keys.
- The sale-portfolio migration used explicit bounded foreign-key and index names and completed
  directly as MySQL batch 27. Entry and event rows are append-only; asking/advertised prices remain
  operational evidence and are not actual sale proceeds.
- Profit calculation v1 intentionally accepts only the exact price-estimate currency. Buy
  cross-market normalization occurs upstream and produces a target-currency estimate with dated
  evidence; the profit input boundary still performs no independent currency conversion.
- The local `procura.test` host entry and Apache virtual host were valid, but Windows retained a
  negative DNS result. `ipconfig /flushdns` restored `procura.test -> 127.0.0.1`. The Angular
  development server is launched with Laragon Node 24.18.0 because the older system Node does not
  satisfy Angular CLI 22. A bounded Laravel browser-route adapter now also makes direct
  `procura.test/app/...` bookmarks resolve to the configured development frontend without changing
  API/admin routing.
- The Telegram identity-uniqueness migration preflight initially used a grouped
  `exists(select *)`, which MySQL correctly rejected under `ONLY_FULL_GROUP_BY` before any index
  mutation. It now selects only the grouped HMAC column, installs nullable unique user/chat
  indexes, and completed as batch 33 while retaining 6 users and 2 organizations.
- The privacy-request migration completed directly as MySQL batch 38. Schema inspection confirmed
  21 request columns, 15 event columns, the request/current-event and event/previous-event chains,
  subject/actor `SET NULL`, parent-event cascade rules, unique active/idempotency/sequence
  constraints, and response-target indexes. Both new tables started with zero rows while the
  existing 6 users, 2 organizations, and 6 memberships remained unchanged; the migration ledger
  now contains 41 rows.
- The analysis-retry migration completed directly as MySQL batch 39. Schema inspection confirmed
  16 retry-event columns, composite analysis/dispatch tenant foreign keys, actor `SET NULL`,
  parent/dispatch cascade rules, unique analysis/run, analysis/idempotency and new-dispatch
  constraints, bounded helper-index names, and requested/hash indexes. The new table started with
  zero rows while the existing 6 users, 2 organizations, 6 memberships, 2 analyses, 1 dispatch, and
  1 subscription-usage row remained unchanged; the migration ledger then contained 42 rows.
- The privacy-fulfillment migration completed directly as MySQL batch 40. Schema inspection
  confirmed 18 InnoDB columns, request/event/actor foreign keys, request/event/idempotency unique
  constraints, type/completion and payload-hash indexes, actor `SET NULL`, completion-event
  `RESTRICT`, and request `CASCADE`. The migration is additive and the fulfillment kill switch
  remains false by default; the migration ledger now contains 43 rows.
- The account-erasure extension completed directly as MySQL batch 41. Schema inspection confirmed
  the indexed nullable user tombstone time, request-linked `RESTRICT` erasure reference, and four
  nullable evidence/deadline receipt fields (22 receipt columns total). Both erasure versions are
  explicit, `PRIVACY_ERASURE_ENABLED` remains false, and the configured backup maximum is 90 days.
- The broker-request migration completed directly as MySQL batch 42. Schema inspection confirmed
  the 22-column InnoDB request projection, 17-column immutable event ledger, current/previous-event
  foreign keys, requester restriction, actor `SET NULL`, tenant/idempotency and request/sequence
  unique constraints, bounded tenant/status/needed-by/type/hash indexes, and currency/category
  references. Both tables started empty and the migration ledger contains 45 rows.
- The broker-offer migration completed directly as MySQL batch 43. Schema inspection confirmed the
  30-column immutable offer projection, 18-column offer-event ledger, composite tenant/request/source
  constraints, current/previous-event chains, exact currency and country references, and bounded
  event, idempotency, sequence, status, and expiry indexes. Offer writes remain disabled by default
  in production through `BROKER_OFFERS_ENABLED=false`; the local migration ledger contains 46 rows.
- The broker-transaction migration completed directly as MySQL batch 44. It adds five immutable
  commission-disclosure fields to offers and creates the 23-column transaction projection,
  19-column immutable transaction-event ledger, 20-column commission projection, and 20-column
  immutable commission-event ledger. Composite tenant/request/offer/transaction/source-event
  foreign keys, one-to-one request/offer/transaction constraints, exact sequence/idempotency
  uniqueness, current/previous-event chains, actor `SET NULL`, and status/type/hash indexes are in
  place. Transaction writes remain false by default through
  `BROKER_TRANSACTIONS_ENABLED=false`; the local migration ledger contains 47 rows.
- The broker-report migration completed directly as MySQL batch 45. It creates the immutable report
  projection/event ledger, exact transaction/commission/source-event composite foreign keys,
  current/previous event chain, transaction-sequence/idempotency/logical-source uniqueness,
  private artifact integrity/retention metadata, and bounded status/expiry/hash indexes. Report
  generation remains false by default through `BROKER_REPORTS_ENABLED=false`; purge scheduling is
  deliberately independent of that switch. Local development enables the switch for verification,
  and the local migration ledger contains 48 rows.
- The broker payment-case migration completed as MySQL batch 46 after its interrupted first attempt
  left only two verified-empty partial tables. Those tables were removed in reverse dependency
  order and the unchanged migration then completed normally, without touching user data. It creates
  the immutable refund/dispute projection and event ledger with exact transaction/request/offer/
  tenant/source-event chains, logical-case and transaction-scoped idempotency uniqueness, bounded
  sequence/status/type indexes, and immutable model guards. Writes remain false by default through
  `BROKER_PAYMENT_CASES_ENABLED=false`; the local migration ledger contains 49 rows.

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
