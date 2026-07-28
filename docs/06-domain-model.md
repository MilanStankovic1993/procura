# 06 — Domain Model

## 1. Core bounded contexts

### Identity and tenancy

```text
User
Organization
Membership
Role
```

### Geography and market context

```text
Continent
Country
Currency
ExchangeRate
MarketScope
```

### Catalog

```text
ProductCategory
Brand
ProductModel
ProductVariant
ProductAlias
ProductSpecification
```

### Market data

```text
MarketplaceSource
Listing
ListingImage
ListingSnapshot
ComparableRecord
SellerProfile
ImportBatch
```

### Owned-product intake

```text
OwnedProduct
OwnedProductSnapshot
OwnedProductImage
OwnedProductAssessment
```

### Analysis

```text
Analysis
AiAnalysis
ProductMatch
ComparableSet
PriceEstimate
RiskAssessment
RiskSignal
CostInput
CostInputItem
ProfitEstimate
ProfitEstimateItem
OpportunityInput
OpportunityInputItem
OpportunityAssessment
OpportunityAssessmentItem
DealScore
BuyerDecisionEvent
```

### Commerce outcome

```text
Purchase
SaleListing
Sale
ProfitRecord
```

### Monitoring

```text
SavedSearch
SavedSearchVersion
SavedSearchMatch
Alert
NotificationLog
TelegramConnection
TelegramConnectionEvent
```

`SavedSearch` is the tenant-owned logical aggregate and keeps only its owner, lifecycle state,
current-version pointer, and monotonic version sequence. `SavedSearchVersion` is append-only and
stores typed criteria, a normalized criteria snapshot, stable criteria/payload hashes, actor,
reason, prior-version pointer, and UUID idempotency key.

`SavedSearchMatch` is immutable evidence for one search version and one listing snapshot. Its
deterministic key also includes the exact current analysis/evidence heads and matcher version, so
new evidence may be evaluated without overwriting an earlier result. `Alert` is an immutable,
deduplicated recipient event. `NotificationLog` is an append-only per-channel state ledger whose
latest sequence is the channel projection. In-app archiving is terminal. Email uses explicit
queued, attempting, delivered, failed, exhausted, and suppressed events with bounded provider,
attempt, retry, and error metadata; delivery records never contain the recipient's plaintext
credentials. Telegram uses the same channel ledger and stores only the connection reference and
safe provider outcome metadata.

`TelegramConnection` is a personal security aggregate, not an organization membership. It stores a
pending, connected, expired, or revoked lifecycle head. The one-time challenge, Telegram user ID,
private chat ID, and optional username are encrypted at rest. A dedicated HMAC-SHA256 key produces
database-unique nullable user/chat identity hashes; the plaintext identity and bot credentials are
never indexed or projected. `TelegramConnectionEvent` is an append-only lifecycle ledger.
Revocation clears the encrypted identifiers and active HMAC indexes while retaining their hashes
in the immutable revocation event, so the identity may be safely reassigned without losing audit
evidence. Terminal connection rows are retained instead of being deleted.

### Subscription

```text
Plan
PlanFeature
OrganizationPlanAssignment
SubscriptionUsage
Subscription
SubscriptionItem
BillingCheckoutSession
BillingProviderEvent
```

Stable internal plan and feature codes are provider-independent. `Subscription` and
`SubscriptionItem` are the Cashier projection of the Stripe lifecycle for an organization.
`BillingCheckoutSession` retains an encrypted, expiring hosted Checkout URL and a tenant-scoped
idempotency hash. `BillingProviderEvent` is an append-only evidence ledger containing safe
projection metadata plus a payload SHA-256 digest, never the raw webhook payload.

`OrganizationPlanAssignment.source` distinguishes manual administration from Stripe projection.
Only signed, supported Stripe subscription events may change a Stripe-owned assignment. Manual
assignments are never silently overwritten. Unknown/multiple Price IDs and non-entitled statuses
remove only the matching Stripe-owned assignment so that the provider-independent entitlement
resolver deterministically falls back to Free.

### Brokerage

```text
BrokerRequest
BrokerOffer
BrokerTransaction
Commission
```

## 2. Important aggregates

### Identity aggregate

Owns:

- user identity,
- authentication state,
- personal interface preference.

Implementation rules:

- `User.preferred_locale` is a validated, non-secret BCP 47 language tag with English as the
  deterministic fallback.
- the preference belongs to the person, not an organization, and is available through Laravel's
  `HasLocalePreference` contract for future localized notifications.
- a personal interface locale must never be used as an implicit market, country, currency,
  organization locale, analysis scope, or listing-language decision.

### Analysis aggregate

Owns:

- analysis request,
- source input,
- normalized product data,
- calculation status,
- final recommendation.

Implementation rules:

- `Analysis` is tenant-owned and references one exact immutable `ListingSnapshot`.
- request market scope, pipeline version, request payload, and request hash are immutable after
  draft creation.
- a draft consumes no entitlement; the first successful submit consumes quota transactionally and
  creates one idempotent dispatch record.
- listing lifecycle and analysis processing status remain separate aggregates.
- every provider attempt is append-only in `AiAnalysis`; retries never overwrite historical input,
  output, validation, timing, or error evidence.
- every dispatch run is append-only in `AnalysisDispatch`. When the production kill switch is
  enabled, a terminal failure may be retried manually only by a verified super administrator and
  only when no automatic retry is scheduled.
- each manual retry appends one immutable `AnalysisRetryEvent` joining the exact failed dispatch to
  one new dispatch run. It preserves the prior failure time, safe error code, SHA-256 error digest,
  prior cumulative attempt count, actor, bounded reason, UUID idempotency key, stable payload hash,
  and request time without copying the raw error message.
- a manual retry reuses the immutable request and existing quota consumption. It never creates a
  second subscription-usage event, resets attempt history, or edits an older dispatch/AI attempt.
- the retry command locks the operator, analysis, and current dispatch; requires the exact current
  dispatch ULID; and treats exact UUID replay as inert while rejecting stale state or changed key
  reuse.
- every confirmed product match produces an append-only, versioned `ComparableSet`, including an
  explicit insufficient-data result when fewer than the required records are safe to use.
- a comparable set owns its immutable candidate decision evidence; later evidence creates a new
  selection run and never rewrites an older run.
- every ready comparable set may produce one append-only, idempotent `PriceEstimate` for an exact
  algorithm version and stable input hash. It never rewrites an older estimate run.
- every normalized price item preserves the original amount and currency, resolved rate identity
  and direction, normalized integer-minor-unit amount, weight, inclusion decision, and evidence
  snapshot.
- every completed price estimate may produce one append-only, idempotent `RiskAssessment` for the
  exact analysis, product match, comparable set, evaluator version, and stable input hash.
- every `RiskSignal` preserves its category, severity, bounded contribution, source evidence,
  confidence, unknown state, and any required verification action.
- every explicit cost confirmation appends a tenant-owned `CostInput` and ordered
  `CostInputItem` evidence set linked to the current exact `PriceEstimate` and `RiskAssessment`.
- every cost input may produce one append-only, idempotent `ProfitEstimate` with ordered
  `ProfitEstimateItem` formula evidence; a changed input appends a new run and never rewrites
  history.
- terminal structured output may be `needs_input` when source evidence is incomplete.

### Listing aggregate

Owns:

- original marketplace data,
- images,
- snapshots,
- seller metadata.

Implementation rules:

- `Listing` is tenant-owned and always queried through an explicit organization scope.
- `MarketplaceSource` is global connector configuration; the manual connector never fetches a URL.
- the initial source payload remains on the listing while every accepted change appends an
  immutable `ListingSnapshot`,
- snapshots include a sequence, capture time, actor, raw event payload, and content hash,
- external identifiers are scoped by organization, connector, and preserved marketplace context,
- image records contain private storage metadata, never a public filesystem URL.

### Owned-product intake aggregate

Owns:

- the user's explicit owned-product facts,
- ordered target countries and sale preference,
- immutable intake snapshots,
- private product, serial-label, defect, and proof-of-purchase images.

Implementation rules:

- `OwnedProduct` is tenant-owned and is always authorized through the active organization.
- it remains separate from marketplace `Listing`, Buy `Analysis`, canonical catalog matching,
  future `SaleListing`, and Phase 4 purchase/sale money records.
- an optional category may reference active global catalog data, but intake never creates or
  silently matches a canonical brand, model, or variant.
- nullable age means unknown while zero means known zero; nullable accessories or defects mean
  unknown while an empty array confirms none. Purchase-history knowledge is explicit.
- one active continent and one to 20 ordered, unique, active countries from that continent are
  required. Interface locale never supplies market scope.
- accepted creation and material updates append `OwnedProductSnapshot` records containing sequence,
  actor, capture time, exact ordered countries, raw payload, and a stable content hash. Identical
  updates do not append duplicate snapshots.
- draft and ready records may transition between those states or to archived. Archived is terminal
  and rejects further edits or image mutations.
- image metadata uses generated private paths and signed, authenticated, tenant-authorized access;
  a failed batch removes every newly stored file and database row.
- `OwnedProductAssessment` is append-only evidence bound by composite foreign key to the exact
  owned product and snapshot. It also records the stable hash of the current ordered image manifest.
- assessment identity includes product, snapshot, image hash, matcher version, and evaluator version;
  an identical command returns the existing run while changed evidence appends a new run.
- current projection requires matching snapshot ID/content hash, image-evidence hash, matcher
  version, and evaluator version. Otherwise the bounded assessment history remains visible but is
  explicitly historical.
- assessment status is `ready`, `needs_input`, or `review_required`; confidence/completeness use
  integer basis points and missing facts remain language-neutral codes.
- the assessment may reference an existing global category/model/variant but cannot create or
  silently choose catalog data. Ambiguous candidates and review actions are preserved as evidence.

### Sell price-intelligence aggregate

Owns:

- manual or approved connector evidence for one exact current owned-product assessment,
- market-scoped deterministic include/exclude decisions,
- three reproducible Sell asking-price bands,
- complete confidence, unknown, verification, outlier, and replay provenance.

Implementation rules:

- `SellComparableRecord` is tenant-owned, append-only, and linked through composite foreign keys to
  the same owned product and exact `OwnedProductAssessment`. It cannot copy a Buy comparable.
- original source identity, money, country, timestamps, classification, condition, accessories,
  source reliability, raw input, evidence hash, assessment input hash, and actor are preserved.
- only a current `ready` assessment with a confirmed canonical model accepts evidence. Changed
  intake or images cause stale commands to fail before any row is committed.
- `SellComparableSelection` and `SellComparableSelectionItem` preserve a bounded, versioned,
  idempotent candidate run. V2 considers only the exact assessment/model candidate pool and
  explicitly excludes unsafe classifications, broken items, duplicates, incompatible variants,
  and every cross-market/cross-currency item without exact compatible normalization evidence.
- `SellComparableMarketNormalization` is tenant-owned append-only evidence for one exact owned
  product, assessment, comparable, and target country/currency. It preserves compatibility,
  original and converted money, immutable dated FX provenance, bounded market factor, explicit
  target-currency shipping/duty/tax/other costs, exact normalized amount, actor, reference/note,
  observation time, calculation version, input evidence, and hash.
- each assessed target country's default currency scope plus every explicitly observed alternate
  currency scope is refreshed in the same aggregate transaction. New comparable or normalization
  evidence therefore cannot leave a current target scope on an older candidate snapshot.
- `SellPriceBand` and `SellPriceBandItem` are append-only and link the exact assessment, selection,
  selected records, item weights, outlier decisions, and algorithm input hash.
- v2 consumes a selected normalization snapshot without a second FX lookup or conversion; invalid,
  missing, stale, incompatible, or mismatched normalization fails closed.
- v2 requires at least three selected values after MAD outlier removal. Quick-sale is Q1 through
  the clamped weighted median, recommended is Q1 through Q3, and ambitious is the clamped weighted
  median through Q3. These are evidence-derived asking-price bands, not sale guarantees.
- current projections require the current assessment plus configured selector and algorithm
  versions. Historical evidence and runs stay visible when any upstream evidence becomes stale.

### Sell listing-draft aggregate

Owns:

- the user's exact target asking price and selected guidance strategy,
- deterministic listing-language title and structured description,
- source-attributed disclosed facts,
- deterministic photo-readiness checklist,
- warnings, unknowns, required reviews, and replay provenance.

Implementation rules:

- `SellListingDraft` is tenant-owned and append-only. Composite foreign keys keep its organization,
  owned product, exact assessment, and exact price band inside one aggregate.
- only a current ready matched assessment and the latest complete version-valid price band for the
  explicitly submitted country/currency may generate a draft.
- target price uses integer minor units. The selected band's exact low/high values are stored; an
  out-of-range target requires a preserved override reason and remains reviewable.
- listing language is explicit and independent from UI locale, organization locale, market country,
  and currency. English, German, Spanish, French, and Serbian Latin templates are independently
  versioned.
- `SellListingDraftFact` stores every generated disclosure with its source kind, source ID, source
  field, explicit unknown state, localized text, and original value snapshot.
- `SellListingPhotoCheckItem` stores count thresholds, matched private image IDs, structural
  status, reason/action codes, and exact image metadata. Semantic visibility is never inferred from
  file metadata; it is marked `review_required`.
- identical exact input replays idempotently. Current projections select the latest run per
  country/currency/language with bounded SQL scope, while all older runs remain immutable history.
- no table in this aggregate represents publication, a sale portfolio, or actual money; those are
  owned by separate downstream aggregates.

### Sale-portfolio aggregate

Owns:

- entry of one exact review-complete listing draft into the operational sale portfolio,
- manual external-listing publication identity and advertised price,
- append-only price and lifecycle history,
- actor, occurrence/recording time, idempotency, and optimistic-concurrency provenance.

Implementation rules:

- `SalePortfolioEntry` is tenant-owned and append-only, links one exact owned product and listing
  draft, and snapshots all assessment/price/image/template/photo evidence hashes plus the initial
  asking price. The draft must currently be `ready` with `ready` photo evidence.
- `SalePortfolioEvent` is append-only. Its sequence and `previous_event_id` form the authoritative
  event head; commands must submit `expected_current_event_id`.
- allowed transitions are server-owned: draft may be published; listed may change price, reserve,
  withdraw, or expire; reserved may relist, withdraw, or expire; withdrawn/expired may relist.
- publication/relisting requires the full marketplace name/key, external ID/HTTPS URL, exact
  advertised price/currency, and external occurrence time. Other events copy the prior publication
  snapshot; price changes replace only exact price/currency.
- withdrawal requires a bounded reason code. Event time is monotonic and cannot be in the future.
  External marketplace identity cannot be attached to another tenant entry.
- UUID idempotency is tenant-scoped. Exact replay is inert; reuse with a changed payload and stale
  event heads return conflict without appending.
- source changes remain visible through `source_evidence_current=false` and prevent new publication
  or relisting. Operational withdrawal/expiry history remains possible.
- this aggregate has no `sold` transition, realized price, received money, marketplace credential,
  connector call, or external mutation.

### Product aggregate

Owns:

- category and brand,
- canonical product model,
- variants,
- variant market applicability,
- aliases,
- specifications.

Implementation rules:

- categories, brands, models, variants, market applicability, and aliases are global catalog data;
  tenant analyses never own or silently create canonical products.
- model numbers, SKUs, names, and aliases retain display values plus indexed normalized values.
- variant market records preserve regional facts such as voltage, plug type, measurement system,
  warranty applicability, and region-specific attributes.
- aliases may be global or explicitly limited by source and target country context.
- `ProductMatch` is tenant-owned, append-only evidence linked to one analysis and exact
  `AiAnalysis` attempt.
- each match records matcher method and version, immutable input hash, candidates, reason codes,
  confidence, status, review state, and any selected model or variant.
- ambiguous, low-confidence, or region-incompatible evidence requires review; unmatched evidence
  must never create or select a canonical record implicitly.
- operator review may update only the explicit review fields. Historical matching evidence remains
  immutable and later recalculation appends a new result.

### Comparable evidence aggregate

Owns:

- original source identity and timestamps,
- canonical model and optional variant link,
- original market and monetary facts,
- normalized listing classification,
- append-only selection decisions and ranking evidence.

Implementation rules:

- `ComparableRecord` is tenant-owned, append-only source evidence. Its original price, currency,
  country, source identity, source timestamps, raw input, and evidence hashes cannot be edited.
- `ComparableMarketNormalization` is tenant-owned, append-only evidence for one exact analysis and
  comparable. It freezes source/target market and currency, source amount, compatibility decision,
  dated exchange-rate provenance, market factor, target-currency shipping/duty/tax/other costs,
  exact normalized amount, actor, observation time, evidence reference/note, version, and hash.
- a cross-country or cross-currency comparable remains excluded until an authorized analyst records
  explicit evidence. Compatible evidence uses
  `normalized = round_half_even(FX(source) × market_factor) + landed_costs`; incompatible evidence
  records a rejection without a monetary result. Same-market/same-currency comparables cannot be
  normalized.
- manual evidence is accepted only through an approved manual connector and only after a confirmed
  canonical product match. A supplied variant must belong to the matched model.
- source observations are deduplicated by normalized source identity. A newer observation may
  supersede an older candidate without deleting either record.
- `ComparableSet` is tenant-owned and linked to one analysis and exact `ProductMatch`. It stores the
  selector version, stable input hash, market boundary, run number, bounded candidate counts,
  minimum required count, status, and reason codes.
- every `ComparableSetItem` records include/exclude decision, optional rank, total score, factor
  scores, reason codes, and an evidence snapshot. Unsafe listing types, broken condition,
  incompatible variants, and unproven country or currency mixing remain explicit exclusions.
  Accepted cross-market items freeze their exact normalization snapshot into the selection input
  hash; replacement evidence appends a new selection run without rewriting history.
- readiness means only that enough safe comparable evidence exists. It does not itself calculate a
  price estimate.

### Price-estimation aggregate

Owns:

- immutable dated exchange-rate evidence,
- original and normalized comparable amounts,
- statistical inclusion and outlier decisions,
- price bands, dispersion, confidence, and calculation provenance.

Implementation rules:

- `ExchangeRate` is global immutable reference evidence. It stores the exact decimal base/quote
  rate, provider and provider reference, effective/published/fetched timestamps, evidence hash, and
  raw evidence. A provider observation is never updated in place.
- the dated resolver accepts explicit source currency, target currency, and calculation timestamp.
  It records identity, direct, or inverse direction and rejects missing, stale, or not-yet-known
  evidence.
- `PriceEstimate` is tenant-owned, append-only, linked to one exact analysis and ready
  `ComparableSet`, and uniquely identified by its algorithm version and stable input hash.
- `PriceEstimateItem` preserves every bounded input decision, including outliers and unresolved
  conversions. Replay never consults an unrecorded current rate.
- the v2 estimate is a deterministic weighted median with Q1/Q3 bands and
  median-absolute-deviation evidence. High dispersion remains visibly low confidence.
- a selected cross-market item consumes only its frozen compatible normalization snapshot. The
  estimator neither resolves a new rate nor applies conversion a second time. Invalid or mismatched
  normalization evidence produces `needs_input`.
- no condition, accessory, shipping, tax, customs, transaction-price, trend, or profit adjustment
  may be invented when its input is unavailable.
- unproven cross-market selection remains forbidden. This Buy boundary does not create default
  country factors, customs/tax estimates, live FX ingestion, or reuse the independently implemented
  Sell normalization ledger.

### Risk-assessment aggregate

Owns:

- the exact upstream evidence chain and evaluator version,
- a bounded reproducible score and documented risk level,
- confidence components and explicit unknown count,
- immutable signal evidence and required verification actions.

Implementation rules:

- `RiskAssessment` is tenant-owned, append-only, and linked to one exact `Analysis`,
  `ProductMatch`, `ComparableSet`, and `PriceEstimate`.
- evaluator version plus a stable input snapshot/hash form an idempotent assessment key; replay
  returns the existing run and never rewrites evidence.
- the score is the bounded sum of persisted signal contributions and must map exactly to the
  documented 0-24 low, 25-49 medium, 50-74 high, and 75-100 critical bands.
- v1 scores only recorded asking-price deviation, low-confidence price evidence, and explicit
  cross-border context. Unknown seller, location, image, condition, ownership, serial, payment,
  shipping, and return facts contribute zero points, reduce confidence, and require verification.
- `RiskSignal` records are immutable and hard bounded. Each preserves code, category, severity,
  weight, contribution, source, confidence, evidence snapshot, and optional verification action.
- risk describes transaction uncertainty and must never be presented as a fraud verdict.

### Cost-and-profit aggregate

Owns:

- explicit purchase and additional-cost inputs,
- known-versus-unknown state for every cost category,
- the exact price and risk evidence chain,
- deterministic expected-profit formulas and confidence,
- immutable line-item calculation evidence.

Implementation rules:

- `CostInput` and `ProfitEstimate` are tenant-owned, append-only, and linked to one exact
  `Analysis`, `PriceEstimate`, and `RiskAssessment`; the profit record also links to its exact
  `CostInput`.
- a null amount is unknown while integer minor-unit zero is a known zero. The v1 boundary accepts
  only the exact price-estimate currency and performs no implicit conversion.
- cross-border v1 requires explicit transport, customs, tax, and regional-compatibility
  confirmation. Missing evidence produces `needs_input`.
- gross margin is expected sale price minus purchase price; total cost is purchase price plus every
  additional cost; expected net profit is expected sale price minus total cost. Margin and return
  on invested capital use exact basis-point arithmetic.
- negative expected profit remains a valid, reason-coded result. Unknown required costs leave
  precise total cost, net profit, margin, and return null.
- stable input hashes make an immediate identical submission idempotent. Any changed input,
  including returning to an older historical value, appends a new current run.
- `CostInputItem` and `ProfitEstimateItem` preserve every ordered category, amount, known state,
  source, and calculation snapshot required for replay.

### Opportunity-component aggregate

Owns:

- explicit logistics and sold-market observations,
- comparable depth and recency derived from one exact immutable set,
- separate deterministic logistics-simplicity and resale-demand scores,
- confidence, unknown state, reasons, and ordered contribution evidence.

Implementation rules:

- `OpportunityInput` is tenant-owned, append-only, and linked to one exact `Analysis`,
  `ComparableSet`, `PriceEstimate`, `RiskAssessment`, `CostInput`, and `ProfitEstimate`.
- `OpportunityInputItem` keeps user-confirmed and derived evidence separate by component, position,
  code, typed value, required/known state, source, and evidence snapshot.
- logistics v1 records shipping method, distance, pickup, tracking, insurance, packaging,
  transport-cost certainty, regional compatibility, and cross-border handling. Unknown facts are
  never interpreted as simple logistics.
- demand v1 keeps comparable count/median age separate from explicit sold-comparable count,
  observation window, median days to sale, observation timestamp, and attributable source.
  Asking-price comparables do not prove completed sales.
- `OpportunityAssessment` stores exactly one `logistics` or `demand` result for one immutable input.
  Each component is normalized to 0-100 only when all required evidence is known; otherwise its
  score is null and status is `needs_input`.
- `OpportunityAssessmentItem` preserves each bounded criterion's maximum points, exact
  contribution or unknown state, input-item link, and source snapshot. Logistics contributions sum
  to at most 100; demand contributions sum to at most 100.
- immediate identical input is idempotent. Changed evidence, including historical-value reversion,
  appends new input and component runs. Any new upstream profit evidence invalidates current
  opportunity projections until reconfirmed.

### Deal-score aggregate

Owns:

- one final weighted opportunity score for one exact immutable evidence chain,
- uncapped and capped basis-point values,
- a recommendation, confidence, cap decisions, factors, assumptions, and next checks,
- five ordered raw, normalized, weighted, and source-linked component items.

Implementation rules:

- `DealScore` is tenant-owned, append-only, and links the exact `ProductMatch`, `PriceEstimate`,
  `RiskAssessment`, `ProfitEstimate`, `OpportunityInput`, logistics assessment, and demand
  assessment used in the calculation.
- `DealScoreItem` preserves component position, weight, raw value/unit, normalized basis points,
  weighted contribution, evidence confidence, impact, and source snapshot.
- `deterministic-deal-score:v1` uses weights of 35% expected net margin, 25% price confidence, 15%
  demand, 15% inverse risk, and 10% logistics. Net margin is explicitly normalized from 0% to 40%;
  negative raw margin remains preserved and contributes zero.
- critical risk, low price confidence, and unknown product model create explicit cap decisions of
  40, 60, and 50. The lowest applicable cap limits the final score.
- missing component scores produce `needs_input`, `insufficient_data`, and null precise score
  values. Immediate identical evaluation is idempotent; changed or reverted upstream component
  runs append a new DealScore.
- current API projection is valid only while every linked upstream identifier remains current.
  Upstream changes clear the DealScore projection without deleting its historical record.

### Buyer-decision aggregate

Owns:

- the current workflow decision for one exact DealScore,
- immutable prior and next state,
- actor, server timestamp, optional reason/note, and idempotency evidence,
- append-only decision history for the analysis.

Implementation rules:

- `BuyerDecisionEvent` is tenant-owned and append-only, and links one exact `Analysis`, current
  `DealScore`, and actor.
- sequence is monotonic per analysis. The current event is the latest event for the current
  DealScore; a new score begins with no current decision while retaining all earlier events.
- initial state may be `interested`, `contacted`, `purchased`, `rejected`, or `archived`.
- allowed later transitions are `interested -> contacted|purchased|rejected|archived`,
  `contacted -> purchased|rejected|archived`, `purchased -> archived`,
  `rejected -> interested|archived`, and `archived -> interested`.
- the request carries `expected_current_event_id`; a mismatch is an optimistic-concurrency
  conflict and must never overwrite or append from stale state.
- a tenant-scoped UUID idempotency key plus stable payload hash makes exact replay inert and rejects
  key reuse with changed payload.
- reason code and note are optional but hard bounded. Event timestamps are server-authoritative.
- buyer decision `purchased` is not a `Purchase` or transaction outcome and stores no actual money.

### Transaction outcome aggregate

Owns:

- versioned actual purchase evidence,
- versioned actual-cost snapshots and ordered category items,
- versioned actual sale/cancelled/no-sale evidence linked to exact sale-portfolio events,
- immutable realized-profit calculations.

Implementation rules:

- `ActualPurchase`, `ActualCostSnapshot`, `ActualCostItem`, `ActualSale`, and `RealizedProfit` are
  append-only. Model-level update/delete guards protect individual records; aggregate deletion may
  cascade with the owned product.
- tenant-scoped UUID idempotency plus stable payload hashes makes exact replay inert and rejects key
  reuse with changed commands. Purchase/cost/product-level and sale/entry-level sequences plus
  expected-current IDs provide optimistic concurrency; every correction requires a bounded reason.
- each known monetary fact stores original integer minor units/currency and reporting integer minor
  units/currency. Conversion stores identity or exact immutable exchange-rate ID, normalized direct
  or inverse decimal, effective time, provider/reference, and calculation time.
- historical cross-currency resolution chooses the latest exact rate whose effective time is not
  after the financial event and whose evidence was fetched by calculation time. Missing rate
  evidence rejects the realized command rather than inventing conversion.
- an actual-cost snapshot contains exactly transport, repair, platform fees, payment fees, customs,
  tax, marketing, and other costs. Each item independently records known/unknown state, time, money,
  provenance, and evidence hash; null is unknown and integer zero is known zero.
- an actual sale is owned by one exact sale-portfolio entry and current event. `sold` requires
  listed/reserved state and positive realized money. `cancelled`/`no_sale` requires
  withdrawn/expired state, a reason, and no realized sale money. One product cannot have realized
  sold evidence on two different entries.
- the current listing-cycle start is the latest publication/relisting event at or before the
  confirmed outcome. Sale duration is stored from that exact event to outcome occurrence.
- realized sale evidence freezes further portfolio lifecycle events. A sold record may only be
  corrected by another sold version; advertised/reserved facts never become sale money.
- `RealizedProfit` is created only when current purchase, fully known current cost snapshot, and
  sold outcome share one reporting currency. It stores exact input IDs/hashes, calculation version,
  purchase, sale, additional costs, total invested, signed net profit, basis-point margin/return,
  duration, reasons, and replay snapshot. Missing or inconsistent input returns explicit unknowns.

### Estimate accuracy aggregate

Owns:

- append-only `OutcomeEstimateAttribution` versions,
- one immutable `EstimateAccuracyReport` per attribution,
- exact links to `OwnedProduct`, `RealizedProfit`, Buy `Analysis`, and `ProfitEstimate`.

Implementation rules:

- attribution is explicit manual evidence and is never inferred from names, canonical-product
  matches, marketplace identifiers, or buyer-decision state;
- both current attribution/report heads and both complete current realized/estimate evidence chains
  are locked and revalidated before appending a version;
- tenant-scoped UUID idempotency and a stable actor-bound payload hash make exact replay inert and
  reject changed reuse; every version after the first requires a correction reason;
- the report preserves calculation version/key, input hash/snapshot, original expected currency,
  realized reporting currency, and the exact identity/direct/inverse rate evidence available at
  the estimate calculation time;
- purchase, additional costs, sale proceeds, and signed net profit store source expected, converted
  expected, actual, signed error, absolute error, signed basis-point error, and absolute percentage
  error. Percentage calculations use integer half-even arithmetic and a configured bound;
- a zero expected denominator leaves percentage errors null with a reason. Missing/stale currency
  evidence makes all converted comparisons unavailable. Expected safety reserve and actual
  marketing make only the additional-cost metric non-comparable;
- actual sale duration remains preserved while expected duration and duration error remain
  explicitly unavailable until an upstream expected-duration contract exists;
- there is no aggregate “accuracy score”, and outcome/accuracy evidence is not consumed by current
  scoring, price intelligence, external AI, or training.

### Privacy-request aggregate

Owns:

- one subject-scoped `PrivacyRequest` projection;
- an append-only `PrivacyRequestEvent` chain;
- a nullable subject link plus normalized requester-email hash;
- workflow and privacy-notice versions, response target, residence country, preferred locale,
  server-derived blocker snapshot, and resolution time.

Implementation rules:

- public aggregate/event identifiers are ULIDs; command idempotency keys are UUIDs and are never
  returned by the API or rendered in Admin;
- one nullable unique active key enforces at most one non-terminal request per subject/type, while
  completed requests remain historical;
- every event stores sequence, previous event, prior/next status, actor/type, bounded reason/note,
  optional external evidence reference, occurrence time, idempotency key, and stable payload hash;
- the aggregate head is updated under a transaction and expected-current-event check. Exact replay
  is inert; stale state or changed key reuse returns a conflict;
- request/event updates and individual deletes are blocked. Event rows cascade only if the complete
  parent aggregate is removed by an approved future retention procedure;
- the subject foreign key is nullable with `nullOnDelete`, so the operational ledger can survive
  approved account erasure without preserving a duplicate clear-text email;
- deletion blockers are server-derived from retention review, business ownership, active
  subscriptions, and super-admin continuity;
- `approved`, `fulfilled`, and `rejected` transitions require an external evidence reference.
  Workflow fulfillment never performs export generation or account deletion itself.

## 3. Core identifiers

Use internal UUIDs or ULIDs for public-facing entities.

External marketplace IDs must never be treated as globally unique without source context.

Unique external listing key:

```text
marketplace_source_id + external_id
```

### Identity and tenancy identifiers

- `organizations.id` and `organization_user.id` are ULIDs.
- `users.id` remains an internal numeric key.
- A personal organization has a unique `personal_user_id`.
- Membership is an explicit entity with one unique organization/user pair, a role, and `joined_at`.
- `users.current_organization_id` is only the persisted default context. Every request resolver must
  still confirm that the user has a current membership.

Personal organization creation is idempotent and transactional. Registration must not persist a
user unless the organization, owner membership, and initial active context all persist.

## 4. Monetary data

All monetary records must include:

- amount,
- currency,
- optional conversion rate,
- conversion timestamp,
- original amount,
- normalized reporting amount where needed.

Never use floating-point columns for money.

The original amount and currency are immutable source facts. Converted amounts must include the
exchange-rate record, provider, exact rate, direction, effective timestamp, and calculation
timestamp. Price estimates must include the exact comparable set, algorithm/resolver versions,
country and currency scope, stable input hash, input snapshot, inclusion decisions, bands,
dispersion, and confidence basis.

## 5. Status enums

### AnalysisStatus

```text
draft
queued
processing
needs_input
completed
failed
archived
```

### ListingStatus

```text
active
reserved
sold
removed
expired
unknown
```

### PurchaseStatus

```text
planned
contacted
negotiating
purchased
rejected
cancelled
```

### SaleStatus

```text
draft
listed
reserved
sold
withdrawn
expired
```

### RiskLevel

```text
low
medium
high
critical
```

### RiskAssessmentStatus

```text
assessed
```

### RiskConfidenceLevel

```text
low
medium
high
```

### PriceEstimateStatus

```text
estimated
low_confidence
needs_input
```

### ExchangeRateResolutionStatus

```text
resolved
missing
stale
```

## 6. Data ownership

Every user-owned business record must belong to an organization.

Shared catalog and anonymized aggregate market intelligence may be global.

Global data:

- continents,
- countries,
- currencies,
- exchange-rate reference data,
- product categories,
- brands,
- canonical product models,
- public market-source configuration.

Tenant data:

- analyses,
- comparable records and selection sets,
- price estimates and estimate items,
- risk assessments and risk signals,
- saved searches,
- saved-search versions and match evidence,
- alerts,
- notification delivery/state logs,
- transactions,
- uploaded files,
- notes,
- organization settings.

Personal security data:

- Telegram connections and append-only connection events.

Personal connection records may support delivery for several entitled organizations, but every
alert, saved search, delivery log, entitlement check, and recipient check remains tenant-scoped.

## 7. Global market rules

Use continent only for navigation and broad discovery. Country is the primary boundary for marketplace availability, price comparison, taxes, customs, shipping, and legal rules.

Products are globally canonical, but variants may be region-specific. Regional attributes may include model number, voltage, plug type, measurement system, warranty applicability, and market-specific accessories.
