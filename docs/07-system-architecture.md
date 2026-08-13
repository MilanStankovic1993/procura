# 07 — System Architecture

## 1. Stack

- Laravel 13
- PHP 8.3+ with the Intl extension
- MySQL 8+
- Redis
- Laravel Horizon
- Laravel Scheduler
- Laravel Sanctum
- Angular 22 standalone application
- TypeScript 6 with strict compiler settings
- Angular Router, HttpClient, Signals, Vitest, and Angular ESLint
- Filament v5
- Livewire for Filament administration only
- SCSS design tokens for the Angular application
- Laravel Cashier
- Pest
- S3-compatible production storage

## 2. Architecture style

Use a modular monolith for MVP.

Reasons:

- faster delivery,
- simpler deployment,
- easier transactions,
- lower operations cost,
- appropriate for early-stage product.

Modules must remain internally separated through:

- contracts,
- services,
- DTOs,
- events,
- policies,
- queues.

## 3. Application layers

```text
Angular SPA / versioned JSON API / Filament administration
        ↓
Application services
        ↓
Domain services and policies
        ↓
Repositories or Eloquent models
        ↓
Database and external providers
```

## 3.1 Global market foundation

Global reference data must use stable standards:

- ISO 3166-1 alpha-2 for countries,
- ISO 4217 for currencies,
- BCP 47 for language tags,
- IANA identifiers for time zones.

Market context must be passed explicitly to pricing, comparable selection, shipping, customs, and tax services. The application must not infer a target market only from the user locale.

Reference data is synchronized from the locked Symfony Intl ICU dataset into indexed local tables.
The public reference API is versioned, ETag-enabled, application-cached, and excludes inactive
records without deleting historical identifiers. Organization market defaults use foreign keys and
a normalized ordered country-selection table; they are defaults only, never implicit analysis
scope.

## 3.2 Multi-tenant foundation

Procura uses one shared database. Tenant-owned records will carry an indexed `organization_id`;
separate databases per customer are not part of the architecture.

Identity and tenancy currently use:

```text
organizations              ULID primary key, personal or business type
organization_user          explicit ULID membership with owner/administrator/analyst/viewer role
users.current_organization_id
```

Database constraints guarantee one personal organization per user and one membership per
organization/user pair. Registration creates the user, personal organization, owner membership,
and initial organization context inside one transaction. A chunked, idempotent deployment
migration backfills accounts that existed before the organization schema.

`current_organization_id` is a convenience pointer, not an authorization grant. The active
organization middleware verifies membership on every tenant-aware authenticated request and stores
the resolved membership plus organization in a request-scoped context. The normal path performs a
constant number of indexed queries. A missing or stale pointer is repaired transactionally by
falling back to the user's oldest membership; an account with no membership receives an explicit
`409 organization_context_unavailable` response and no tenant data is opened.

Organization activation is membership-scoped and locks the user row while changing the persisted
pointer. A forged organization identifier returns `404` without disclosing that another tenant
exists. Policies distinguish members, owners, and outsiders; future tenant-owned services must take
the verified request context as input rather than accept an organization ID from untrusted client
state.

## 3.3 Subscription entitlement and billing foundation

Subscription enforcement is provider-independent. Stable plan and feature codes are stored
separately from Stripe product and price identifiers. Plan rows are versioned, and
organization assignments reference a specific plan version.

`SubscriptionUsageService` is the supported path for consuming monthly quota. It locks the indexed
monthly counter, checks the backend entitlement, increments inside the same transaction, and records
a tenant-scoped idempotency key. Duplicate job delivery cannot consume quota twice. A null limit
means unlimited; disabled features remain explicit configuration rows.

The tenant-scoped subscription API exposes authoritative entitlement/usage state plus a bounded
billing projection. Laravel Cashier 16 owns the local organization subscription and item schema;
the application disables Cashier's default webhook route and exposes exactly
`POST /api/v1/integrations/stripe/webhook`. That route is rate-limited, refuses to operate without a
signing secret, and verifies the `Stripe-Signature` before Cashier or application listeners run.

Only organization owners may create hosted Checkout or billing-portal sessions. Checkout accepts
internal plan and interval enums, resolves the exact Stripe Price ID server-side, and uses a
tenant-scoped idempotency key plus a short cluster lock. Repeated requests return the same encrypted
expiring URL; no card data enters Procura.

After Cashier persists a supported subscription event, `SynchronizeStripeEntitlements` calls the
transactional projector. The append-only provider-event ledger makes duplicate delivery inert and
orders events by provider occurrence time plus lifecycle precedence. Only `active` and `trialing`
are entitled by default. Unknown/multiple prices, missing plan versions, non-entitled states,
manual-assignment conflicts, and simultaneous active-subscription conflicts fail closed and remain
visible to operations without exposing provider secrets or full payloads.

## 3.4 Platform administration foundation

The internal `/admin` panel uses Filament v5 and the existing Laravel session guard. Access is
allowed only to verified users with the explicit `users.is_super_admin` flag; ordinary tenant users
receive `403` and cannot infer administrative data.

Phase 1 resources expose read-only operational views for users, organizations, memberships, plans,
entitlements, explicit plan assignments, usage counters, countries, currencies, and platform audit
events. The two controlled Admin mutations are organization plan assignment and terminal Analysis
retry. Both delegate to transactional application actions rather than placing business logic in
Filament. Plan assignment locks the affected records, is idempotent, and records the administrator,
organization, old and new plan versions, reason, IP address, user agent, and timestamp. Analysis
retry follows the stricter operations boundary below and is disabled by default.

The first verified super administrator is created through the one-time
`admin:bootstrap-super-admin` command. The command refuses to run after any super administrator
exists and writes its own platform audit event. Future administrative mutations must follow the
same action-level authorization and audit boundary rather than placing business logic in Filament
resources.

## 3.5 Privacy-request foundation

Privacy requests are a subject-scoped application boundary and intentionally do not depend on the
active organization middleware. Verified users submit and cancel through versioned self-service
API routes. Creation locks the subject, snapshots server-derived blockers, appends the initial event,
and writes a safe platform audit event in one transaction.

All later state transitions pass through `TransitionPrivacyRequest` and
`PrivacyRequestEventRecorder`. The generic operator command requires the exact current event, UUID
idempotency key, reason code, note, and evidence, but is prohibited from writing `fulfilled`.
Data-export completion instead passes through `CompletePrivacyDataExport`, which locks the
operator/request, verifies the production kill switch, approved state, exact inventory version,
identity evidence, private-artifact SHA-256/size/expiry and delivery evidence, then appends the
terminal event, immutable `privacy_request_fulfillments` receipt, and platform audit record in one
transaction. Exact replay returns the one existing receipt; changed replay conflicts.

`CompletePrivacyAccountErasure` is a separate production-gated command boundary. It locks the
operator, request and subject; verifies the approved exact head, dedicated switch/inventory,
snapshot-clearance set and bounded backup deadline; recalculates live ownership, subscription and
super-admin blockers; and refuses to run while any known personal listing image, owned-product
image or import artifact still exists. Its transaction creates the terminal event/receipt/audit
record, removes the personal tenant and revocable personal state, detaches remaining business
memberships, anonymizes invitation addresses, revokes credentials, and writes an unverified,
non-admin user tombstone. Exact replay returns the existing receipt even after configuration or
deadline changes; changed replay conflicts.

The API exposes only receipt ID, type, versions, artifact size/expiry, backup-purge deadline and
completion time. Artifact location/checksum, identity/run/storage/processor evidence, clearance
data, payload hash, and idempotency key remain private; the subject event timeline retains only its
bounded completion reference. Filament is a localized read-only projection. No queue worker,
archive generator, scheduled deletion, external object/processor cleanup or external compliance
provider is implied by these boundaries.

## 3.6 Analysis operations boundary

`AnalysisOperationsQuery` is the shared backend projection for the Filament queue and dashboard
count. It selects terminal failed analyses, stale processing leases, failed dispatch heads, and
stale dispatch claims. The table exposes tenant, listing, safe status/error codes, attempt/run
metadata, last reviewed reason/operator, and timing. Raw exception messages, dispatch errors,
idempotency keys, payload hashes, and error hashes are not rendered.

`RequestManualAnalysisRetry` is the only manual retry mutation. Filament and
`analyses:manual-retry` delegate to this action; neither contains lifecycle logic. The action
requires a verified super administrator, exact current dispatch ULID, UUID idempotency key, and
bounded operational reason. In one transaction it locks current state, rejects an automatic retry
or stale head, appends a new dispatch and immutable retry event, resets only the mutable analysis
projection, and records a platform audit event. Dispatch occurs after commit through the existing
idempotent dispatcher. The original request and subscription usage are retained.
`ANALYSIS_MANUAL_RETRY_ENABLED` is false by default and disables both Admin eligibility and the
application action until the production procedure is approved.

## 3.7 Broker-request boundary

The broker module begins with a tenant-owned request aggregate rather than a generic CRUD table.
Versioned `/api/v1/broker-requests` endpoints resolve every record through the active organization
and policy capability. Create/update/submit/cancel controllers delegate to transactional actions;
the Angular client never supplies an organization, requester, status, sequence, usage count, or
operator evidence as trusted state.

Each request points to an append-only `BrokerRequestEvent` head. Create, draft update, subject
submit/cancel, and operator review/search/cancel use row locks, an exact expected event ULID, UUID
idempotency, stable payload hashes, monotonically increasing sequence, a previous-event link, and
an immutable full request snapshot. Submission consumes the provider-independent
`broker_requests.monthly` entitlement in the same transaction and exact replay cannot consume it
again.

Tenant responses are safe projections of current request data and the bounded event timeline; they
exclude event snapshots, payload/request hashes, replay keys, and operator evidence. Filament is a
localized read-only operations projection. Verified super administrators use
`broker-requests:transition` with an exact head, UUID, reason, and external evidence to enter
`reviewing`, then `searching`, or cancel.

`PresentBrokerRequestOffer` is the dedicated manual operations boundary for immutable supplier
terms. It locks the request, validates server-calculated exact money, appends offer/request events,
and moves the request to `offers_available`. `AcceptBrokerRequestOffer` resolves the request and all
presented offers under deterministic row locks, exact request/offer heads, and one UUID replay
anchor. The tenant API exposes only safe offer terms; private supplier references, evidence,
snapshots, hashes, and replay keys remain server-side. The Angular comparison explicitly refuses
to imply cross-currency ranking.

Offer presentation also snapshots the configured commission rule/version and calculates the exact
customer-payable total in integer minor units. When transaction writes are enabled, acceptance
creates the transaction, opening transaction event, commission, and opening commission event in the
same database transaction as request/offer acceptance. An accepted offer can therefore never exist
without its exact commercial fulfillment boundary after activation.

The transaction application action uses deterministic row locking, exact current-event checks,
UUID replay protection, an explicit transition matrix, and mandatory external evidence. Completion
also appends the request-completed and commission-earned events; pre-payment cancellation appends
request-cancelled and commission-waived events. Commission settlement is a separate exact-head
action. Tenant resources expose safe status, amounts, timestamps, and event history only. Admin
resources expose bounded evidence to verified super administrators, never snapshots, hashes,
idempotency keys, supplier credentials, card data, or raw provider payloads.

This is a provider-independent evidence ledger. Payment execution, refunds/disputes, escrow,
supplier connectors, and automated communication remain reserved for later application boundaries.

`OpenBrokerPaymentCase` and `TransitionBrokerPaymentCase` provide the provider-independent
refund/dispute investigation boundary. Opening locks the transaction, requires its exact current
event at or after payment confirmation, copies exact money/currency ownership, rejects a duplicate
logical external case, and appends the opening projection/event atomically. Transitioning locks the
case, enforces exact-head and UUID replay semantics plus a strict state/type/outcome/amount matrix,
and appends one new snapshot event. Neither action calls a provider or changes transaction,
request, offer, commission, or report history.

Tenant API delivery is read-only through the existing broker-request detail projection and omits
external case/evidence references and all replay/integrity internals. A separate localized
verified-super-admin-only Filament resource exposes bounded operational references for reviewed
support work. The independent `BROKER_PAYMENT_CASES_ENABLED` switch gates writes without hiding
history.

`GenerateBrokerReport` is a separate false-by-default document boundary. It locks the completed
transaction and earned/settled commission, verifies both exact event heads, builds a deterministic
subject-safe snapshot, renders one A4 PDF with remote resources and script execution disabled,
checks a configured size ceiling, stores the artifact on a private disk, and appends the report plus
opening event under one retry-safe transaction. Storage compensation removes an orphan if the
database write fails.

The report API never accepts a disk/path and never renders arbitrary HTML. It returns safe metadata
and a short-lived relative signed URL only for available, unexpired artifacts. The download
controller repeats tenant policy authorization and verifies file existence, byte size, and SHA-256
before returning `private, no-store`. A bounded scheduled command deletes expired artifacts and
then appends the immutable purge event; a missing artifact is treated as already removed, while a
storage error leaves the report available for retry.

## 4. Queue pipeline

```text
CreateAnalysis
→ NormalizeInputJob
→ AnalyzeWithAiJob
→ MatchProductJob
→ BuildComparableSetJob
→ CalculatePriceEstimateJob
→ CalculateRiskJob
→ wait for explicit cost confirmation
→ CalculateProfit (synchronous domain action)
→ CalculateDealScore (synchronous domain action after opportunity evidence)
→ wait for explicit buyer decision events
→ FinalizeAnalysisJob
```

Each step must be:

- retryable,
- independently observable,
- idempotent where possible,
- safe from duplicate execution.

The implemented Phase 2 queue foundation currently covers `CreateAnalysis`, immutable input
normalization, a deterministic fake AI extraction provider, a separately versioned deterministic
catalog-matching stage, append-only deterministic comparable selection, and append-only
reproducible price estimation, followed by append-only deterministic risk assessment. It now also
covers a user-controlled explicit cost-confirmation boundary and append-only deterministic
expected-profit calculation, followed by a user-controlled opportunity-evidence boundary with
separate append-only deterministic logistics and resale-demand assessments. The same authorized
boundary now invokes the separately versioned deterministic DealScore action after both component
runs are recorded. It writes one append-only final score or an explicit insufficient-data result;
no controller or Angular component contains scoring logic. A separate synchronous buyer-decision
action records only explicit user workflow changes after an assessed current DealScore. It does not
run in the queue and does not create purchase, payment, inventory, or outcome records.

Submission writes one `analysis_dispatches` outbox record after an atomic entitlement check.
`ANALYSIS_SUBMISSION_ENABLED` is checked before that transaction; when disabled it consumes no
quota, creates no dispatch, and invokes no provider. The code default is false, while local/test
environments opt in explicitly. Production may enable it only after both configured analysis and
product-matching adapters resolve to reviewed non-fake implementations.
Controllers never execute provider work. `analyses:dispatch-pending` runs every minute and recovers
pending dispatches, interrupted dispatch claims, and stale processing leases. Each provider attempt
is append-only in `ai_analyses`; queue-level terminal failures also reconcile domain state. The
production worker and cron contract is maintained in `deploy/supervisor/`. Terminal heads without
an automatic retry appear in Analysis Operations; an approved manual retry creates another bounded
run with cumulative attempt numbering and never consumes quota again.

Product matching reads a bounded global catalog and appends one idempotent `product_matches` record
per analysis, AI attempt, and matcher version. Candidate evidence and incompatibility reasons are
preserved even when no canonical model is selected. Ambiguity, low confidence, and regional
incompatibility produce an explicit review state rather than a fabricated match.

Comparable selection runs only for a confirmed product match. The selector reads a hard-bounded
tenant/model candidate pool, keeps the newest observation for each source identity, and appends an
idempotent `comparable_sets` run with one included or excluded evidence item per considered record.
It does not mix countries or currencies without proven conversion and market context. Manual
comparable submission reruns this deterministic stage without rerunning AI extraction. When the
new set is ready, it also invokes the separately versioned price-estimation boundary.

Price estimation reads only one exact ready comparable set and a hard-bounded item list. A dated
resolver records identity, direct, or inverse exchange-rate provenance and rejects stale, missing,
or not-yet-known evidence. Exact decimal rates are converted to integer minor units without binary
floating point. The estimator appends an idempotent weighted-median result with Q1/Q3,
median-absolute-deviation decisions, dispersion, confidence components, reason codes, and full
input snapshots. The current selector still admits only same-country, same-currency records; rate
support does not silently broaden market coverage.

Risk assessment runs only after one completed price estimate. The bounded deterministic evaluator
reads the exact listing, canonical product, comparable-set, price, and market-scope evidence chain,
then appends an idempotent `RiskAssessment` with immutable `RiskSignal` rows. Recorded price
deviation, low price confidence, and cross-border context may contribute points; missing seller,
condition, ownership, payment, shipping, and other unrecorded facts remain zero-point unknowns that
reduce confidence and produce verification actions. The API and Angular UI expose every signal and
state clearly that the result estimates transaction uncertainty rather than fraud.

After risk assessment, the queue intentionally pauses before profit calculation. An authorized user
must submit all nine explicit cost categories against the exact current price and risk identifiers.
The domain action rejects stale evidence, cross-currency input, unsafe bounds, and unauthorized
tenant access. It appends immutable cost and profit runs, treats zero differently from unknown,
persists every formula item, and returns an idempotent current result for an immediate identical
submission. Controllers only validate and project this action; calculation logic remains behind the
`ProfitCalculationService` contract.

After a complete current profit estimate, an authorized user may submit bounded logistics and
observed sold-market facts together with the exact comparable, price, risk, cost, and profit
identifiers. `OpportunityInput` records user and derived facts once; the independently versioned
`LogisticsEvaluator` and `DemandEvaluator` create separate 0-100 component assessments and ordered
contributions. Missing required evidence returns `needs_input` with a null score. Immediate replay
is idempotent, changed evidence appends runs, and stale or cross-tenant chains are rejected. A new
upstream profit run removes the current component projections until the evidence is reconfirmed.

`DealScoreEvaluator` consumes the exact recorded product, price, risk, profit, logistics, and
demand chain through a typed DTO. `RecordDealScore` enforces ownership and evidence-link consistency
again under transaction locks, persists the immutable score and five component items, and returns
an existing record for the same versioned input hash. `DealScoreResultProjection` exposes only that
current chain and clears the pending step. Any upstream projection clears the current final score
while preserving history.

## 5. Services

```text
AnalysisService
ListingIntakeService
ListingNormalizationService
AiListingAnalysisService
ProductMatchingService
ComparableSelectionService
PriceEstimationService
RiskAssessmentService
ProfitCalculationService
LogisticsEvaluator
DemandEvaluator
DealScoreService
SellRecommendationService
OutcomeTrackingService
SavedSearchMatchingService
AlertService
SubscriptionUsageService
```

## 6. API design

All future mobile and browser-extension clients should use versioned APIs:

```text
/api/v1/
```

The Angular application is the primary first-party browser client. It uses Sanctum's stateful
cookie authentication with the Laravel `web` guard, CSRF protection, and `auth:sanctum` on protected
API routes. First-party bearer tokens must not be persisted in browser storage.

Manual listing intake deliberately uses two writes: JSON source facts first, then one atomic private
upload batch per evidence kind. A successful listing ID is retained client-side for retry so a
failed file upload cannot create duplicate listings. Each upload batch locks the listing, enforces
the per-kind count, stores generated server filenames, and removes newly written files if its
database transaction fails.

Private listing content is exposed only through short-lived, signed, authenticated API URLs. The
content controller re-applies the active organization boundary and listing policy before streaming
with `private, no-store` and `nosniff` headers.

Authorized CSV intake is a separate connector aggregate. `marketplace_imports` freezes tenant,
actor, connector, schema version, delimiter, optional default target market, private file metadata,
content hash, attestation time, lifecycle counters, processing lease, and sanitized failure head.
`marketplace_import_rows` preserves the raw and normalized row, validation reason codes, source-row
hash, outcome, and optional created/existing listing link. Organization/idempotency and
organization/source/content uniqueness make HTTP retries inert.

`ProcessMarketplaceImport` preflights the bounded file and required schema, then streams each
non-blank row through the typed connector normalizer and global-reference validation. Valid unique
rows create a listing and `connector_import` snapshot in one transaction. Invalid rows are
quarantined; repeated external identities link to the existing listing without rewriting it.
Unique jobs run on `connectors`; scheduled recovery re-dispatches pending and stale-processing
imports. The environment kill switch is off by default and no remote provider or marketplace
mutation exists.

Buy cross-market comparable normalization is a separate synchronous domain command behind the
analysis-management policy. The controller resolves both path records inside the active tenant;
`CreateComparableMarketNormalization` locks the tenant, analysis, comparable, and current
canonical match, resolves only dated immutable FX evidence, performs exact integer/decimal money
math, and appends an immutable normalization record. It then invokes the existing selection,
price-estimate, and risk refresh chain. That refresh holds the analysis and match row locks across
selection and all resulting projections, so concurrent comparable/normalization writes cannot
publish an older evidence snapshot as the newest run. The v2 selector hashes and snapshots the exact
normalization; the v2 estimator consumes that snapshot without a second rate lookup. Missing,
stale, incompatible, or mismatched evidence fails closed. Filament exposes the ledger read-only,
while the Angular analysis page owns the authorized five-language evidence form.

Sell cross-market normalization is a separate owned-product command and ledger, not a reuse of Buy
analysis rows. `CreateSellComparableMarketNormalization` locks membership, owned product, current
assessment, and exact comparable in one transaction; validates the assessed target market; resolves
only immutable dated FX evidence; performs half-even integer/decimal money math; appends compatible
or incompatible evidence; and invokes the shared `RefreshSellPriceIntelligence` command before
commit. Selector v2 freezes the exact target-scoped normalization snapshot, and price-band v2
consumes its normalized amount without re-resolving FX. Every assessed country's default currency
scope is materialized even before native evidence exists, so the localized Sell UI can record the
missing normalization rather than requiring an API-only bootstrap. Filament exposes a separate
read-only Sell normalization ledger.

Owned-product intake follows the same retry-safe two-write and private-file boundary while using
its own aggregate, permissions, lifecycle, image kinds, and immutable snapshots. It reuses active
catalog categories and geography references without creating canonical products. JSON creation is
committed before an optional upload batch, so the Angular client retains the owned-product ID and
retries only the failed upload.

Foundation endpoints:

```text
GET /api/v1/health
POST /api/v1/auth/login
POST /api/v1/auth/logout
POST /api/v1/auth/register
POST /api/v1/auth/forgot-password
POST /api/v1/auth/reset-password
GET  /api/v1/auth/email/verify/{id}/{hash}
POST /api/v1/auth/email/verification-notification
GET  /api/v1/auth/password-confirmation
POST /api/v1/auth/confirm-password
GET /api/v1/me
PATCH /api/v1/me/preferences
GET /api/v1/me/privacy-requests
POST /api/v1/me/privacy-requests
POST /api/v1/me/privacy-requests/{privacyRequest}/cancel
GET /api/v1/organizations
PUT /api/v1/organizations/{organization}/activate
GET /api/v1/marketplace-sources
GET /api/v1/marketplace-imports
POST /api/v1/marketplace-imports
GET /api/v1/marketplace-imports/{marketplaceImport}
GET /api/v1/listings
POST /api/v1/listings
GET /api/v1/listings/{listing}
PATCH /api/v1/listings/{listing}
POST /api/v1/listings/{listing}/images
DELETE /api/v1/listings/{listing}/images/{image}
GET /api/v1/listing-images/{image}/content
GET /api/v1/analyses
POST /api/v1/buy-analyses
GET /api/v1/analyses/{analysis}
POST /api/v1/analyses/{analysis}/submit
GET /api/v1/analyses/{analysis}/comparables
POST /api/v1/analyses/{analysis}/comparables
POST /api/v1/analyses/{analysis}/comparables/{comparable}/market-normalizations
POST /api/v1/analyses/{analysis}/costs
POST /api/v1/analyses/{analysis}/opportunity-evidence
POST /api/v1/analyses/{analysis}/buyer-decisions
GET /api/v1/products/search
GET /api/v1/products/{productModel}
GET /api/v1/product-categories
GET /api/v1/saved-searches
POST /api/v1/saved-searches
GET /api/v1/saved-searches/{savedSearch}
PUT /api/v1/saved-searches/{savedSearch}
POST /api/v1/saved-searches/{savedSearch}/archive
GET /api/v1/saved-searches/{savedSearch}/matches
GET /api/v1/notifications
POST /api/v1/notifications/{alert}/state
GET /api/v1/me/telegram-connection
POST /api/v1/me/telegram-connection/link
DELETE /api/v1/me/telegram-connection
POST /api/v1/integrations/telegram/webhook
GET /api/v1/owned-products
POST /api/v1/owned-products
GET /api/v1/owned-products/{ownedProduct}
PATCH /api/v1/owned-products/{ownedProduct}
POST /api/v1/owned-products/{ownedProduct}/assessments
GET /api/v1/owned-products/{ownedProduct}/sell-intelligence
POST /api/v1/owned-products/{ownedProduct}/comparables
POST /api/v1/owned-products/{ownedProduct}/comparables/{comparable}/market-normalizations
POST /api/v1/owned-products/{ownedProduct}/images
DELETE /api/v1/owned-products/{ownedProduct}/images/{image}
GET /api/v1/owned-product-images/{image}/content
```

Saved-search writes use a logical aggregate plus immutable versions. The create path locks the
organization before checking the backend plan limit; later writes lock the search/current version
and require the expected head. Catalog hierarchy is validated centrally on both create and update.
Controllers never perform matching.

`MatchSavedSearch` backfills current listing snapshots in bounded chunks and
`MatchListingSnapshot` fans one new or newly evidenced snapshot across current active searches in
bounded chunks. Listing-snapshot, product/profit/risk/deal-score changes dispatch only after the
source transaction commits. The deterministic matcher does not perform network calls, currency
conversion, geocoding, or AI inference. Matches, alerts, and notification state are immutable
ledgers with stable deduplication/idempotency keys. The tenant- and recipient-bounded in-app inbox
remains authoritative. An entitled email opt-in creates a queued channel head and dispatches
`SendAlertEmail` only after commit. The job has a stable unique key, bounded attempts, exponential
backoff, timeout, localized content, replay-safe terminal states, and append-only failure evidence.
`notifications:recover-email-deliveries` redispatches orphaned retryable heads in bounded chunks
every minute.

Telegram uses a platform-managed bot behind `TelegramProvider`. The user creates a short-lived
one-time deep link only from an entitled workspace. Telegram claims it through a separately
rate-limited webhook protected by `X-Telegram-Bot-Api-Secret-Token`; only a private chat whose chat
ID equals the Telegram user ID is accepted. `SendAlertTelegram` is dispatched after commit to the
isolated `notifications` queue and rechecks provider configuration, plan entitlement, membership,
recipient ownership, connection status, and the exact connection before provider access. It shares
the generic delivery ledger/recovery boundary with email while keeping independent attempts and
terminal heads. `notifications:recover-telegram-deliveries` and
`notifications:expire-telegram-connections` run every minute in bounded chunks.

Deployment registers the public HTTPS webhook explicitly with
`notifications:configure-telegram-webhook`; the bot token and webhook secret remain environment
secrets and are never returned by application APIs. Analysis and notification workers run in
independently scalable Supervisor pools.

Owned-product assessment commands carry the exact latest snapshot ULID. The application action
locks tenant membership, owned product, latest snapshot, and current image metadata before invoking
the configured assessor. The deterministic local assessor reuses the versioned canonical
`ProductMatcher`; controllers and Angular never infer or fabricate a match. Results are append-only
and idempotent on snapshot, image evidence, matcher version, and evaluator version. Detail
projections expose a bounded history plus `current_assessment` only when every evidence hash still
matches and both configured provider versions are current.

Sell comparable creation uses the same aggregate lock and current-assessment resolver. One
transaction stores immutable manual source evidence, reruns every assessed default market/currency
scope plus every explicitly observed alternate currency scope, persists all selection decisions,
and records each price-band result.
The selector and estimator are framework-independent domain services; controllers only resolve
tenant context, authorize the aggregate, validate input, and project resources. Reads expose
bounded record, selection, and band history plus only version-valid current bands. No Buy-analysis
table is queried or copied by this pipeline.

Sell listing preparation is another isolated downstream aggregate:

```text
GET  /api/v1/owned-products/{ownedProduct}/listing-drafts
POST /api/v1/owned-products/{ownedProduct}/listing-drafts
```

`CreateSellListingDraft` locks the tenant membership and owned-product aggregate, resolves the exact
current assessment and price band, rechecks the image manifest, validates an explicit target price,
strategy, market/currency, and listing language, then records the parent draft, disclosed fact
items, and photo-check items atomically. `CurrentSellPriceBandResolver` and
`CurrentSellListingDraftResolver` centralize version-safe current projections. The latest run is
selected in SQL per bounded market/language scope, independently from the 25-row historical view.

`DeterministicSellListingContentGenerator` loads an independently versioned first-party template
for EN/DE/ES/FR/sr-Latn and interpolates only assessment/snapshot/price facts.
`DeterministicPhotoReadinessEvaluator` evaluates image kind, count, and dimensions and leaves
semantic visibility for explicit human review. `SellListingVersionEvidence` hashes the actual
template file and a normalized photo-policy snapshot, so an unversioned content or policy change
also invalidates the current projection. No external AI or marketplace provider participates in
this boundary.

The sale-portfolio boundary remains an isolated downstream aggregate:

```text
GET  /api/v1/owned-products/{ownedProduct}/sale-portfolio
POST /api/v1/owned-products/{ownedProduct}/sale-portfolio
POST /api/v1/owned-products/{ownedProduct}/sale-portfolio/{entry}/events
```

`CreateSalePortfolioEntry` locks the tenant membership and owned product, accepts only one exact
current ready listing draft with ready photo evidence, and snapshots its complete evidence chain.
`RecordSalePortfolioEvent` locks the entry and event head, validates the submitted expected event
ID, server-owned transition, monotonic time, external identity, advertised price/currency, and UUID
idempotency key before appending. Reads expose bounded immutable history and whether the original
draft evidence is still current. All marketplace facts are manual user evidence; this module has
no connector, credential, scrape, remote write, `sold` transition, or realized-money column.

Transaction outcomes are a separate downstream aggregate:

```text
GET  /api/v1/owned-products/{ownedProduct}/outcomes
POST /api/v1/owned-products/{ownedProduct}/outcomes/purchases
POST /api/v1/owned-products/{ownedProduct}/outcomes/cost-snapshots
POST /api/v1/owned-products/{ownedProduct}/sale-portfolio/{entry}/outcomes
```

`RecordActualPurchase`, `RecordActualCostSnapshot`, and `RecordActualSale` lock membership, owned
product, immutable evidence head, and exact sale-portfolio event as applicable. Commands validate
tenant UUID idempotency, expected current version, correction reason, occurrence time, evidence,
integer-minor-unit money, active currencies, and exact historical conversion. The outcome-specific
normalizer records identity or direct/inverse dated rate provenance without changing source money.

`RecordRealizedProfit` runs inside the evidence transaction and appends an idempotent calculation
only when current purchase, complete cost snapshot, and exact sold outcome share one reporting
currency. `OutcomeTrackingProjection` returns bounded history, exact current heads, portfolio
choices, complete calculations, and language-neutral unknown codes. The localized Angular panel
keeps purchase, costs, and sale forms separate and leaves all money, currency, event, outcome, and
evidence choices empty. No payment, escrow, connector, marketplace write, or estimate-accuracy
mutation occurs in this aggregate.

Buyer-decision commands carry the exact current DealScore ID, expected current event ID, one
server-allowed next state, and a UUID idempotency key. The domain action locks the tenant
membership, analysis, complete current evidence chain, score, and decision head before appending.
Stale state returns `409`; stale or incomplete score evidence returns validation failure. Current
analysis responses expose the event head, server-computed allowed transitions, and a bounded
history page without reinterpreting historical DealScores.

The Angular `/login`, `/register`, `/forgot-password`, `/reset-password`, `/verify-email`, and
`/confirm-password` routes are client routes. Session mutations therefore use namespaced
`/api/v1/auth/*` endpoints rather than top-level Fortify URLs. Fortify remains the authentication,
registration, reset, verification, and password-confirmation engine. Logout explicitly closes the
`web` guard session, invalidates the session, and rotates the CSRF token.

`GET /api/v1/me` is available to an authenticated but unverified session so Angular can render the
correct verification state. All tenant operations, including organization invitation acceptance,
require the `verified` middleware. Signed verification URLs use a relative API signature so reverse
proxies and the Angular development origin cannot invalidate the signature. The client accepts only
the expected local verification-path shape; Laravel remains authoritative for signature and expiry
validation.

Password reset request responses are deliberately identical for known and unknown email addresses.
Angular return URLs must be local absolute paths beginning with one slash. Listing and activation
endpoints never return organizations outside the authenticated user's memberships.

### Browser localization

Angular owns runtime interface localization through a typed English catalog and lazy-loaded German,
Spanish, French, and Serbian Latin catalogs. Every catalog is a complete
`TranslationDictionary`; TypeScript compilation fails when a new English key is absent from any
supported locale. Runtime English fallback remains a defensive boundary for corrupt or stale
assets, not an accepted development path. Locale changes update document language and localized
route title, and the client sends the active BCP 47 value in `Accept-Language`. Locale catalogs
remain separate lazy chunks so adding languages does not place every translation in the initial
application bundle.

Guest locale selection is browser-local. Once authenticated, the server-side
`User.preferred_locale` is authoritative and `PATCH /api/v1/me/preferences` persists changes without
requiring an organization context. This personal preference is deliberately separate from
organization market locale and all source/target market decisions.

Laravel applies the same resolver to Filament and every API request before request validation.
Authenticated preference takes precedence over `Accept-Language`; guests resolve supported BCP 47
browser variants, including mapping `sr` and `sr-*` to the supported `sr-Latn` catalog, with a
deterministic English fallback. Fortify authentication/password responses, every framework
validation rule currently used by Procura, and every current FormRequest attribute have
EN/DE/ES/FR/sr-Latn server catalogs. API responses expose the resolved `Content-Language` header.
The middleware restores the prior application locale after every response or exception so
long-lived PHP workers cannot leak one user's presentation state into another request.

Public billing, marketplace-import, privacy-request, buyer-decision, sale-portfolio, and outcome
conflicts use the closed `ApiErrorCode` enum. Their language-neutral `code` remains stable while
`ApiErrorLocalizer` resolves presentation from the request-scoped locale and the five
`api_errors.php` catalogs. Exception messages remain internal diagnostics and must never be copied
into the response. This also avoids depending on mutable global locale state after exception
middleware restores it. Machine health/error codes, persisted evidence identifiers, market scope,
and authorization remain language-neutral.

Expected application-service validation failures use the closed `ApplicationValidationCode` enum
and `ApplicationValidation` boundary instead of constructing English
`ValidationException::withMessages` payloads inside services. The boundary resolves the active
request locale from the five `application_validation.php` catalogs while preserving Laravel's
field-keyed `422` response shape. The first migrated platform tranche covers organizations,
saved-search/notification/Telegram operations, privacy requests, listing uploads, product search,
realized-money normalization, and manual analysis retry. The complete Analysis tranche additionally
covers comparable identity/intake, cross-market normalization, cost and opportunity confirmation,
and buyer decisions. The final OwnedProducts tranche covers intake/assessment lifecycle,
private-image limits, Sell comparables and normalization, listing drafts, sale-portfolio lifecycle,
realized purchase/sale/cost evidence, profit attribution, and estimate accuracy. No owned-product
action constructs ad hoc public validation copy.

The translated surface covers the public and authentication flows, workspace shell, organization,
market and subscription administration, listing intake/detail, owned-product intake/list/detail,
the complete buy-analysis detail flow, owned-product assessment, Sell price intelligence, Sell
listing-draft/photo-readiness, and all isolated evidence/decision panels.
`frontend/tools/check-localization.mjs` rejects
application components without `TranslatePipe`, hard-coded template text and common hard-coded
component messages. Country and currency display names plus number, date, percentage, file-size,
and money formatting follow the active locale; stored ISO codes, exact minor-unit values and
immutable reason identifiers remain unchanged.

Later endpoints may include:

```text
POST /api/v1/sell-analyses
POST /api/v1/analyses/{id}/recalculate
POST /api/v1/analyses/{id}/outcome
```

## 7. File storage

Original uploads must be private.

Use:

- private disks,
- signed URLs,
- image validation,
- file-size limits,
- normalized derivatives where necessary.

## 8. Observability

Track:

- queue runtime,
- failed jobs,
- AI latency,
- AI cost,
- analysis completion rate,
- product-match confidence,
- comparable-set readiness and exclusion reasons,
- price-estimate confidence,
- notification failures,
- user conversion.

`/up` is the process liveness boundary. The versioned `GET /api/v1/health` endpoint is the
dependency-readiness boundary: it performs bounded database and shared-cache probes and, when
production queue monitoring is enabled, requires a fresh processed heartbeat from each configured
worker queue. It returns `503 unavailable` when a required dependency, queue age, or queue latency
is outside policy. Its public payload exposes only aggregate `database`, `cache`, and `queues`
states; queue names, connection names, exception text, infrastructure drivers, and cache evidence
remain internal.

The singleton scheduler dispatches one lightweight `RecordQueueHeartbeat` job per minute to
`analyses`, `connectors`, `imports`, `notifications`, and `default`. Each worker writes only a short-lived
shared-cache projection containing dispatch time, processing time, and latency. A distributed lock
prevents an older delayed job from replacing newer evidence. No heartbeat row is added to the
business database and expired cache state is intentionally unrecoverable.

`operations:readiness --require-queue-heartbeats --json` is the deployment and operator contract.
Unlike the public endpoint, the local CLI may show each configured queue's age and latency so an
operator can identify the affected pool. The localized Filament dashboard exposes only Ready,
Core ready, or Unavailable and never shows infrastructure identifiers. Queue-heartbeat monitoring
is disabled by default and must be activated only after shared cache, the singleton scheduler, and
every documented worker pool are running.

The global Filament overview aggregates are isolated in `PlatformOverviewMetrics`. A cold snapshot
has an explicit twelve-query budget; a valid snapshot is stored for 30 seconds in the configured
shared cache. A distributed lock prevents a dashboard traffic burst from recomputing the same
global counts concurrently. Cache corruption or unavailability falls back to the bounded database
query without hiding the separate readiness failure. Readiness itself is never served from this
dashboard cache.

`operations:capacity-baseline` is a bounded diagnostic harness for the cold dashboard aggregate,
the Analysis Operations count, and one optional tenant Analysis index page. Versioned query budgets
are always enforced. Database and wall-time budgets are enforced only with `--enforce-duration`,
because CI hardware timing is not a trustworthy production SLO. The command performs no business
write, external call, provider request, queue dispatch, or unbounded row load. Production execution
is refused unless an operator supplies the explicit read-only acknowledgement.

`operations:queue-throughput` is the separate bounded staging workload for queue transport and
worker-pool concurrency. It dispatches unique synthetic no-op jobs to one configured queue, records
only run identity, sequence, dispatch/process timestamps, and latency in the shared cache, polls the
receipts in bounded batches, calculates completion, jobs/second, and nearest-rank p50/p95/p99, then
removes the run evidence. A run marker prevents late jobs from recreating accepted evidence; any
residual race expires through the fixed TTL. Staging requires Redis for both queue and cache, every
timing budget may only be tightened from the repository baseline, and production execution has no
override. This isolates infrastructure throughput measurement from customer data and from the real
Analysis pipeline; it does not claim endpoint or provider-processing capacity.

`operations:verify-saturation-soak-evidence` closes the application-side infrastructure evidence
boundary without coupling Procura to one monitoring vendor. An approved staging collector exports
only the exact aggregate fields declared in
`tools/performance/saturation-soak-evidence.schema.json`: resource utilization in basis points,
cumulative error/event counters, fixed queue depth/age, and fixed worker busy/restart metrics. The
parser rejects additional fields, malformed or gapped UTC samples, phase reordering, decreasing
counters, an unexpected release commit, and files outside ignored private storage. The verifier
requires ordered baseline, saturation, soak, and recovery phases, calculates nearest-rank
percentiles and deltas, requires median pressure throughout soak rather than accepting one short
peak, and applies the versioned capacity budget. It permanently refuses
production. A local rehearsal can prove only the contract; only a passing run executed by the
staging application reports `release_evidence=true`. The raw monitoring export remains private and
only the identifier-free aggregate report belongs in release evidence.

## 9. Deployment

Initial deployment can use:

- Ubuntu 24.04,
- nginx,
- PHP-FPM 8.3,
- MySQL 8,
- Redis,
- Supervisor,
- Horizon,
- cron for scheduler,
- GitHub Actions.

The production browser boundary uses one HTTPS origin. `npm run build:frontend` emits the Angular
application into ignored deployment output at `public/spa` and runs
`tools/verify-production-serving.mjs`. The verifier requires content-hashed JavaScript/CSS, no
public source maps, a root Angular base URL, and the documented nginx route/cache contract.

nginx serves direct browser-route refreshes through the internal Angular `index.html` fallback.
For local Apache/Laragon development, Laravel registers only the known Angular browser prefixes
and redirects them to the configured `FRONTEND_URL`, preserving the complete path and query. This
adapter never catches API, Sanctum, administration, storage, or mutating requests.
`/api`, `/sanctum`, `/admin`, `/filament`, `/flux`, fingerprinted Livewire paths, `/storage`, and
`/up` always resolve through Laravel or an existing public file and can never fall through to the
SPA. Non-`GET`/`HEAD` requests outside those backend boundaries are rejected. The application shell
uses no-store/revalidation headers; content-hashed bundles use one-year immutable caching.

The reference web configuration and atomic-release checklist are maintained in `deploy/nginx/`.
Supervisor worker and cron scheduler examples are maintained in `deploy/supervisor/`. Every target
host must replace its domain, certificate, release-root, PHP-FPM socket, PHP CLI path, worker count,
and operating-system user, then validate nginx, Supervisor, and the Laravel schedule before serving
traffic. Load balancers use `/up` only for process liveness and `/api/v1/health` for traffic
readiness.

## 10. Scaling strategy

Do not split services prematurely.

Scale in this order:

1. optimize queries and indexes,
2. add bounded shared Redis caching with measured cold-path fallbacks,
3. separate queue workers by workload,
4. move files to object storage,
5. add read replicas if needed,
6. split high-load connectors or AI processing only when justified.
