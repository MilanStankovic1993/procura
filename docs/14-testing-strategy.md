# 14 — Testing Strategy

## 1. Unit tests

Required:

- price estimation,
- outlier detection,
- profit calculation,
- risk scoring,
- deal scoring,
- normalization,
- product alias matching,
- confidence scoring.

## 2. Feature tests

Required:

- registration,
- personal organization creation,
- organization switching,
- buy analysis creation,
- sell analysis creation,
- image upload,
- analysis pipeline,
- subscription enforcement,
- saved search matching,
- outcome recording,
- privacy request creation, history, cancellation, operational transitions, export fulfillment,
  and account erasure,
- broker-request draft, submission, history, cancellation, quota, operator review/search, offer
  presentation/comparison/acceptance, transaction fulfillment, commission earning/waiver/
  settlement, localized report generation/download/retention purge,
- analysis operations queue and audited manual retry,
- dependency readiness and queue-worker heartbeat processing,
- production preflight configuration, secret-free JSON, strict warning handling, trusted origin/
  proxy boundaries, shared data-plane drivers, secure sessions/storage, provider kill switches, and
  feature dependency consistency.

Production-preflight coverage must prove safe disabled integrations remain explicit warnings,
blocking failures return non-zero, strict mode rejects warnings, non-production rehearsal requires
an explicit option, fake analysis cannot be enabled, disabled analysis submission consumes no quota
and creates no dispatch, Redis `retry_after` exceeds the longest job timeout, catch-all proxies are
rejected, and configured secrets never appear in table or JSON output.

The production-runtime CI contract must additionally apply the complete migration ledger against
MySQL 8.4, prove cached application readiness against Redis, and run the dedicated MySQL schema/
session compatibility test. That test verifies UTC, strict mode, `utf8mb4`, InnoDB, migration
completeness, foreign keys, bounded index names, and the reserved `rank` query. The production build
must fail when the nginx security/routing boundary, Supervisor worker pools, queue ownership, retry
timing, scheduler recovery set, or fail-closed environment template drifts from its reviewed
contract. The complete functional suite remains in the PHP 8.3/8.4/8.5 SQLite matrix; MySQL is an
additional production-family compatibility gate, not a false claim that every test is duplicated.

Operational-readiness coverage must prove database/cache failure isolation, sanitized public
`503` responses, disabled-by-default queue monitoring, one job on every configured worker queue,
fresh/missing/stale/high-latency classification, distributed out-of-order protection, strict
deployment-command exit codes, machine-readable JSON, topology-free public output, and localized
Admin catalog parity.

Privacy workflow coverage must prove verified self-service access without organization context,
active ISO residence validation, notice attestation, one active request per subject/type, exact
creation/cancellation/transition replay, stale-head conflicts, cross-user `404`, deterministic
deletion blockers/response target, immutable request/event/fulfillment records, reserved generic
terminal state, verified-super-admin authorization, fulfillment kill switch, exact inventory
version, private-artifact checksum/size/expiry and delivery evidence, erasure-specific kill switch
and inventory, exact blocker-clearance set, live ownership/billing/admin recalculation, private-file
absence, personal-tenant removal, access revocation, pseudonymous business-history retention,
transactional rollback, exact replay/changed conflict, safe subject/Admin projections,
five-language catalog parity, and successful CLI operation without any real archive generation,
provider delivery, or external processor/backup mutation.

Broker-request coverage must prove verified tenant access, owner/administrator/analyst/viewer
capability boundaries, cross-tenant `404`, draft-only content mutation, exact create/update/
submit/cancel replay, changed replay conflict, stale-head rejection, immutable bounded event
history, atomic one-time `broker_requests.monthly` consumption, fail-closed Free-plan exhaustion,
request/offer kill-switch behavior, safe subject/Admin projections, verified-super-admin evidence,
allowed review/search/cancel transitions, immutable offer terms/events, server-calculated
safe-integer totals, exact request/offer-head acceptance, expired/stale rejection, alternative
offer closure, request-cancellation cleanup, cross-currency warning, private supplier-reference
exclusion, rejection of reserved transaction states, active personal-request account-erasure
blocking, complete five-language catalogs, and API/CLI operation without a supplier connector,
payment, marketplace, or report provider.

Broker transaction/commission coverage must additionally prove a positive versioned commission
configuration, integer half-up basis-point calculation, JavaScript-safe total bounds, immutable
offer-term recomputation at acceptance, atomic one-to-one transaction/commission creation, exact
opening source heads, append-only previous-linked histories, verified-super-admin-only mutation,
mandatory external evidence, exact-head concurrency, exact UUID replay/changed conflict, the strict
payment/order/shipping/delivery/completion sequence, pre-payment-only cancellation, atomic request
completion/cancellation and commission earning/waiver, earned-only settlement, false-by-default
transaction activation, and exclusion of evidence/hashes/snapshots/replay keys from subject and
Admin projections. Provider fakes are unnecessary because this boundary makes no external payment,
supplier, carrier, settlement, or report call.

Broker-report coverage must prove false-by-default activation, verified-super-admin-only
generation, completed transaction plus earned/settled commission state, both exact source heads,
UUID exact replay and changed/logical-source conflict, immutable subject-safe snapshots, exclusion
of notes/private supplier/operator/replay/hash data, real `%PDF` output, bounded size/page count,
private storage write verification, safe API/Admin projections, short-lived relative signing,
tenant `404`, policy authorization, no-store/no-sniff delivery, checksum/size/missing/expiry
failure, immutable generated/purged events, deletion-before-purge semantics, retry after storage
failure, personal-erasure blocker/private-file inventory, all five locales, and Poppler-rendered
visual inspection of every page. No provider fake is needed because rendering is first-party and
the boundary performs no outbound call.

Broker payment-case coverage must prove false-by-default activation, post-payment-only opening,
verified-super-admin authorization, exact transaction/case-head concurrency, transaction-scoped
UUID replay/conflict, duplicate logical external-case rejection, safe-integer and payable-total
amount limits, strict `open -> under_review -> resolved|cancelled` sequencing, type-compatible
refund/dispute outcomes and zero/positive resolved-amount rules, immutable snapshots/events,
unchanged transaction/commission histories, safe tenant/Admin projections, CLI parity, active
personal-case erasure blocking, privacy inventory v4, and complete five-language catalogs. No
provider fake is required because the boundary performs no payment-provider call.

Broker operations-monitoring coverage must prove each of the seven current-head classifications,
disjoint request age versus past-needed-by counting, terminal/resolved exclusion, reviewed bounded
configuration, one SQL query independent of table size, safe zero/attention JSON states,
report-only versus alerting exit codes, production-preflight rejection of invalid thresholds,
five-language Admin catalog parity, the cached dashboard query budget, and exclusion of tenant,
supplier, offer, money, storage, payment, evidence, snapshot, hash, and replay data.

Analysis operations coverage must prove verified-super-admin-only mutation, exact current-dispatch
checks, UUID replay/mismatch behavior, automatic-retry and total-run ceilings, unchanged
subscription usage, cumulative attempt bounds, append-only retry/dispatch evidence, safe error
hashing, dashboard/query classification of terminal and stale heads, localized Admin catalog
parity, CLI replay, and rendered exclusion of raw failures and internal hashes.

The saved-search feature suite must cover normalized immutable criteria versions, expected-head
conflicts, UUID idempotency replay/mismatch, backend plan limits, owner/administrator/analyst/viewer
permissions, cross-tenant 404 behavior, exact-currency matching, explicit missing financial and
geospatial evidence, deduplicated alerts, recipient-only inbox reads, and append-only
read/unread/terminal-archive notification state. Queue fakes must distinguish the legitimate
listing-monitoring dispatch from Buy Analysis processing jobs instead of asserting that no job of
any kind was scheduled. Email coverage must additionally prove backend entitlement enforcement,
after-commit queueing, recipient locale rendering, queued/attempting/delivered replay safety,
retryable failure and terminal exhaustion evidence, inbox delivery projection, and bounded orphan
recovery without contacting a real provider.

Telegram coverage must additionally prove plan/provider/connection boundaries, encrypted
challenges and identifiers, dedicated-key identity hashes, private-chat ownership, webhook-secret
rejection, one-time replay safety, challenge expiry, revocation, localized bounded message
rendering, after-commit queueing, independently recoverable delivery heads, sanitized provider
failures, exhaustion, and inbox/admin projections. HTTP and provider fakes must assert the bounded
Telegram Bot API request and webhook-registration contract without making a real network request.

Stripe billing coverage must prove owner-only Checkout and portal access, server-side plan/Price
mapping, required tenant idempotency keys, one provider call for duplicate Checkout requests,
manual-assignment isolation, invalid-signature rejection, signed webhook acceptance, Cashier
subscription persistence before entitlement projection, duplicate and out-of-order event safety,
unknown-price fail-closed behavior, non-entitled Free fallback, and immutable provider evidence.
Provider fakes and locally generated webhook signatures must be used; automated tests never contact
Stripe.

## 3. Authorization tests

Required:

- no cross-organization read,
- no cross-organization update,
- members cannot manage billing,
- non-admin users cannot access Filament admin,
- users cannot override estimates without permission,
- ordinary tenant users cannot access billing operations and rendered admin tables expose no
  sensitive provider identifiers,
- users cannot read/cancel another subject's privacy request and Admin privacy tables expose no
  requester-email, payload, active-key, artifact reference/checksum, fulfillment evidence, or
  idempotency hashes,
- users cannot read or mutate another organization's broker request; viewers cannot mutate, and
  tenant/Admin projections expose no event snapshot, internal hash, idempotency key, or
  unauthorized operator evidence; tenant users cannot execute transaction or commission
  operations,
- ordinary and unverified users cannot access Analysis Operations or request manual retries, and
  rendered rows expose no raw analysis/dispatch failures, payload/error hashes, or idempotency keys.

## 4. Integration tests

Use fake providers for:

- AI,
- email,
- Telegram,
- payment,
- marketplace connectors.

Do not call real external services in automated tests.

## 5. Golden-data tests

Maintain a curated dataset of product listings with expected:

- model,
- condition,
- accessories,
- price range,
- risk signals.

Use it to detect regression in AI prompts and matching logic.

The initial catalog-matching fixture set is implemented in feature tests and covers:

- exact alias selection with stable evidence and duplicate-delivery idempotency,
- unknown input with no silent canonical selection,
- ambiguous candidates requiring review,
- region-incompatible variants requiring review,
- authenticated, validated, and bounded catalog search/read responses,
- tenant isolation of match results.

The initial comparable-selection fixture set is implemented in feature tests and covers:

- zero-to-minimum evidence progression and an explicit insufficient state,
- immutable records, duplicate-submission idempotency, and append-only selection runs,
- exact-model ranking factors and stable included evidence,
- spare-part, broken-condition, incompatible-variant, mixed-country, and mixed-currency exclusions,
- explicit compatible/incompatible cross-market evidence, superseding append-only normalization
  decisions, same-market rejection, factor/cost bounds, attestation, role and tenant isolation,
- newest-observation source deduplication and hard candidate-pool bounds,
- authenticated role enforcement, validation limits, and tenant isolation,
- refusal to accept evidence before a confirmed canonical product match.

The initial price-estimation fixture set is implemented in feature tests and covers:

- immutable, idempotent exchange-rate recording with exact decimal values,
- calculation-time identity, direct, and inverse resolution,
- stale, missing, and not-yet-known rate rejection,
- exact integer-minor-unit conversion without binary floating point,
- dated cross-currency conversion plus half-even bounded market-factor and landed-cost calculation,
- selector/estimator replay from one exact immutable normalization snapshot without double
  conversion, with invalid normalization failing closed,
- zero-to-ready comparable progression into one append-only estimate,
- weighted median, Q1/Q3, median, median absolute deviation, and confidence evidence,
- explicit MAD outlier decisions and preserved source snapshots,
- high-dispersion `low_confidence` output without invented adjustments,
- duplicate execution idempotency, immutable estimates, tenant isolation, and API exposure,
- Angular recording/rendering of compatibility, factor, explicit landed costs, formula, evidence,
  and rate provenance in all supported languages, plus read-only Admin visibility.

The initial risk-assessment fixture set is implemented in unit, feature, and Angular tests and
covers:

- every inclusive score-band boundary from 0 through 100,
- one exact upstream analysis/product/comparable/price evidence chain,
- asking-price deviation, low-confidence price evidence, and cross-border contributions,
- unknown evidence contributing zero points while reducing confidence and requiring checks,
- score reconstruction from immutable signal contributions and preserved source snapshots,
- hard signal bounds, duplicate-execution idempotency, replay determinism, and immutable records,
- tenant isolation and API exposure,
- Angular rendering of level, score, confidence, components, unknowns, actions, every signal, and
  the transaction-uncertainty disclaimer.

The initial cost-and-profit fixture set is implemented in feature and Angular tests and covers:

- exact integer-minor-unit formulas for gross margin, total cost, expected net profit, margin, and
  return on invested capital,
- the semantic boundary between explicit zero and unknown null,
- cross-border transport/customs/tax and compatibility requirements,
- visible negative-profit results without clamping or optimistic rewriting,
- immutable evidence, stable replay, immediate duplicate idempotency, and changed-input runs,
- returning to an older historical value as a new current version,
- stale upstream identifiers, currency and amount bounds, role enforcement, tenant isolation, and
  cascade cleanup,
- Angular serialization and rendering of input state, every formula item, confidence, reasons, and
  the estimate disclaimer.

The initial opportunity-component fixture set is implemented in feature and Angular tests and
covers:

- exact 100-point logistics and demand contribution bounds with separate persisted items,
- asking-comparable breadth/recency separated from explicit sold observations and velocity,
- unknown, confirmed false, and numeric zero as distinct states,
- domestic and cross-border route readiness plus transport-cost and compatibility provenance,
- sold-rate normalization by an explicit observation window and zero-sales behavior,
- stale upstream identifiers, contradictory pickup evidence, dependency and hard-bound validation,
- duplicate idempotency, changed runs, historical reversion, immutability, cascade cleanup, role
  enforcement, tenant isolation, and downstream invalidation after a new profit run,
- Angular input hydration/serialization, component scores, criterion contributions, confidence,
  reasons, responsive isolation, and the component disclaimer.

The deterministic DealScore fixture set is implemented in unit, feature, and Angular tests and
covers:

- exact `35/25/15/15/10` weight totals and integer half-up arithmetic,
- negative, zero, linear, ceiling, and above-ceiling margin normalization,
- 0/100 component extremes and missing-component `insufficient_data`,
- every recommendation threshold boundary,
- critical-risk, low-price-confidence, unknown-model, and lowest-cap-wins behavior,
- immutable score/item persistence, exact upstream links, stable replay, changed and historical
  reversion runs, stale-chain projection clearing, tenant/role enforcement, and cascade cleanup,
- Angular rendering of capped/uncapped scores, all five components, cap decisions, factors,
  assumptions, next checks, and the final disclaimer.

The localization contract is implemented in Laravel, Angular, source checks, and browser
verification and covers:

- supported-locale validation, authentication, registration persistence, and guest rejection,
- personal preference persistence without organization or market-context coupling,
- BCP 47 browser-locale resolution and deterministic English fallback,
- lazy loading of every non-English catalog, interpolation, and local browser persistence,
- document language, localized route titles, and `Accept-Language`,
- authenticated server-preference precedence across a full reload,
- guest API validation for regional EN/DE/ES/FR/sr-Latn browser tags, authenticated-preference
  precedence, deterministic unsupported-language fallback, `Content-Language`, and locale reset
  after both normal and validation-exception responses,
- complete shared-key contracts for Admin, monitoring, authentication, password-reset, JSON
  summary, framework validation, API conflict, and every current FormRequest attribute catalog,
- a source-level FormRequest field check plus an explicit non-English contract for every Laravel
  validation rule currently used by Procura,
- exact equality between the closed `ApiErrorCode` enum and all five `api_errors.php` catalogs,
  non-English conflict messages without English fallback, stable language-neutral response codes,
  request-scoped exception translation, and rendered exclusion of raw exception diagnostics,
- exact equality between the closed `ApplicationValidationCode` enum and all five
  `application_validation.php` catalogs, non-empty/non-English messages, request-scoped Serbian
  runtime rendering, locale reset after the `422`, and a source guard preventing migrated platform
  Analysis, and OwnedProducts services from recreating ad hoc validation messages,
- desktop and 390 x 844 responsive rendering without horizontal overflow,
- representative domain-screen translations for every non-English catalog,
- exact key-order and non-empty-value equality across all five server-side admin catalogs,
- authenticated Filament dashboard rendering in each personal locale plus supported guest
  `Accept-Language` resolution,
- application-owned Filament translation overrides for navigation accessibility labels,
  notifications, table boolean states, and pluralized result counts,
- browser verification of the admin language modal, immediate localized notification, resource
  table, preserved resource URL, and English/Serbian round trip,
- compile-time equality of the complete typed catalog key set,
- a source scan that rejects application components without localization and common hard-coded
  user-interface text.

`npm run check:i18n` is part of the frontend lint boundary and therefore runs in CI. Any new route,
panel, form state, validation fallback, confirmation or empty/error/loading state must add all five
translations in the same change. Any new FormRequest field or Laravel validation rule must add its
five server attribute/message entries in the same change. Any new public domain conflict must add
one `ApiErrorCode` case and all five safe `api_errors.php` messages in the same change. Stored
Any expected application-service validation must add one `ApplicationValidationCode` case and all
five safe `application_validation.php` messages instead of embedding presentation text in the
service. Stored evidence codes remain language-neutral, but known domain values must use localized
presentation labels.

The initial owned-product intake fixture set is implemented in feature, service, and Angular
component tests and covers:

- exact preservation of unknown versus known zero/empty facts,
- active category and same-continent ordered-country validation,
- tenant isolation plus owner/administrator/analyst/viewer capability boundaries,
- draft/ready transitions and terminal archived records,
- immutable, actor-attributed, hash-addressed snapshots and idempotent identical updates,
- private signed image access, kind-specific bounds, deletion, and whole-batch file cleanup,
- retry-safe Angular creation/upload behavior, detail rendering, and localized status/evidence
  presentation.

## 6. Acceptance tests

MVP acceptance scenarios:

### Buy Analysis

User submits listing and receives a complete result.

### Sell Analysis

User submits and reviews a private owned-product intake, marks it ready, and records an immutable
identity/condition assessment for the exact latest snapshot and image evidence. Tests must prove
tenant and role isolation, idempotent replay, explicit unmatched/ambiguous states, catalog
non-creation, immutable history, stale-snapshot rejection, and stale projection after image or
intake changes. Sell price-intelligence tests additionally prove exact minor-unit bands,
zero-to-ready progression, assessed default and alternate currency-scope refresh, stable
idempotency, immutable records/items/runs, algorithm-version invalidation, and bounded historical
projection with no more than 25 records and 10 newest normalization decisions per record. They
also prove newest-decision ordering under a reduced projection limit, compatible/incompatible Sell
cross-market evidence, exact dated direct/identity conversion, factor and landed-cost math,
superseding append-only decisions, no double conversion, provenance projection, localized
recording/rendering, and read-only Admin visibility. Sell listing-draft tests additionally prove
all five explicit listing languages,
integer-minor-unit target price preservation, selected-band provenance, required override reasons,
source-fact disclosure, private photo checklist, idempotent replay, append-only parent/child rows,
tenant/role isolation, stale assessment/image rejection, and generator-version invalidation.

Sale-portfolio tests additionally prove review-complete current-draft gating, stale template/photo
policy rejection, immutable entries/events, exact minor-unit advertised-price history, manual
publication identity/URL evidence, server-owned transitions, withdrawal reasons, monotonic event
heads, idempotent replay/conflict, tenant/role isolation, and separation from realized-sale money.

### Outcome

Outcome tests prove separate immutable purchase/cost/sale/profit records, tenant/role isolation,
tenant UUID idempotency, optimistic correction heads, correction reasons, exact source/reporting
minor units, identity and historical dated-rate conversion, explicit incomplete costs, no-sale
without money, exact portfolio-event gating, post-sale lifecycle blocking, full source IDs/hashes,
and deterministic realized profit only for a complete same-reporting-currency chain.

The localized Angular test proves explicit unknown rendering and empty purchase, currency, cost,
portfolio, outcome, and realized-money choices. The Phase 4 acceptance scenario now continues from
manual publication through a complete actual purchase/cost/sold chain and exact net profit.
Estimate-accuracy tests additionally prove explicit exact-analysis selection, complete current-head
revalidation, tenant/role isolation, UUID replay/conflict, optimistic attribution/report heads,
mandatory correction reasons, immutable attribution/report histories, signed/absolute minor
errors, bounded half-even basis-point errors, zero-denominator handling, explicit non-comparable
cost categories, missing/stale dated-rate unavailability, absent expected duration, API projection,
and localized Angular service/panel contracts in EN/DE/ES/FR/sr-Latn.

## 7. Performance tests

Measure:

- analysis creation response,
- queue throughput,
- admin listing search,
- comparable selection,
- price estimation and rate resolution,
- Sell multi-scope comparable recalculation and price-band projection,
- dashboard queries.

Queue-heartbeat age and dispatch-to-process latency provide a continuous production signal for
worker availability and starvation, but they are not a load test. Staging capacity tests must
still establish queue-throughput and endpoint/query budgets at the intended traffic and dataset
size before production approval.

The first deterministic capacity regression suite is implemented at
`tests/Feature/Performance/CapacityBaselineTest.php`. It loads 2,000 tenant Analysis rows and proves
that:

- the cold global Admin metrics projection remains exactly twelve queries;
- a repeated Admin metrics projection uses the shared snapshot and performs zero database queries;
- a corrupt cached payload is rejected, replaced once, and then reused without database queries;
- Analysis Operations count remains one query;
- the first 50 tenant Analysis summaries, including eager-loaded listing data, remain two queries;
- the staging command emits one parseable JSON document, rejects invalid tenant input, refuses
  unacknowledged production use, and fails when a versioned query budget regresses.

These deterministic query-count tests run in CI and intentionally do not enforce wall time.
Staging must run `operations:capacity-baseline --enforce-duration` against MySQL and a
production-shaped, non-customer dataset. The current harness covers the first operational query
budgets only. Full concurrent analysis creation, queue throughput, comparable selection,
price/rate resolution, Sell multi-scope recalculation, browser/API latency percentiles, saturation,
and soak testing remain required before launch; none may be claimed from an in-memory SQLite test.
