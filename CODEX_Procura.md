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

- PHP 8.3+
- Laravel 12
- MySQL 8+
- Redis
- Laravel Horizon
- Laravel Sanctum
- Filament v5
- Livewire
- Tailwind CSS
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

Tables:

```text
alerts
notification_logs
telegram_connections
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
commissions
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

listings
listing_images
listing_snapshots

product_categories
brands
product_models
product_aliases
listing_product_matches

ai_analyses
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
/saved-searches
/alerts
/profit-tracker
/broker-requests
/organization
/billing
/settings
```

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

### Phase 8 — broker requests

Build:

- sourcing request form,
- admin workflow,
- offers,
- commission records,
- PDF report generation later.

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

Do not implement Stripe, Telegram, browser extensions, or external marketplace integrations before this vertical slice works.

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

STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=

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
- multilingual UI beyond English and German initially.

---

## 24. Definition of done

A task is done only when:

- migrations are complete,
- models and relationships are complete,
- policies exist,
- validation exists,
- automated tests pass,
- UI handles loading and error states,
- queue failures are handled,
- tenant boundaries are enforced,
- no sensitive data is logged,
- code is formatted,
- relevant documentation is updated.

---

## 25. First Codex task

Start with Phase 1 and create the project foundation.

Implement:

1. Laravel 12 project structure
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
