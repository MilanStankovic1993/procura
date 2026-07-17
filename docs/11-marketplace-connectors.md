# 11 — Marketplace Connectors

## 1. Principle

The core platform must not depend on a single marketplace.

## 2. Connector types

### Manual connector

User enters data.

### URL-assisted connector

User provides a URL and manually confirms extracted values.

### Browser-extension connector

User actively submits page data from their browser, subject to legal and platform review.

### CSV connector

Imports authorized data.

### Email connector

Imports listing alerts or feeds sent to a controlled mailbox.

### Partner-feed connector

Imports authorized XML, JSON, or CSV feeds.

### Official API connector

Uses documented marketplace APIs.

## 3. Contract

```php
interface MarketplaceConnector
{
    public function key(): string;

    public function capabilities(): ConnectorCapabilitiesData;

    public function search(SearchCriteriaData $criteria): Collection;

    public function fetchListing(string $externalId): MarketplaceListingData;

    public function normalize(array $payload): MarketplaceListingData;
}
```

## 4. Capability flags

```text
search
fetch_single
historical_prices
seller_profile
images
sold_status
transaction_prices
webhooks
```

Each connector must also declare supported countries, currencies, languages, geographic coverage, and whether cross-border search is supported.

## 5. Compliance registry

For every source store:

```text
terms_reviewed_at
legal_basis
allowed_operations
prohibited_operations
rate_limits
data_retention_rules
attribution_rules
contact_person
review_notes
```

Compliance is evaluated per source and country. Permission in one country must not be assumed to apply globally.

## 6. Prohibited implementation

Do not implement:

- CAPTCHA bypass,
- stealth browser fingerprinting,
- residential proxy rotation,
- automated account creation,
- credential harvesting,
- automated seller messaging,
- unauthorized mass crawling.

## 7. Source quality score

Each source may have:

- reliability,
- freshness,
- completeness,
- asking-price-only indicator,
- transaction-price indicator.

Price calculations should weight sources accordingly.
