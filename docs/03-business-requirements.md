# 03 — Business Requirements

## BR-001 Revenue model

The platform should support:

- monthly subscriptions,
- annual subscriptions,
- usage-based limits,
- paid one-time analysis,
- broker or sourcing service fees,
- business plans,
- future API access.

## BR-002 Subscription plans

Initial plan concept:

### Free

- limited monthly analyses,
- one saved search,
- basic result,
- email notifications.

### Starter

- more analyses,
- multiple saved searches,
- standard price estimation,
- email and Telegram alerts.

### Pro

- higher limits,
- detailed risk report,
- profit portfolio,
- priority processing,
- analysis history.

### Business

- team accounts,
- organization reporting,
- sourcing requests,
- exports,
- administrative controls,
- custom limits.

Exact prices must be configurable.

## BR-003 Cost control

The platform must track:

- AI tokens,
- AI request cost,
- image-processing cost,
- notification cost,
- external data-provider cost,
- storage use,
- analyses per plan.

The system must enforce monthly budget limits.

## BR-004 User value measurement

The system should capture:

- estimated saving,
- actual saving,
- estimated profit,
- actual profit,
- estimate error,
- time-to-sale,
- user confirmation of outcome.

## BR-005 Trust

The platform must explain:

- data sources,
- confidence,
- assumptions,
- missing information,
- risk factors,
- limitations.

Black-box recommendations without explanation are not acceptable.

## BR-006 Market expansion

The architecture must support global markets from the start and allow:

- continents and market regions,
- new countries,
- new currencies,
- new marketplaces,
- new product categories,
- new pricing models,
- new AI providers.

Global support does not imply equal data coverage. Search results and estimates must expose source coverage and confidence for the selected countries.

## BR-007 Legal boundaries

The product must not depend on prohibited scraping, automated messaging, credential sharing, CAPTCHA bypassing, or unauthorized use of marketplace data.

## BR-008 Human review

Low-confidence, high-value, and high-risk cases must support human review.

## BR-009 Data retention

Retention must be configurable by record type and plan.

## BR-010 Multilingual product

Initial UI languages:

- English,
- German.

Serbian may be supported for internal operations and founder use.

Marketplace input may be submitted in any language supported by the configured extraction and translation provider. UI localization and listing-language support are separate capabilities.

## BR-011 Auditability

All material overrides must be logged:

- price override,
- product-match override,
- risk override,
- role change,
- subscription change,
- broker status change.

## BR-012 Commercial MVP success

The first commercial milestone is reached when users can complete both:

```text
Buy Analysis
Sell Analysis
```

and at least a pilot group can confirm that recommendations are useful and understandable.
