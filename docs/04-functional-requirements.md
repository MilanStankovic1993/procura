# 04 — Functional Requirements

## FR-001 Authentication

The system shall support:

- registration,
- login,
- logout,
- password reset,
- email verification,
- account deletion request.

### FR-001A Privacy requests

A verified user shall be able to submit and review personal `data_export` and `account_deletion`
requests without an active organization context. Submission requires the accepted current privacy
notice and a UUID idempotency key, permits an optional active ISO residence country and bounded
reason, and allows only one active request of each type per subject.

The system shall:

- snapshot workflow/privacy-notice versions, preferred locale, request time, a 30-day operational
  response target, and server-derived deletion blockers;
- retain only a normalized email hash outside the nullable user link so the audit survives eventual
  account deletion without retaining a duplicate clear-text address;
- append an immutable, monotonically sequenced event for every state change;
- require optimistic concurrency and exact idempotent replay for subject cancellation and operator
  transitions;
- permit subject cancellation only while the request is in a cancellable state;
- require a verified super administrator and external evidence reference for approved and rejected
  outcomes;
- reserve `fulfilled` for a request-type-specific operation that creates an immutable fulfillment
  receipt in the same transaction as the terminal event;
- expose a bounded personal history and localized event timeline while excluding internal hashes
  and idempotency values;
- keep the Filament resource read-only and perform transitions only through the audited operational
  command.

The data-export completion operation is disabled by default. It accepts only an approved
`data_export` request and requires the exact event head, exact approved inventory version, identity
evidence, private-artifact reference, SHA-256, bounded byte size, bounded future expiry, delivery
evidence, and UUID replay key. The subject API receives only a safe receipt projection, never the
artifact location, checksum, identity evidence, payload hash, or idempotency key. The bounded
delivery receipt reference remains visible in the existing subject-owned event timeline.

The workflow does not generate export archives or perform unreviewed automatic deletion. A
separate, disabled-by-default account-erasure operation accepts only an approved
`account_deletion` request. It requires the exact event head, erasure-inventory version, identity,
erasure-run, private-storage and processor evidence, one clearance reference for every snapshotted
blocker, and a bounded backup-purge deadline. Business ownership, active subscriptions and
super-admin status are recalculated live and must actually be absent. Known private files must
already be removed and verifiably absent. The terminal transaction removes the personal tenant,
personal access state/preferences/integrations, anonymizes invitation addresses, revokes
credentials, tombstones the user while retaining pseudonymous business/audit references, and
creates the immutable receipt and audit event. External object-store/processor/backup work remains
an owner-approved production procedure, not an inferred side effect.

## FR-002 Organizations

The system shall support:

- personal organizations,
- business organizations,
- organization switching,
- member invitations,
- owner and member roles.

## FR-003 Buy Analysis intake

The user shall be able to submit:

- source URL,
- marketplace name,
- title,
- description,
- asking price,
- currency,
- seller information,
- location,
- source country,
- target market country,
- product images,
- screenshots,
- notes.

## FR-004 Sell Analysis intake

The user shall be able to submit:

- product brand,
- model,
- category,
- condition,
- age,
- accessories,
- defects,
- purchase history,
- target continent and one or more target countries,
- cross-border sale preference,
- desired sale speed,
- images.

The intake is a tenant-owned `OwnedProduct` record, separate from marketplace-source `Listing`,
Buy `Analysis`, the future sale portfolio, and actual purchase/sale outcomes. Category is an
optional reference to an active canonical category; intake must never create or silently select a
canonical product model.

Unknown facts must remain distinguishable from confirmed facts: nullable age is unknown while
`0` is known zero, and nullable accessories/defects are unknown while an empty array confirms
none. Purchase history requires an explicit known flag. Target countries are ordered, unique,
limited to 20, and must belong to the selected active continent.

The lifecycle is `draft`, `ready`, or `archived`. Draft and ready records may move between those
states; archived is terminal and read-only. Every accepted material change appends an immutable
snapshot with actor, capture time, exact ordered target countries, raw payload, and content hash.
Images are private evidence separated by product, serial-label, defect, and proof-of-purchase kind.
This intake requirement does not itself calculate price bands, generate marketplace copy, create a
sale portfolio record, or record money. Those operations remain separate downstream aggregates.

## FR-005 Product identification

The system shall assess one exact immutable `OwnedProductSnapshot` plus the current ordered private
image-evidence manifest. The result shall identify or preserve as unknown:

- category,
- brand,
- model,
- variant,
- included accessories,
- missing accessories,
- condition,
- defects.

Each assessment is tenant-owned, append-only, and records its run number, actor, capture time,
snapshot content hash, image-evidence hash, complete input hash, matcher/evaluator versions,
confidence, completeness, bounded catalog candidates, language-neutral reason codes, unknown facts,
and required verification actions. Identical snapshot/image/version evidence must replay
idempotently. A later intake snapshot or image mutation makes the old result historical rather
than current.

The status is `ready`, `needs_input`, or `review_required`. Canonical matching reuses the global
catalog, aliases, variant market applicability, and deterministic matcher contract. Unmatched,
ambiguous, low-confidence, or market-incompatible evidence must not silently create or select a
category, brand, model, or variant. The identification boundary does not itself calculate Sell
price bands, generate listing content, publish a listing, create a sale portfolio, or record money.

## FR-006 Comparable offers

For Sell Analysis, the system shall accept only approved manual or connector-derived evidence
explicitly tied to the current ready `OwnedProductAssessment`. It shall preserve source identity,
original integer-minor-unit asking price and currency, country, publication and observation time,
classification, condition, accessories, source reliability, raw input, evidence hash, actor, and
assessment input hash as append-only tenant data.

The system shall store and rank candidate records based on:

- exact model,
- product variant,
- condition,
- accessories,
- location,
- time period,
- seller type,
- listing status.

Each deterministic selection run shall preserve every included and excluded candidate, rank,
basis-point factor scores, reason codes, source snapshot, selector version, market/currency scope,
minimum evidence count, stable input hash, and run number. A new observation may supersede an old
source observation without rewriting it. Unsafe listing types, broken condition, incompatible
variants, and unapproved currency mixing remain explicit exclusions.

Sell selection v1 is scoped to one country and currency. It must not copy Buy-analysis comparables,
scrape a marketplace, mix countries, or convert currencies implicitly. Adding evidence in another
currency refreshes every previously used currency scope for that country so current projections
cannot silently omit a new exclusion.

## FR-007 Price estimate

For every Sell selection run the system shall record a versioned, append-only price-band run. With
fewer than three eligible comparables it shall return `needs_input` and null precise bands. With
sufficient evidence it shall calculate:

- quick-sale price,
- recommended market price,
- ambitious price,
- purchase fair-value range,
- confidence score.

Sell v1 derives the quick-sale band from Q1 to the clamped weighted-median market anchor, the
recommended band from Q1 to Q3, and the ambitious band from that anchor to Q3. It records median,
weighted median, quartiles, median absolute deviation, dispersion, outlier decisions, confidence
components, assessment completeness, unknown facts, verification actions, exact selected evidence,
algorithm version, and stable replay input.

Asking prices must never be presented as completed transaction prices. Realized price and expected
sale duration remain explicit unknowns until attributable outcome evidence exists.

### FR-007A Sell listing draft and photo readiness

The user shall be able to generate one append-only Sell listing draft only when all of the
following are exact and current:

- a ready, canonically matched `OwnedProductAssessment`,
- a complete version-valid `SellPriceBand` for the explicitly chosen country and currency,
- the current private owned-product image manifest,
- a user-entered target asking price and selected quick-sale, recommended, or ambitious strategy,
- an explicitly selected listing language from English, German, Spanish, French, or Serbian Latin.

The target asking price shall use integer minor units. The selected band's low/high values are
stored with the draft. A target outside that range is allowed only with an explicit bounded reason
and produces a review warning; the system never silently chooses or overwrites the price.

The deterministic generator shall produce a bounded title, structured description, disclosed fact
items, and photo checklist. It shall preserve exact source identifiers/hashes, the actual template
content hash, the normalized photo-policy snapshot/hash, template/generator/photo-evaluator
versions, stable input hash, actor, generation time, completeness, unknown facts, warnings,
verification actions, and the full replay input. Identical input replays the same draft; changed
upstream evidence, template content, photo policy, or a changed version makes old drafts
historical.

Photo readiness shall evaluate product overview, multiple angles, configured resolution, serial or
model label, disclosed defects, included-accessory visibility, and private proof-of-purchase
exclusion. Structural absence is `needs_photos`; facts that image metadata cannot prove remain
`review_required` rather than being guessed.

This requirement does not publish a listing, call a marketplace API, create a sale-portfolio
record, or record an actual transaction.

### FR-007B Sale portfolio and manual publication history

The user shall be able to create an append-only sale-portfolio entry only from one exact current
`ready` `SellListingDraft` whose photo readiness is also `ready`. The entry shall preserve the
draft, assessment, price-band, image, template, generator, and photo-policy evidence chain, the
explicit market/language/strategy, and the initial target asking price in integer minor units.

The user shall be able to record append-only manual `published`, `price_changed`, `reserved`,
`withdrawn`, `expired`, and `relisted` events. Publication and relisting require marketplace name
and normalized key, external listing ID, HTTPS URL, exact advertised price/currency, and occurrence
time. Withdrawal requires a bounded reason code. Every command carries the expected current event
ID and a tenant-scoped UUID idempotency key; stale state and changed-key replay are conflicts.

The server shall expose only transitions allowed from the current state, preserve every earlier
price and lifecycle event, reject non-monotonic event times and cross-entry external identities,
and keep stale source evidence visible as historical. Publication/relisting from stale source
evidence is prohibited. Asking prices are not received money, `sold` is not available in this
boundary, and no marketplace API call or actual transaction record is created.

## FR-008 Cost calculation

The system shall accept:

- purchase price,
- transport,
- repair,
- platform fees,
- payment fees,
- customs,
- tax,
- other costs,
- safety reserve.

Each amount shall use integer minor units in the exact price-estimate currency. `0` is a known zero;
an absent value is unknown and must never be silently converted to zero. Inputs and their line items
shall be tenant-owned, versioned, append-only, and linked to one exact analysis, price estimate, and
risk assessment. The first calculation version shall not perform implicit currency conversion.
Cross-border calculations require explicit transport, customs, tax, and regional-compatibility
confirmation before a precise result is allowed.

## FR-009 Profit calculation

The system shall calculate:

- gross margin,
- total cost,
- net profit,
- profit margin,
- return on invested capital.

The calculation shall use deterministic exact-money arithmetic and persist every formula
contribution, input snapshot, version, stable hash, confidence component, and reason code. Changed
costs append a new run; an immediate identical replay returns the current run. Negative profit is a
valid explainable result. Any unknown required cost produces `needs_input` with null precise totals
and ratios rather than false precision.

## FR-010 Risk assessment

The system shall calculate:

- risk score,
- risk level,
- individual risk signals,
- required verification actions.

## FR-011 Deal score

The system shall calculate and explain:

- final score,
- margin component,
- confidence component,
- demand component,
- risk component,
- logistics component.

Before the final score is calculated, resale demand and logistics simplicity shall each have their
own tenant-owned, versioned assessment linked to the exact current comparable, price, risk, cost,
and profit evidence chain. Shipping method, distance, pickup, tracking, insurance, packaging,
cross-border handling, comparable depth/recency, observed sold count, observation window, sale
velocity, and source attribution shall remain separate evidence. Unknown required evidence shall
produce `needs_input` and a null component score; it must not be treated as favorable. Asking-price
comparables alone shall never be presented as proof of completed-sale demand.

The final score shall consume those exact current component runs plus the exact product match,
price confidence, risk assessment, and expected-profit run. V1 shall use the documented
`35/25/15/15/10` weights, explicit 0%-to-40% net-margin normalization, integer basis-point
arithmetic, exact cap decisions, and recommendation thresholds. It shall persist uncapped and
capped values, every raw and weighted component, confidence, factors, assumptions, next checks,
version, stable hash, and input snapshot. Missing or stale required evidence shall produce
`insufficient_data` with no precise score.

### FR-011A Buyer decision status

After an assessed current DealScore exists, an authorized tenant member shall be able to append a
buyer-decision event with one of:

```text
interested
contacted
purchased
rejected
archived
```

The first decision for a DealScore may use any documented state, matching the Buy Analysis result
flow. Later transitions are limited to:

```text
interested -> contacted | purchased | rejected | archived
contacted  -> purchased | rejected | archived
purchased  -> archived
rejected   -> interested | archived
archived   -> interested
```

Submitting the current state again is not a transition. Every event shall be tenant-owned,
append-only, linked to the exact current DealScore, attributed to its actor, server-timestamped, and
preserve immutable prior/next state plus optional bounded reason code and note. The client shall
submit the expected current event identifier for optimistic concurrency and a UUID idempotency key.
An exact replay returns the original event; reuse of that key for another payload is rejected.

A new DealScore has no current buyer decision until a new event is recorded against it. Historical
events remain visible and are never reinterpreted against the new score. The `purchased` decision is
only a workflow signal: it shall not create a Purchase, actual cost, sale, or realized-profit record.
Those remain FR-013 and Phase 4.

## FR-012 Sell recommendation

The system shall generate:

- recommended listing title,
- recommended description,
- photo checklist,
- proof checklist,
- pricing strategy,
- price-reduction schedule.

## FR-013 Outcome tracking

The user shall be able to record:

- actual purchase price,
- actual transport, repair, platform, payment, customs, tax, marketing, and other costs,
- actual listing date,
- actual sale date,
- actual sale price,
- actual net profit,
- cancellation or no-sale reason.

Implementation contract:

- actual purchase, cost snapshots, sale outcomes, and realized-profit calculations are separate
  tenant-owned append-only evidence from estimates, buyer decisions, listing drafts, advertised
  prices, and sale-portfolio lifecycle events;
- every realized amount preserves original integer minor units/currency, actor, occurrence time,
  evidence kind/reference, input hash, and immutable replay snapshot;
- reporting conversion must use identity or one exact immutable exchange-rate record effective no
  later than the source event;
- purchase, cost, and sale corrections append a new optimistic-concurrency head with a reason;
- a sold outcome requires the exact current listed/reserved portfolio event, while cancelled or
  no-sale outcomes require withdrawn/expired state and contain no realized sale money;
- blank actual cost is unknown and integer zero is known zero;
- actual net profit is stored only for one complete current purchase, fully known cost snapshot,
  and sold outcome in one reporting currency. Otherwise the API and UI return explicit unknowns.

Estimate-accuracy reporting is a separate downstream calculation and must never change the
realized evidence chain. One explicit tenant-owned append-only attribution links the complete
current `RealizedProfit` to one exact current Buy `Analysis` and `ProfitEstimate`; product names,
catalog matches, marketplace identifiers, and buyer-workflow state must never infer that link.
Each attribution correction requires both current attribution/report heads, a reason, actor,
provenance, UUID idempotency, and stable hashes.

The immutable accuracy report compares purchase price, additional costs, sale proceeds, and signed
net profit in the realized reporting currency. Identity or one exact rate available at the
estimate calculation time is preserved; missing or stale rate evidence returns `unavailable`.
Every comparable metric stores expected and actual minor units, signed and absolute minor error,
and bounded signed/absolute basis-point error. Zero expected values, expected safety reserve versus
actual marketing, and absent expected sale duration remain explicit rather than producing invented
percentages or precision. Accuracy evidence must not feed DealScore, price intelligence, external
AI, or model training without a separately documented governance boundary.

## FR-014 Saved searches

The system shall support criteria for:

- category,
- brand,
- model,
- price,
- continent,
- one or more countries,
- city,
- radius,
- cross-border inclusion,
- minimum profit,
- minimum margin,
- minimum deal score,
- maximum risk.

Each saved search is tenant-owned and has one logical identity with append-only immutable criteria
versions. Create, update, pause, resume, and archive commands require role authorization,
backend-enforced plan limits, a UUID idempotency key, and, after creation, the expected current
version head. Archived searches are terminal. Exact catalog identifiers, ISO market/currency
codes, normalized keyword lists, money in minor units, and percentage/score basis points are
stored in both typed columns and a hashed criteria snapshot.

Matching runs asynchronously in bounded chunks for the latest listing snapshot and current active
search version only. Every evaluation is immutable and preserves the exact listing snapshot,
available analysis evidence, matcher version, reason codes, and unknown criteria. Missing
coordinates, canonical product evidence, profit, margin, deal score, or risk evidence must produce
an explicit `insufficient_evidence` result rather than an invented match.

## FR-015 Alerts

The system shall support:

- in-app notifications,
- email,
- Telegram.

One deduplicated tenant- and recipient-bound alert is created for a deterministic match. In-app
delivery appends `delivered`, `read`, `unread`, and terminal `archived` events; recipient inbox
queries use the current channel head, cursor pagination, and active organization boundary.

Email is an optional saved-search channel only when the active backend plan enables it. It uses a
separate queue with bounded exponential retries and an append-only
`queued -> attempting -> delivered|failed|exhausted|suppressed` delivery ledger. Successful replay
is inert, stale attempts stop automatically rather than risking an untracked duplicate, orphaned
retryable heads are recovered by the scheduler, and content uses the recipient's stored locale.

Telegram is an optional channel only when the active backend plan enables it, the platform bot is
fully configured, and the recipient has an active personal connection. A one-time `/start`
challenge may be claimed only from the same Telegram user and private chat. Challenges and
Telegram identifiers are encrypted at rest; keyed hashes enforce identity ownership without
indexing ciphertext or plaintext. Webhooks require the configured Telegram secret header.
Revocation immediately blocks new delivery attempts. The adapter uses the same append-only terminal
delivery states, bounded attempts, scheduler recovery, recipient locale, and immutable alert
evidence as email. Provider credentials, challenge values, identifiers, and outbound request URLs
must never appear in API responses or operational failure summaries.

## FR-016 Admin review

Administrators shall be able to:

- confirm product matches,
- create aliases,
- override estimates,
- rerun pipelines,
- review failed jobs,
- review high-risk results.

## FR-017 Subscription billing and enforcement

The backend shall enforce:

- analysis limits,
- saved-search limits,
- team-member limits,
- notification-channel limits,
- report limits,
- owner-only hosted Checkout and billing-portal access,
- tenant-scoped idempotency for Checkout creation,
- exact server-configured Stripe Price mappings rather than browser-supplied amounts,
- signature verification for every Stripe webhook,
- durable idempotent and out-of-order-safe subscription-event projection,
- paid entitlements only for explicitly configured entitled provider states,
- Free as the safe fallback for unknown prices and non-entitled states.

A browser redirect from Checkout is never proof of payment or entitlement. Only the signed provider
webhook may synchronize the local subscription and change a provider-owned plan assignment. Manual
administrator assignments remain an explicit boundary and cannot be overwritten by self-service
billing.

## FR-018 Exports

Business users should later be able to export:

- analysis reports,
- opportunity lists,
- profit records,
- sourcing comparisons.

## FR-019 Global market context

The system shall support:

- ISO 3166-1 alpha-2 country codes,
- ISO 4217 currency codes,
- BCP 47 language tags,
- IANA time zones,
- continent-to-country selection,
- original and reporting currencies,
- dated exchange rates used in calculations,
- metric and imperial product attributes,
- explicit origin and destination countries for cross-border costs.

The system shall also support a personal interface-language preference using a validated BCP 47 tag.
The initial supported values are `en`, `de`, `es`, `fr`, and `sr-Latn`; English is the deterministic
fallback. An authenticated user's preference shall persist independently from organization
preferences. Selecting an interface language must never imply or replace a source market,
destination market, listing language, country, currency, timezone, or measurement system.

All Angular routes and reusable feature panels shall use typed translation keys for user-facing
copy. A feature is incomplete if any supported locale lacks a key or if a template/component
introduces hard-coded interface text. Country, currency, number, date, and money presentation shall
use the active interface locale while preserving ISO codes and exact stored amounts.

## FR-020 Broker and sourcing requests

Verified organization members with `broker-requests.view` shall be able to read only their active
organization's sourcing requests. Members with `broker-requests.manage` shall additionally be able
to:

- create and edit a bounded draft containing the requested product, condition, quantity, optional
  maximum budget, target countries, needed-by date, and notes;
- submit a draft against the exact current event and a UUID idempotency key;
- consume the plan feature `broker_requests.monthly` exactly once on first submission;
- cancel a request while it remains in a subject-cancellable state;
- compare tenant-safe presented offers with an exact item/shipping/tax-duty/other-cost breakdown,
  validity, delivery, condition, warranty, and return terms;
- accept one unexpired offer against the exact current request and offer event heads, atomically
  marking all other presented offers as not selected;
- inspect the complete bounded request event timeline without internal hashes, snapshots,
  idempotency keys, or operator-only evidence.

Every mutation shall be tenant-scoped, authorized on the server, protected by an exact event-head
check, and recorded as an immutable previous-event-linked snapshot. Reusing an idempotency key with
changed input shall fail closed. A verified super administrator may move a submitted request to
`reviewing`, then `searching`, or cancel it only through the audited command with an external
evidence reference. `offers_available`, `accepted`, and `completed` are reserved for dedicated
offer/transaction application actions and shall not be written by a generic status transition.

The delivered offer boundary is manual and evidence-bound: a verified super administrator presents
immutable supplier terms only from `searching`/`offers_available`. Tenant projections omit the
private supplier reference, operator evidence, hashes, snapshots, and replay keys. Different
currencies shall not be ranked without separate current normalization evidence.

Every newly presented offer shall disclose the server-authoritative commission rule version, rate
in basis points, exact commission base, exact commission amount, and exact customer-payable total
before acceptance. The commission base is the complete supplier-offer total. Calculation uses
integer minor units and deterministic half-up rounding; overflow beyond the JavaScript-safe integer
boundary fails closed.

When transaction writes are enabled, accepting an offer shall atomically create exactly one
`BrokerTransaction`, its opening event, exactly one `BrokerCommission`, and its opening event.
The initial transaction state is `awaiting_payment`; the initial commission state is `pending`.
Verified super administrators may append evidence-bound transaction transitions only through:

```text
awaiting_payment -> payment_confirmed -> supplier_ordered -> shipped -> delivered -> completed
       \
        +-> cancelled
```

Every transition requires the exact current transaction event, a UUID idempotency key, reason,
external evidence reference, and occurrence time. Completion atomically changes the broker request
from `accepted` to `completed` and the commission from `pending` to `earned`. Pre-payment
cancellation atomically changes the request to `cancelled` and the commission to `waived`.
An earned commission may be changed to `settled` only by its dedicated evidence-bound operation.

These records are an operational evidence ledger, not a payment processor. Procura shall not
collect card data, hold or transfer funds, initiate a supplier purchase, infer payment from a
browser redirect, or contact a payment/supplier provider in this boundary. Provider integrations,
refund/dispute handling, automated supplier communication, and marketplace mutation remain
separately approved procedures.

When broker payment-case writes are enabled, a verified super administrator may open a dedicated
`refund` or `dispute` case only after the transaction has reached `payment_confirmed`. Opening
requires the exact current transaction event, a transaction-scoped UUID idempotency key, an amount
greater than zero and no greater than the immutable customer-payable total, a bounded external case
reference, reason, and evidence reference. The case currency is copied from the transaction and can
never be supplied independently or changed.

Payment cases use their own immutable previous-event-linked lifecycle and never rewrite the
transaction, request, offer, report, or commission ledgers:

```text
open -> under_review -> resolved
  \          \
   +----------+-> cancelled
```

Resolution requires a type-compatible outcome and reviewed external evidence. Refund cases accept
only `refund_confirmed` with a positive resolved amount no greater than the requested amount, or
`refund_rejected` with zero resolved amount. Dispute cases accept only `dispute_won` with zero
resolved amount, or `dispute_lost` with a positive resolved amount no greater than the requested
amount. These names describe reviewed external evidence; Procura still does not move money, submit
a chargeback, reverse commission, or call a payment provider. Commission remediation remains a
separately approved finance operation.

Every payment-case mutation shall require a verified super administrator, exact current event
head, UUID idempotency, bounded reason and evidence, deterministic row locking, and a maximum
bounded history. Exact replay is inert; changed replay, a stale head, duplicate logical external
case, invalid state/type/outcome/amount, or disabled switch fails closed. Tenant projections expose
only safe type/status/amount/outcome/timeline facts and omit external case references, operator
evidence, snapshots, hashes, and replay keys. An open or under-review case in a personal
organization blocks account erasure.

When broker-report generation is enabled, a verified super administrator may generate a localized
PDF only for a `completed` transaction whose commission is `earned` or `settled`. Generation shall
require the exact current transaction and commission event heads, a UUID idempotency key, a bounded
reason, an external case reference, and one supported locale. The immutable report snapshot shall
contain only subject-safe request, accepted-offer, disclosed commission, transaction-status, and
bounded timeline facts. Private supplier references, operator evidence, event payloads, hashes,
replay keys, payment data, and credentials shall never enter the PDF.

Every generated report shall record its version, locale, exact source event IDs, per-transaction
sequence, snapshot/hash, private storage disk/path, SHA-256 checksum, byte count, page count,
generation time, and configured artifact expiry. Exact replay is inert; changed replay or stale
source heads fail closed. The artifact shall use a private disk, a short-lived signed tenant route,
authorization on every download, `private, no-store` response headers, and checksum/size
verification before streaming.

Expired artifacts shall be purged by a bounded scheduled operation. Purge shall append an immutable
event and retain the non-secret report metadata/snapshot while removing the private file. A purged
or expired report cannot be downloaded. Personal-account erasure shall fail closed while a broker
report artifact still exists in the personal organization.
