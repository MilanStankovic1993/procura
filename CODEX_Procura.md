# Procura — Codex Project Specification

## 1. Project overview

Build a global multi-tenant SaaS application called **Procura**.

The product helps individual buyers, resellers, brokers, and small companies analyze marketplace listings and determine:

- whether the asking price is attractive,
- the estimated market value,
- the likely resale margin,
- possible hidden costs,
- listing and seller risk,
- whether the listing matches the user’s saved search criteria.

The initial product niche is:

- professional power tools,
- global manual listing analysis,
- continent and multi-country market selection,
- manually added listings,
- pasted listing URLs,
- pasted listing text,
- uploaded listing images,
- AI-assisted listing analysis,
- estimated profit calculation,
- risk scoring,
- Telegram and email alerts.

Do not build an automated Willhaben scraper in the first version.

The system must be designed around marketplace connectors so that approved APIs, feeds, browser-extension imports, manual imports, CSV imports, and future authorized integrations can be added later. A continent is a discovery filter; country is the primary pricing, marketplace, shipping, customs, tax, and compliance boundary.

---

## 2. Technical stack

Use:

- PHP 8.3+ with the Intl extension
- Laravel 13
- MySQL 8+
- Redis
- Laravel Horizon
- Laravel Sanctum
- Angular 22 as the primary browser application
- TypeScript 6 with strict compiler settings
- Angular Router, HttpClient, Signals, Vitest, and Angular ESLint
- Filament v5
- Livewire and Blade only for Filament administration
- SCSS design tokens for Angular
- Laravel Scheduler
- Laravel Notifications
- Laravel Cashier with Stripe
- S3-compatible file storage in production
- local filesystem storage in development
- Pest for automated tests

Use queued jobs for all slow operations.

Do not run AI analysis, image processing, external API requests, notifications, or listing normalization directly inside HTTP controllers.

---

## 3. Product roles

Support the following roles:

### super_admin

Can access the full Filament administration panel and manage:

- users,
- organizations,
- plans,
- subscriptions,
- marketplace sources,
- listings,
- AI analyses,
- product matches,
- price estimates,
- risk assessments,
- alerts,
- broker requests,
- usage limits,
- application settings,
- failed jobs,
- audit logs.

### organization_owner

Can:

- manage the organization,
- invite and remove organization members,
- manage billing,
- create listing analyses,
- create saved searches,
- view organization reports,
- manage organization settings.

### organization_member

Can:

- create analyses,
- view organization listings and reports,
- create saved searches,
- receive notifications.

### individual_user

Can use the application without creating a separate company profile.

---

## 4. Multi-tenancy approach

Use a shared database with `organization_id` columns.

Do not use separate databases per tenant.

Rules:

- Every tenant-owned record must include `organization_id`.
- Individual users should automatically receive a personal organization during registration.
- Every user must belong to at least one organization.
- A user may belong to multiple organizations.
- The active organization must be resolved for every authenticated request.
- Use policies and query scopes to prevent cross-organization access.
- Never trust an `organization_id` coming from the frontend.
- Resolve the active organization from the authenticated user context.

Core tables:

```text
users
organizations
organization_user
```

Suggested `organization_user` columns:

```text
organization_id
user_id
role
joined_at
timestamps
```

---

## 5. Initial application modules

Build the application in modules.

### Module A — Authentication and organizations

Features:

- registration,
- login,
- password reset,
- email verification,
- personal organization creation,
- active organization switcher,
- organization member invitation,
- role-based authorization,
- user profile,
- organization settings.

### Module B — Listing intake

Users must be able to add a listing through:

1. Manual form
2. Pasted listing URL
3. Pasted listing description
4. Uploaded screenshots or product images
5. CSV import in a later phase
6. Browser extension in a later phase

Manual listing fields:

```text
title
source_url
source_name
external_id
description
asking_price
currency
seller_name
seller_type
seller_location
country
city
condition
published_at
notes
```

Allow multiple uploaded images per listing.

### Module B2 — Owned-product intake

The Sell workflow starts with a tenant-owned `OwnedProduct` aggregate that is separate from a
marketplace `Listing`, Buy `Analysis`, the future sale portfolio, and actual financial outcomes.

The intake stores:

```text
optional active product category
brand and model as user-provided facts
condition and nullable age in months
nullable accessories and defects
explicit purchase-history knowledge and context
one target continent and ordered target countries
cross-border preference and desired sale speed
draft, ready, or archived lifecycle
private product, serial-label, defect, and proof-of-purchase images
notes
```

Unknown must remain distinct from known zero or confirmed empty facts. Every material write appends
an immutable actor/time/hash snapshot; identical updates are idempotent. Archived intake is
terminal. Private image access requires a short-lived signed URL plus authentication, active
organization scope, and policy authorization. This module must not calculate price bands, generate
listing copy, create sale-portfolio records, or store purchase/sale money.

### Module B3 — Owned-product identification and condition assessment

Assessments are tenant-owned, append-only evidence linked to the exact `OwnedProductSnapshot` and
the stable ordered hash of current private images. Each run records matcher/evaluator versions,
input and evidence hashes, canonical category/model/variant references when supported, bounded
candidates, identity and condition facts, included/missing accessories, defects, confidence and
completeness basis points, language-neutral reason codes, unknown facts, and verification actions.

Identical evidence and provider versions replay idempotently. Any later intake or image change makes
the previous result historical. Matching reuses the global catalog contract and must never silently
create or select canonical data. Results are `ready`, `needs_input`, or `review_required`. This
module must not infer Sell prices, generate listing content, publish, create a portfolio record, or
record money.

### Module B4 — Sell comparable evidence and price bands

Sell pricing is a separate tenant-owned append-only aggregate anchored to one exact current ready
owned-product assessment. Approved manual or connector evidence preserves source identity, original
integer-minor-unit asking price, currency, country, timestamps, classification, condition,
accessories, reliability, raw input, actor, and hashes. It must never scrape or silently copy Buy
comparables.

Each versioned selector run is bounded to one country and currency and preserves included and
excluded candidates, rank, factor scores, reason codes, and source snapshots. Each versioned price
run preserves selected items, MAD outlier decisions, Q1/Q3, weighted median, dispersion,
confidence, completeness, unknown facts, verification actions, and the complete replay input.
Quick-sale spans Q1 to the clamped weighted median, recommended spans Q1 to Q3, and ambitious spans
the clamped weighted median to Q3. These are asking-price guidance bands, not guaranteed sale
prices. This module must not generate listing content, publish, create a portfolio record, or record
actual money.

### Module B5 — Sell listing draft and photo readiness

Listing preparation is a separate tenant-owned append-only aggregate anchored to one exact current
ready matched assessment, one exact current complete Sell price band, and the current private image
manifest. The user explicitly submits target country/currency, quick/recommended/ambitious
strategy, target asking price in integer minor units, and listing language. An out-of-band target
requires a recorded reason; the system never chooses a target price silently.

Each versioned draft preserves title, structured description, disclosed source facts, photo
checklist, assessment/price/image hashes, the actual template content hash, the normalized
photo-policy snapshot/hash, template/generator/photo-evaluator versions, actor, unknown facts,
warnings, review actions, stable input hash, and complete replay input. Templates support English,
German, Spanish, French, and Serbian Latin independently from UI locale and market scope. Image
metadata may prove kind, count, and dimensions but must not be used to invent semantic visibility.
This module must not publish, call marketplace APIs, create a portfolio record, record actual
money, scrape, or call external AI.

### Module B6 — Sale portfolio and manual publication history

One exact current `ready` listing draft with `ready` photo evidence may enter the tenant-owned
append-only sale portfolio. The entry snapshots all assessment, price, image, template, generator,
and photo-policy evidence plus the initial target asking price. This amount is asking-price
evidence, not received money.

Manual publication events preserve marketplace name/key, external listing ID/HTTPS URL, exact
advertised minor-unit price/currency, occurrence time, actor, prior event, UUID idempotency key, and
payload hash. Server-owned transitions cover publication, price change, reservation, withdrawal,
expiry, and relisting with optimistic concurrency and immutable history. Stale source evidence
blocks publication/relisting but remains visible. This module does not call marketplace APIs,
support a `sold` transition, or record realized financial outcomes.

### Module B7 — Transaction outcomes and realized profit

Actual purchase, actual costs, and actual sale are separate tenant-owned append-only evidence
streams anchored to an `OwnedProduct`. They never overwrite Buy estimates, buyer workflow
decisions, Sell price bands, listing drafts, advertised prices, or sale-portfolio events.

Every realized amount preserves the original integer minor units and currency, attributable
occurrence time, actor, evidence kind/reference, stable input hash, and a complete immutable
snapshot. Reporting-currency conversion is either identity or references one exact immutable rate
whose effective date is not after the financial event. Purchase and cost corrections append a new
optimistic-concurrency version with a bounded reason.

Actual costs preserve transport, repair, platform fees, payment fees, customs, tax, marketing, and
other costs independently. Null is explicitly unknown while zero is a known zero. Actual sale
outcomes are `sold`, `cancelled`, or `no_sale` and must link the exact current portfolio entry and
publication event. `sold` is permitted only from listed/reserved state and contains realized money;
cancelled/no-sale is permitted only from withdrawn/expired state and contains no sale money.

The system records actual net profit only when current purchase, complete cost snapshot, and sold
outcome use one reporting currency. The immutable calculation stores exact source IDs/hashes,
purchase, additional costs, total invested, sale proceeds, signed net profit, basis-point ratios,
and sale duration. Incomplete chains return explicit unknowns, never a partial precise result. This
module does not process payments, escrow funds, call marketplace APIs, or mutate external listings.

### Module B8 — Estimate attribution and accuracy

One tenant-owned append-only attribution explicitly links a complete current realized-profit chain
to the exact current Buy analysis and profit estimate that informed the transaction. Product names,
catalog matches, marketplace identifiers, and buyer-workflow state never infer this relationship.
Corrections append a new version with both expected attribution/report heads, actor, bounded
reason/provenance, tenant UUID idempotency, stable payload/input hashes, and the previous version.

Each immutable report preserves the exact expected and realized evidence IDs/hashes, calculation
version/key, original estimate currency, realized reporting currency, and identity/direct/inverse
dated exchange-rate evidence available at the estimate calculation time. Purchase, additional
costs, sale proceeds, and signed net profit store source expected, converted expected, actual,
signed/absolute minor error, and bounded signed/absolute basis-point error. Missing/stale FX, a zero
expected denominator, expected safety reserve versus actual marketing, and absent expected sale
duration remain explicit. There is no aggregate accuracy score, and this evidence does not feed
DealScore, price intelligence, external AI, or training.

### Module C — Listing normalization

Normalize:

- title,
- description,
- price,
- currency,
- country,
- city,
- brand,
- model,
- category,
- condition,
- included items,
- missing items,
- detected defects,
- risk signals.

Store the original values separately from normalized values.

Never overwrite original imported content.

### Module D — AI analysis

AI analysis should return structured JSON.

Required output:

```json
{
  "language": "de",
  "translated_title": "Makita cordless drill set",
  "translated_description": "Used Makita set with two batteries.",
  "category": "power_tools",
  "brand": "Makita",
  "model": "DHP486",
  "condition": "used_good",
  "included_items": [
    "case",
    "charger",
    "2 batteries"
  ],
  "missing_items": [],
  "detected_defects": [],
  "risk_flags": [
    "serial_number_not_visible"
  ],
  "estimated_repair_cost_min": 0,
  "estimated_repair_cost_max": 30,
  "confidence": 0.88
}
```

AI results must be validated before saving.

Do not assume AI output is always valid JSON.

Implement:

- JSON schema validation,
- retries for malformed responses,
- maximum retry count,
- analysis status,
- analysis error message,
- token usage,
- estimated API cost,
- model name,
- provider name,
- input hash,
- prompt version.

Do not re-run AI analysis when the same content hash and prompt version already have a successful result.

### Module E — Product catalog and matching

Core tables:

```text
product_categories
brands
product_models
product_aliases
listing_product_matches
```

`product_models` suggested fields:

```text
product_category_id
brand_id
name
model_number
slug
description
specifications_json
active
timestamps
```

`product_aliases` suggested fields:

```text
product_model_id
alias
normalized_alias
source
confidence
timestamps
```

`listing_product_matches` suggested fields:

```text
listing_id
product_model_id
match_method
confidence
confirmed_by_user_id
confirmed_at
timestamps
```

Matching methods:

```text
exact
alias
rule
ai
manual
```

The system must support manual correction from the admin panel.

### Module F — Price estimation

Price estimation must not rely only on AI.

Use comparable listings when available.

Suggested calculation:

```text
estimated_market_price =
median_comparable_price
× condition_factor
× included_items_factor
× age_factor
× region_factor
```

Store:

```text
price_estimates
```

Suggested fields:

```text
organization_id
listing_id
product_model_id
currency
estimated_price_min
estimated_price_mid
estimated_price_max
comparable_count
confidence
calculation_method
calculation_details_json
calculated_at
timestamps
```

The calculation must be reproducible from stored details.

Delivered Buy boundary: `ComparableRecord` and `ComparableSet` preserve immutable source and
selection evidence. `ComparableMarketNormalization` permits a cross-country or cross-currency
comparable only after an authorized analyst explicitly records compatible/incompatible evidence
for the exact analysis/comparable. Compatible evidence freezes dated FX provenance, a bounded
50.00%–150.00% market factor, explicit target-currency shipping/duty/tax/other costs, exact
half-even calculation results, actor, reference/note, timestamp, version, and hash. The v2 selector
and weighted-median estimator snapshot and replay that result without a second conversion.
Missing, stale, mismatched, or unconfirmed evidence fails closed; no factor or cost is invented.
Production FX and evidence governance is mandatory in `docs/19-production-go-live.md`.

### Module G — Profit calculator

Users must be able to enter or confirm:

```text
purchase_price
transport_cost
repair_cost
platform_fees
payment_fees
customs_cost
tax_cost
other_costs
safety_reserve
expected_sale_price
```

Calculate:

```text
gross_margin = expected_sale_price - purchase_price

total_costs =
purchase_price
+ transport_cost
+ repair_cost
+ platform_fees
+ payment_fees
+ customs_cost
+ tax_cost
+ other_costs
+ safety_reserve

net_profit = expected_sale_price - total_costs

profit_margin_percent =
net_profit / expected_sale_price * 100
```

Handle division by zero.

Store profit calculations so the user can compare the initial estimate with the actual outcome later.

### Module H — Risk engine

Create deterministic risk rules in addition to AI analysis.

Initial risk checks:

- price significantly below market,
- suspicious payment request,
- cryptocurrency payment request,
- off-platform payment request,
- urgency language,
- seller refuses inspection,
- seller refuses protected payment,
- missing serial number,
- unclear ownership,
- account-locked product,
- contradictory description,
- copied or duplicate text,
- no proof of purchase,
- unrealistic shipping offer,
- unknown seller location,
- newly created seller account when available.

Store each risk signal separately.

Tables:

```text
risk_assessments
risk_signals
```

Suggested risk score:

```text
0–24: low
25–49: medium
50–74: high
75–100: critical
```

Do not display “safe” or “guaranteed”.

Use wording such as:

```text
No obvious fraud indicators were detected, but the seller and product must still be independently verified.
```

### Module I — Deal score

Calculate a score from 0 to 100.

Initial weighting:

```text
35% estimated margin
25% price-estimate confidence
15% demand or resale potential
15% inverse risk score
10% logistics simplicity
```

Store the scoring components.

Do not store only the final score.

Table:

```text
deal_scores
```

Suggested fields:

```text
organization_id
listing_id
score
margin_component
confidence_component
demand_component
risk_component
logistics_component
calculation_details_json
calculated_at
timestamps
```

### Module J — Saved searches

Users can define monitoring criteria.

Fields:

```text
title
category
brand
model
min_price
max_price
currency
country
city
radius_km
required_keywords
excluded_keywords
minimum_profit
minimum_margin_percent
minimum_deal_score
maximum_risk_score
active
```

Initial MVP does not need automated marketplace crawling.

Saved searches should initially match:

- manually imported listings,
- authorized feed imports,
- CSV imports,
- future browser-extension submissions.

### Module K — Alerts and notifications

Supported channels:

- database notification,
- email,
- Telegram.

Current implementation boundary (2026-07-26): database/in-app, plan-entitled localized email, and
plan-entitled localized Telegram delivery are implemented with immutable delivery evidence,
bounded queue retries, orphan recovery, and read-only operations visibility. Telegram uses a
platform-managed bot, an explicit one-time private-chat connection, encrypted identifiers,
keyed identity hashes, a secret-authenticated webhook, revocation, and a separate notification
worker pool. Production mail and Telegram provider credentials and webhook registration are
deployment work, not application-domain assumptions.

Tables:

```text
alerts
notification_logs
telegram_connections
telegram_connection_events
```

Prevent duplicate alerts.

A unique alert can be based on:

```text
user_id
listing_id
saved_search_id
alert_type
```

Notification example:

```text
New potential deal

Makita DHP486
Listing price: €245
Estimated market value: €360–€410
Estimated net profit: €82
Deal score: 86/100
Risk: Medium
```

### Module L — Subscription and usage limits

Plans:

```text
Free
Starter
Pro
Business
```

Example limits:

#### Free

```text
5 analyses per month
1 saved search
email notifications
no Telegram
```

#### Starter

```text
50 analyses per month
10 saved searches
email and Telegram
basic price history
```

#### Pro

```text
250 analyses per month
50 saved searches
priority analysis
full risk report
profit tracking
```

#### Business

```text
custom analysis limits
multiple team members
organization reporting
broker requests
exports
priority support
```

Create tables:

```text
plans
plan_features
subscription_usages
```

Usage must be checked before dispatching paid operations.

Do not rely only on frontend checks.

### Module M — Broker requests

Allow users to create a sourcing or broker request.

Example:

```text
I need a used CNC machine under €40,000 in Austria or Germany.
```

Tables:

```text
broker_requests
broker_request_offers
broker_transactions
broker_commissions
broker_reports
broker_report_events
```

Statuses:

```text
draft
submitted
reviewing
searching
offers_available
accepted
completed
cancelled
```

The first version may be handled manually by administrators.

---

## 6. Core database tables

Initial database list:

```text
users
organizations
organization_user

plans
plan_features
subscription_usages

marketplace_sources
marketplace_imports
marketplace_import_rows

listings
listing_images
listing_snapshots

owned_products
owned_product_target_countries
owned_product_snapshots
owned_product_images

product_categories
brands
product_models
product_aliases
listing_product_matches

ai_analyses
comparable_records
comparable_market_normalizations
comparable_sets
price_estimates
profit_calculations
risk_assessments
risk_signals
deal_scores

saved_searches
saved_search_matches

alerts
notification_logs
telegram_connections

broker_requests
broker_request_offers
broker_transactions
commissions

audit_logs
application_settings
```

Use:

- foreign keys,
- indexes,
- unique constraints,
- soft deletes only where business restoration is needed,
- JSON columns for unstructured structured data,
- decimal columns for monetary values,
- ISO 4217 currency codes,
- UTC timestamps in the database.

Never use float columns for money.

Use decimal values such as:

```php
$table->decimal('asking_price', 12, 2)->nullable();
```

---

## 7. Marketplace connector architecture

Create an interface:

```php
<?php

namespace App\Contracts\Marketplaces;

use App\Data\MarketplaceListingData;
use App\Data\SearchCriteriaData;
use Illuminate\Support\Collection;

interface MarketplaceConnector
{
    public function key(): string;

    public function supportsAutomatedSearch(): bool;

    public function search(SearchCriteriaData $criteria): Collection;

    public function fetchListing(string $externalId): MarketplaceListingData;
}
```

Initial connectors:

```text
ManualListingConnector
CsvImportConnector
EmailImportConnector
PartnerFeedConnector
```

Future connectors:

```text
OfficialApiConnector
BrowserExtensionConnector
AuthorizedWillhabenConnector
```

Each connector must declare whether automated search is supported.

Do not bypass marketplace restrictions.

Do not implement browser automation, captcha bypassing, residential proxy rotation, stealth scraping, or automated account actions.

Current delivered boundary: a typed connector registry and `AuthorizedCsvConnector` provide
normalization without automated search or network access. Tenant CSV uploads require source-rights
attestation, private storage, UUID/content idempotency, full-file preflight, bounded queued
processing, immutable row outcomes, and listing snapshot provenance. Invalid rows are quarantined
and duplicate source identities do not rewrite listings. The production kill switch defaults off;
activation and every external-source approval belong in `docs/19-production-go-live.md`. Email,
partner-feed, official API, URL-assisted, and browser-extension connectors remain pending.

---

## 8. Queue jobs

Create queued jobs:

```text
NormalizeListingJob
AnalyzeListingWithAiJob
MatchListingToProductJob
CalculatePriceEstimateJob
CalculateRiskAssessmentJob
CalculateDealScoreJob
MatchSavedSearchesJob
SendDealAlertsJob
GenerateBrokerReportJob
ProcessListingPipelineJob
```

`ProcessListingPipelineJob` should coordinate the pipeline, but each processing step must remain independently retryable.

Recommended processing sequence:

```text
listing created
→ normalization
→ AI analysis
→ product matching
→ price estimation
→ risk assessment
→ profit calculation
→ deal score
→ saved search matching
→ notifications
```

Use unique jobs or locking where duplicate processing is possible.

Configure:

- retry counts,
- exponential backoff,
- job timeouts,
- failure handling,
- failed-job logging.

---

## 9. Application services

Keep business logic out of controllers and Filament resources.

Create services:

```text
ListingIntakeService
ListingNormalizationService
AiListingAnalysisService
ProductMatchingService
ComparableListingService
PriceEstimationService
ProfitCalculationService
RiskAssessmentService
DealScoreService
SavedSearchMatchingService
AlertService
SubscriptionUsageService
BrokerRequestService
```

Controllers should:

- validate requests,
- authorize actions,
- call a service,
- return a response.

---

## 10. DTOs and enums

Use DTOs for data crossing module boundaries.

Suggested DTOs:

```text
MarketplaceListingData
NormalizedListingData
AiListingAnalysisData
PriceEstimateData
ProfitCalculationData
RiskAssessmentData
DealScoreData
SearchCriteriaData
```

Use PHP backed enums for:

```text
ListingStatus
ListingCondition
SellerType
RiskLevel
AnalysisStatus
MatchMethod
AlertType
NotificationChannel
SubscriptionPlanCode
BrokerRequestStatus
CurrencyCode
```

---

## 11. AI provider abstraction

Create an abstraction:

```php
<?php

namespace App\Contracts\Ai;

use App\Data\AiListingAnalysisData;
use App\Models\Listing;

interface ListingAiAnalyzer
{
    public function analyze(Listing $listing): AiListingAnalysisData;
}
```

Implement:

```text
OpenAiListingAnalyzer
FakeListingAnalyzer
```

The fake provider is required for local development and automated tests.

Do not call external AI APIs in tests.

AI provider configuration must come from environment variables.

Example:

```env
AI_PROVIDER=openai
AI_MODEL=
AI_TIMEOUT=60
AI_MAX_RETRIES=2
AI_MONTHLY_BUDGET_EUR=100
```

Track estimated spend.

Stop or block new AI jobs when the configured monthly budget is exceeded, unless a super admin overrides it.

---

## 12. UI pages

### Public pages

```text
/
pricing
features
how-it-works
security
privacy
terms
login
register
```

### Authenticated application

```text
/dashboard
/listings
/listings/create
/listings/{listing}
/listings/{listing}/analysis
/app/sell
/app/sell/new
/app/sell/{ownedProduct}
/saved-searches
/alerts
/profit-tracker
/broker-requests
/organization
/billing
/app/privacy
/settings
```

### Privacy fulfillment boundary

Generic privacy workflow transitions must never write `fulfilled`. Each privacy request type needs
its own application action that validates the approved exact event head, verified super-admin
operator, UUID replay key, current execution/inventory versions, and type-specific evidence before
atomically appending the terminal event, immutable fulfillment receipt, and platform audit event.

Data-export fulfillment is disabled by default and records only evidence for an archive that was
assembled and securely delivered by an approved external procedure. It requires a private artifact
reference, SHA-256, exact byte size, bounded expiry, identity evidence, and delivery evidence. Never
expose artifact location/checksum, identity evidence, payload hashes, or idempotency keys through
subject/Admin projections; only the bounded delivery receipt reference belongs in the existing
subject event timeline. Account-deletion fulfillment uses its own disabled-by-default executor. It
requires exact snapshot clearances, recalculates live ownership/billing/admin blockers, verifies
known personal-tenant files are absent, revokes access, removes the personal tenant, and writes an
immutable receipt plus pseudonymous user tombstone. Never treat this database operation as proof of
external object-store, processor, log, analytics, queue or backup erasure; those remain
inventory-driven production evidence and the receipt carries the bounded backup-purge deadline.

### Interface localization

Every first-party Angular screen, reusable panel, and Filament administration resource ships in:

- English (`en`),
- German (`de`),
- Spanish (`es`),
- French (`fr`),
- Serbian Latin (`sr-Latn`).

All user-facing text uses typed translation keys. Adding a key requires all five catalog entries,
and the localization source check must pass. Interface locale is independent from market, country,
currency and source-listing language. Stored evidence values, ISO codes and immutable reason codes
must not be translated in persistence.

The localization contract applies equally to owned-product list, intake, detail, assessment,
validation, loading, error, empty, lifecycle, image, stale-evidence, and unknown-evidence states.
Filament uses the same authenticated personal preference, exposes its language action in the user
menu, and keeps admin authorization, organization context, and market scope independent from
locale. Custom admin labels and known values belong to the five `admin.php` catalogs; application
vendor overrides may fill documented upstream translation gaps but must never patch `vendor/`.

Laravel API validation follows the same personal locale. Authenticated preference overrides the
request header; guests resolve supported `Accept-Language` variants with English fallback.
Fortify/authentication/password-reset messages, every framework validation rule currently used by
Procura, and every current FormRequest attribute require EN/DE/ES/FR/sr-Latn entries. The resolved
locale is request-scoped and must be restored after success or failure so persistent PHP workers
cannot leak language state between users.

Expected billing, marketplace-import, privacy-request, buyer-decision, sale-portfolio, and outcome
API conflicts use the closed `ApiErrorCode` enum. The response preserves its language-neutral
`code`, while the request-scoped locale selects a safe message from the five `api_errors.php`
catalogs. Raw exception/provider diagnostics must never be rendered to a client. Adding a public
conflict code requires all five catalog entries and the enum/catalog contract test in the same
change.

Expected application-service `422` failures use the closed `ApplicationValidationCode` enum and
`ApplicationValidation` boundary. They preserve Laravel's field-keyed error shape while selecting
safe presentation from the five `application_validation.php` catalogs. Services must not embed
English validation copy or interpolate database/provider diagnostics into public errors. Adding a
code requires all five catalog entries and the enum/catalog contract test in the same change. The
current 138-code contract covers the platform, Analysis, OwnedProducts, privacy fulfillment,
broker-request, and broker-offer service validation tranches.

### Listing detail screen

Display:

- original title,
- translated title,
- source and URL,
- asking price,
- seller data,
- uploaded images,
- extracted brand and model,
- condition,
- included items,
- missing items,
- defects,
- estimated market range,
- estimated profit,
- risk score,
- risk signals,
- deal score,
- AI confidence,
- comparison listings,
- editable calculation inputs,
- user notes,
- analysis history.

Use visible warnings when confidence is low.

---

## 13. Filament administration

The entire operator surface, including navigation, resources, columns, actions, dashboard
statistics, modals, notifications, accessibility labels, known domain values, countries, and
currencies, must follow the same English/German/Spanish/French/Serbian-Latin locale contract as the
primary application.

Create Filament resources for:

```text
User
Organization
Plan
MarketplaceSource
Listing
ProductCategory
Brand
ProductModel
ProductAlias
AiAnalysis
PriceEstimate
RiskAssessment
DealScore
SavedSearch
Alert
BrokerRequest
ApplicationSetting
AuditLog
```

Create an admin dashboard with:

- new users,
- active subscriptions,
- analyses this month,
- AI cost this month,
- failed analyses,
- unmatched listings,
- average deal score,
- alert delivery failures,
- open broker requests.

Add a special workflow for unmatched listings:

```text
Review listing
→ select or create product model
→ save alias
→ confirm match
→ recalculate price estimate
```

---

## 14. Security requirements

Implement:

- authorization policies for all tenant-owned models,
- rate limiting,
- email verification,
- secure file validation,
- malware-safe upload handling,
- MIME type validation,
- maximum upload sizes,
- signed private file URLs,
- audit logging for admin changes,
- encrypted credentials,
- CSRF protection,
- mass-assignment protection,
- output escaping,
- validation for all external input.

Do not store marketplace passwords.

Do not log:

- access tokens,
- passwords,
- full payment data,
- private API credentials.

---

## 15. Audit logging

Track:

```text
user_id
organization_id
action
subject_type
subject_id
old_values
new_values
ip_address
user_agent
created_at
```

Audit important actions:

- listing changes,
- manual product match changes,
- risk overrides,
- price-estimate overrides,
- role changes,
- subscription changes,
- application setting changes,
- broker transaction status changes.

---

## 16. Testing requirements

Use Pest.

Required test groups:

### Unit tests

```text
ProfitCalculationServiceTest
RiskAssessmentServiceTest
DealScoreServiceTest
PriceEstimationServiceTest
ListingNormalizationServiceTest
```

### Feature tests

```text
UserRegistrationTest
OrganizationAccessTest
ListingCreationTest
ListingAuthorizationTest
ListingPipelineDispatchTest
OwnedProductIntakeTest
OwnedProductAssessmentTest
SavedSearchMatchingTest
SubscriptionLimitTest
TelegramConnectionTest
BrokerRequestTest
```

### Security tests

Verify:

- user cannot access another organization’s listing,
- user cannot modify another organization’s saved search,
- member cannot manage billing,
- free user cannot exceed monthly usage,
- invalid uploaded files are rejected,
- unverified user cannot access protected features when required.

Use factories and seeders.

---

## 17. Seed data

Create seeders for:

- plans,
- plan features,
- common product categories,
- initial power-tool brands,
- sample product models,
- fake listings,
- demo organization,
- demo users.

Initial brands:

```text
Bosch Professional
Makita
DeWalt
Milwaukee
Hilti
Metabo
Festool
Einhell
Ryobi
```

Initial categories:

```text
cordless_drills
impact_drivers
rotary_hammers
angle_grinders
circular_saws
jigsaws
multi_tools
battery_sets
tool_sets
measuring_tools
```

---

## 18. Development phases

### Phase 1 — foundation

Build:

- Laravel project setup,
- authentication,
- organizations,
- roles,
- policies,
- Filament admin,
- plans and feature limits,
- basic landing page,
- application layout.

### Phase 2 — listing intake

Build:

- manual listing form,
- URL and text input,
- image uploads,
- listing detail page,
- listing status lifecycle,
- source abstraction.

### Phase 3 — AI analysis

Build:

- AI provider abstraction,
- fake provider,
- OpenAI provider,
- structured extraction,
- retries,
- validation,
- usage and cost tracking,
- analysis UI.

### Phase 4 — product matching and price estimate

Build:

- catalog,
- aliases,
- matching engine,
- comparable listing logic,
- price estimate,
- admin correction workflow.

Delivered application boundary: append-only catalog-match, comparable-selection, dated FX,
explicit Buy cross-market normalization, reproducible weighted-median price estimate, full
five-language evidence UI, and read-only normalization operations. Verified catalog
administration, live FX ingestion, governed reusable market factors, and Sell cross-market
normalization remain separate production work.

### Phase 5 — risk and deal scoring

Build:

- deterministic risk rules,
- AI risk signals,
- risk score,
- profit calculator,
- deal score,
- explanation UI.

### Phase 6 — saved searches and alerts

Build:

- saved searches,
- matching engine,
- email notifications,
- Telegram integration,
- duplicate alert protection.

### Phase 7 — subscriptions

Build:

- Stripe integration,
- pricing plans,
- usage limits,
- billing portal,
- upgrade and downgrade flows,
- subscription enforcement.

Delivered application boundary: provider-independent plan enforcement, Laravel Cashier
organization subscriptions, owner-only idempotent hosted Checkout and billing portal, signed
webhook projection, append-only operations evidence, and five-language UI. Stripe test/live
products, Price IDs, tax/portal policy, production secrets, and complete lifecycle acceptance
remain external release work governed by `docs/19-production-go-live.md`.

Production release safety includes a secret-free effective-configuration preflight, explicit
trusted-host/proxy boundaries, strict UTC/`utf8mb4` MySQL and TLS Redis/session/private-storage requirements, and
an independent analysis-submission kill switch. The fake analysis/product-matching providers may
be used only when submission is disabled outside development/testing; enabling production analysis
requires reviewed non-fake adapters and the activation evidence in `docs/19-production-go-live.md`.
The release build also enforces the nginx/Supervisor/scheduler/environment deployment contract, and
CI must apply the complete migration ledger and dedicated strict schema/session/query compatibility
contract against MySQL 8.4 with cached readiness proven through Redis, in addition to the complete
functional SQLite PHP-version matrix.

### Phase 8 — broker requests

Build:

- sourcing request form,
- admin workflow,
- offers,
- commission records,
- evidence-derived localized PDF reports with private signed delivery.

Delivered request and offer foundation: tenant-safe request list/create/edit/detail, immutable event
snapshots, exact optimistic concurrency, UUID replay safety, atomic plan quota at submission,
subject cancellation, verified-super-admin review/search/cancel command, read-only Admin
operations, privacy-erasure blocking, immutable evidence-bound supplier offers, server-calculated
exact-money terms, exact request/offer-head subject acceptance, atomic alternative closure, safe
multi-currency comparison, atomic accepted-offer transaction/commission creation, evidence-bound
payment/order/shipping/delivery/completion tracking, independent commission settlement evidence,
evidence-derived immutable PDF reports with checksum-verified private signed delivery and retention
purge, a provider-independent immutable refund/dispute investigation ledger with strict reviewed
outcome rules and safe subject/Admin projections, and complete EN/DE/ES/FR/sr-Latn UI/validation.
These records do not execute payment, refunds, chargebacks, commission reversals, or supplier
operations. Provider communication and actual payment/refund/dispute execution remain separate
Phase 8 procedures.

---

## 19. Initial implementation priorities

Start by implementing only the following vertical slice:

1. User registration
2. Automatic personal organization creation
3. Manual listing creation
4. Listing image upload
5. Listing detail page
6. Fake AI analysis
7. Product matching to a seeded product model
8. Manual expected resale price
9. Profit calculation
10. Risk score using deterministic rules
11. Deal score
12. Filament admin listing review

Do not implement Stripe, Telegram, browser extensions, or external marketplace integrations before
this vertical slice works. The current delivered-boundary status is authoritative in
`docs/15-delivery-roadmap.md` and `docs/18-development-handoff.md`.

The first milestone is complete when a new user can:

```text
register
→ create a listing
→ upload images
→ run fake AI analysis
→ see extracted product information
→ enter costs
→ see net profit
→ see risk score
→ see deal score
```

---

## 20. Coding standards

Follow:

- PSR-12
- strict typing in service and DTO classes,
- explicit return types,
- constructor property promotion where appropriate,
- small focused classes,
- no business logic in Blade files,
- no business logic in controllers,
- no direct API calls from controllers,
- no static helper classes for domain logic,
- use dependency injection,
- use transactions for multi-record state changes,
- use events only when they improve decoupling,
- avoid unnecessary abstractions before they are needed.

Use comments only where the code is not self-explanatory.

Prefer descriptive names over comments.

---

## 21. Git workflow

Use branches such as:

```text
feature/project-foundation
feature/organizations
feature/listing-intake
feature/ai-analysis
feature/product-matching
feature/price-estimation
feature/risk-engine
feature/deal-score
feature/saved-searches
feature/telegram-alerts
feature/subscriptions
feature/broker-requests
```

Use focused commits.

Example:

```text
feat: add personal organization creation during registration
feat: add manual marketplace listing intake
feat: add deterministic listing risk rules
test: cover cross-organization listing authorization
fix: prevent duplicate deal alerts
```

---

## 22. Environment variables

Prepare `.env.example` entries:

```env
APP_NAME="Procura"

DB_CONNECTION=mysql
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

FILESYSTEM_DISK=local

AI_PROVIDER=fake
AI_MODEL=
AI_TIMEOUT=60
AI_MAX_RETRIES=2
AI_MONTHLY_BUDGET_EUR=100

BILLING_PROVIDER=stripe
BILLING_CHECKOUT_ENABLED=false
BILLING_ALLOW_PROMOTION_CODES=false
BILLING_COLLECT_TAX_IDS=true
CASHIER_CURRENCY=eur
CASHIER_CURRENCY_LOCALE=en_IE
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
STRIPE_WEBHOOK_TOLERANCE=300
STRIPE_PRICE_STARTER_MONTHLY=
STRIPE_PRICE_STARTER_YEARLY=
STRIPE_PRICE_PRO_MONTHLY=
STRIPE_PRICE_PRO_YEARLY=
BILLING_PRICE_STARTER_MONTHLY_MINOR=0
BILLING_PRICE_STARTER_YEARLY_MINOR=0
BILLING_PRICE_PRO_MONTHLY_MINOR=0
BILLING_PRICE_PRO_YEARLY_MINOR=0

TELEGRAM_BOT_TOKEN=

MARKETPLACE_AUTOMATION_ENABLED=false
```

---

## 23. Non-goals for the first MVP

Do not implement:

- automated Willhaben scraping,
- captcha bypassing,
- proxy rotation,
- automatic marketplace messaging,
- automatic purchase actions,
- automatic account creation,
- automatic seller contact,
- native mobile apps,
- all marketplace categories,
- vehicles,
- real estate,
- cryptocurrency payments,
- complex machine-learning training pipelines,
- fully automated brokerage,
- additional interface languages beyond the documented initial five.

---

## 24. Definition of done

A task is done only when:

- migrations are complete,
- models and relationships are complete,
- policies exist,
- validation exists,
- automated tests pass,
- UI handles loading and error states,
- all user-facing UI copy is present in every supported locale,
- localization source checks and typed catalog compilation pass,
- queue failures are handled,
- tenant boundaries are enforced,
- no sensitive data is logged,
- code is formatted,
- relevant documentation is updated.

---

## 25. First Codex task

Start with Phase 1 and create the project foundation.

Implement:

1. Laravel 13 project structure
2. Authentication
3. Filament v5 admin panel
4. Organizations
5. Personal organization creation during registration
6. `organization_user` membership table
7. Active organization resolution
8. Roles:
   - super_admin
   - organization_owner
   - organization_member
   - individual_user
9. Tenant-aware authorization policies
10. Plan and plan feature tables
11. Seed Free, Starter, Pro, and Business plans
12. Pest test setup
13. Tests for:
   - registration,
   - personal organization creation,
   - organization membership,
   - cross-organization access prevention.

Before writing code:

- inspect the existing repository,
- do not overwrite existing work,
- identify the current Laravel and Filament versions,
- list the files that will be created or modified,
- then implement the smallest complete foundation.

After implementation:

- run migrations,
- run tests,
- run the formatter,
- report all changed files,
- report any failed command,
- do not hide unresolved errors.
