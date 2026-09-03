# 17 — Global Market Model

## 1. Purpose

Procura is built for global use from the first production architecture. Global support means that users can submit and analyze products from any country and define the markets in which they want to buy or sell.

It does not mean that every marketplace can be searched automatically. Search coverage depends on manually submitted data and connectors that are explicitly authorized for each source and country.

## 2. Geographic hierarchy

Use the following hierarchy:

```text
Continent
→ Country
→ Administrative region or state
→ City
→ Optional coordinates and radius
```

Continents are navigation and discovery groups. Countries are the primary market units because currencies, marketplaces, shipping, customs, taxes, consumer rules, and price behavior operate at country level.

## 3. Reference standards

Use:

```text
ISO 3166-1 alpha-2  countries
ISO 4217            currencies
BCP 47              languages and locales
IANA                 time zones
```

Do not create business logic around translated country names. Store stable codes and translate display names in the UI.

## 4. Core reference data

Foundation tables:

```text
continents
countries
currencies
```

Suggested continent fields:

```text
code
name
sort_order
active
```

Suggested country fields:

```text
code
continent_code
name
official_name
default_currency_code
default_locale
default_timezone
measurement_system
active
```

Suggested currency fields:

```text
code
name
symbol
minor_unit
active
```

Exchange rates are time-dependent data and must be stored separately when currency conversion is introduced.

## 5. Organization preferences

An organization may define:

```text
home_country_code
reporting_currency_code
locale
timezone
measurement_system
```

These are defaults only. They must never silently replace the explicit market context of an analysis.

A user's personal interface locale is a separate identity preference. It controls translated
application copy and future notification language only; it must never select an organization
locale, source country, destination country, currency, market scope, or listing language.

## 6. Market scope

A market scope may contain:

```text
continent_code
country_codes
origin_country_code
destination_country_code
include_cross_border
radius_km
reporting_currency_code
```

A user selecting a continent must be able to include or exclude individual countries. Price estimates and search results must clearly show which countries were actually covered.

## 7. Listings and analyses

Every listing must preserve:

```text
source_country_code
seller_region
seller_city
original_currency_code
original_price
original_language
```

Every price-sensitive analysis must define a target country or explicit multi-country market scope. User locale is not sufficient evidence of market intent.

## 8. Cross-border economics

Cross-border calculations may include:

- shipping,
- insurance,
- customs duty,
- import tax,
- payment and currency-conversion fees,
- product compatibility,
- warranty limitations,
- expected return cost.

Unknown costs must be visible as missing information and must reduce confidence. The system must not silently treat unknown costs as zero.

## 9. Product compatibility

The canonical product catalog is global. Product variants may differ by:

- regional model number,
- voltage and frequency,
- plug type,
- metric or imperial specification,
- radio or wireless certification,
- included accessories,
- warranty region.

Regional incompatibility must be represented as a product or transaction risk signal.

## 10. Price intelligence

Comparable selection prioritizes the target country. Cross-country comparables may be used only when:

- currencies are normalized using a dated exchange rate,
- shipping and import costs are considered,
- regional product compatibility is verified,
- market-level adjustment is explicit,
- confidence is reduced when evidence is weak.

The UI must never imply worldwide data coverage when only a subset of countries or sources was searched.

## 11. Connector coverage

Every marketplace connector declares:

```text
supported_country_codes
supported_currency_codes
supported_language_tags
geographic_coverage
cross_border_search
capabilities
compliance_status
```

Unsupported countries must produce an explicit coverage message, not an empty result that looks like no offers exist.

## 12. Phase 1 boundary

Phase 1 creates global reference data and organization defaults. It does not implement worldwide marketplace aggregation, live exchange-rate providers, customs engines, tax engines, or external connectors.

## 13. Current price-conversion boundary

Phase 2 now stores immutable dated exchange-rate evidence and can deterministically resolve
identity, direct, or inverse conversion for an explicit calculation timestamp. Rates are recorded
only through an explicit operational command/action with provider, reference, timestamps, raw
evidence, and evidence hash; no live provider or background rate fetch exists yet.

The Buy v2 selector still rejects cross-country and cross-currency records by default. It admits
one only when an authorized analyst appends an exact compatible `ComparableMarketNormalization`
for the analysis/comparable. That evidence freezes the dated rate, bounded market factor, explicit
target-currency shipping/duty/tax/other costs, regional-compatibility reference/note, actor,
timestamp, formula results, and hash. Incompatible, missing, stale, mismatched, or unconfirmed
evidence remains excluded. No live rate provider, automatic factor, customs/tax engine, or global
coverage claim exists.

The Phase 3 Sell selector remains a separate aggregate. Its candidate pool is scoped to one exact
owned-product assessment/model and each run has one explicit target country/currency. Native
records are eligible directly. Another country/currency remains excluded unless an authorized
manager appends an exact compatible `SellComparableMarketNormalization` for that assessment,
comparable, and target scope. The record freezes compatibility, dated rate provenance, bounded
market factor, explicit target-currency costs, formula result, reference/note/attestation, actor,
version, and hash. Selector v2 snapshots it; price-band v2 consumes the target amount without a
second lookup. Assessed default scopes and observed alternate currency scopes refresh atomically.
No UI locale, reporting currency, Buy comparable, current unrecorded exchange rate, or implicit
cross-border assumption may change that boundary.

Sell listing language is also independent from the market boundary. A German-market EUR price band
may generate an English, German, Spanish, French, or Serbian Latin draft only when the user chooses
that language explicitly. The draft preserves target country/currency from the selected band and
does not use language to infer market, currency, tax, shipping, or regional compatibility.

Sale-portfolio publication scope is also explicit. The marketplace key and external listing ID
identify the outside listing, while each event records its advertised currency independently from
UI locale and reporting currency. A price change never performs implicit currency conversion.
Relisting from stale source evidence requires a new current evidence chain; no marketplace country,
currency, or language is inferred from the external URL.

## 14. Realized-outcome conversion boundary

Every actual purchase, known cost item, and sold amount preserves its original integer minor units
and source currency. The user explicitly selects a reporting currency for each evidence version;
UI locale, organization locale, target country, advertised currency, and Buy estimate currency do
not select it implicitly.

Identity conversion records no exchange-rate ID. Cross-currency realized evidence requires one
exact immutable direct or inverse rate whose effective time is not after the financial occurrence
and whose evidence exists by calculation time. The normalized amount, direction, exact decimal,
rate ID, effective time, provider/reference, and calculation time are frozen with the record.
Missing evidence rejects the command.

Realized profit requires purchase, complete cost snapshot, and sold outcome in one reporting
currency. Mismatched currencies stay explicit unknowns; the system never sums original currencies,
uses a current unrecorded rate, or treats an advertised price as proceeds. This outcome boundary
does not imply cross-market comparability, tax correctness, customs completeness, payment
processing, or settlement verification.
