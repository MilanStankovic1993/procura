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
SellerProfile
ImportBatch
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
DealScore
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
SavedSearchMatch
Alert
NotificationLog
```

### Subscription

```text
Plan
PlanFeature
SubscriptionUsage
```

### Brokerage

```text
BrokerRequest
BrokerOffer
BrokerTransaction
Commission
```

## 2. Important aggregates

### Analysis aggregate

Owns:

- analysis request,
- source input,
- normalized product data,
- calculation status,
- final recommendation.

### Listing aggregate

Owns:

- original marketplace data,
- images,
- snapshots,
- seller metadata.

### Product aggregate

Owns:

- canonical product model,
- variants,
- aliases,
- specifications.

### Transaction outcome aggregate

Owns:

- actual purchase,
- actual sale,
- real costs,
- actual profit.

## 3. Core identifiers

Use internal UUIDs or ULIDs for public-facing entities.

External marketplace IDs must never be treated as globally unique without source context.

Unique external listing key:

```text
marketplace_source_id + external_id
```

## 4. Monetary data

All monetary records must include:

- amount,
- currency,
- optional conversion rate,
- conversion timestamp,
- original amount,
- normalized reporting amount where needed.

Never use floating-point columns for money.

The original amount and currency are immutable source facts. Converted amounts must include the exchange-rate source, rate, and timestamp. Price estimates must include the country or market scope they represent.

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
- saved searches,
- alerts,
- transactions,
- uploaded files,
- notes,
- organization settings.

## 7. Global market rules

Use continent only for navigation and broad discovery. Country is the primary boundary for marketplace availability, price comparison, taxes, customs, shipping, and legal rules.

Products are globally canonical, but variants may be region-specific. Regional attributes may include model number, voltage, plug type, measurement system, warranty applicability, and market-specific accessories.
