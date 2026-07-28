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

Realized outcomes must be evidenced independently from estimates and workflow status. Original
amount/currency, actor, occurrence time, provenance, immutable corrections, and any exact dated
conversion reference must remain auditable. Actual profit must remain unknown until the purchase,
complete real-cost, and sold evidence chain is complete.

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

Procura must provide a traceable data-subject request workflow for account-data export and account
deletion. Submission, review, required action, approval, fulfillment, rejection, and subject
cancellation are evidence-bearing states rather than destructive shortcuts. The application must
preserve the request/event audit trail even if the subject account is later removed.

The product must never automatically export or erase customer data before the approved retention,
legal-hold, identity-verification, ownership-transfer, billing-resolution, and secure-delivery
procedures have completed. A workflow status records the reviewed operational outcome; it does not
replace those production procedures.

## BR-010 Multilingual product

Initial UI languages:

- English,
- German,
- Spanish,
- French,
- Serbian in Latin script (`sr-Latn`).

English is the deterministic fallback language. The interface language is a personal user preference
and must remain independent from organization market defaults, analysis market scope, currency, and
listing language.

Marketplace input may be submitted in any language supported by the configured extraction and
translation provider. UI localization and listing-language support are separate capabilities.

Every first-party browser screen, including all new feature work, shall ship with complete English,
German, Spanish, French, and Serbian Latin interface catalogs in the same change. Technical
identifiers and immutable evidence codes remain language-neutral; their user-facing labels and
explanatory copy are localized without rewriting the stored source facts.

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

## BR-013 Current Sell pricing boundary

Phase 3 supports explainable Sell asking-price guidance only after a current canonical
identity/condition assessment. It preserves approved source evidence and every include/exclude
decision, then derives quick-sale, recommended, and ambitious bands from same-country,
same-currency evidence. The business promise is transparent guidance with confidence and unknowns,
not a guaranteed sale price or duration. Listing generation, publication, portfolio management,
and actual financial outcomes remain later boundaries.

## BR-014 Current Sell listing-draft boundary

Phase 3 may generate marketplace-ready draft copy only from one exact current ready owned-product
assessment, one exact current complete Sell price band, the user's explicit target asking price,
and the current private image manifest. The user chooses the price strategy and listing language;
neither may be inferred from UI locale or market defaults.

Generated content must disclose known condition, accessory, missing-accessory, and defect facts
without inventing specifications, warranty, transaction results, marketplace claims, or missing
facts. Every draft and photo-readiness checklist is versioned, append-only, attributable, and
reproducible. This business capability prepares a draft only: publication, sale-portfolio
lifecycle, and actual financial outcomes remain separate boundaries.

## BR-015 Current Sell portfolio and manual-publication boundary

Phase 3 may move only one exact current `ready` listing draft with `ready` photo evidence into an
append-only sale portfolio. The entry preserves the complete draft evidence chain and the initial
target asking price, but that amount is neither received money nor a completed sale.

Authorized users may manually record publication, price change, reservation, withdrawal, expiry,
and relisting events. Every event preserves marketplace identity/URL, the exact advertised
minor-unit price and currency, external occurrence time, actor, idempotency evidence, and the prior
event head. The server owns allowed lifecycle transitions and rejects stale commands. This
boundary calls no marketplace API and records no realized financial outcome.

## BR-016 Current transaction-outcome boundary

Phase 4 permits authorized users to append evidenced actual purchase, categorized real costs, and
sold/cancelled/no-sale outcomes for an owned product. These records are operational truth distinct
from every estimate, asking price, reservation, and buyer workflow decision. Original money,
historical conversion, actor, time, evidence, corrections, and exact sale-publication attribution
must remain auditable.

The platform may present actual profit only from a complete current purchase, fully known current
cost snapshot, and exact sold outcome in one reporting currency. Missing or mismatched evidence
must remain visibly unknown. This boundary performs no payment processing, settlement verification,
marketplace mutation, estimate-accuracy reporting, or outcome-fed model learning.
