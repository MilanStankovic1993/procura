# 08 — Price Intelligence Engine

## 1. Purpose

The Price Intelligence Engine estimates realistic value.

It must distinguish between:

- current asking price,
- lowest available new price,
- typical used asking price,
- realistic transaction price,
- quick-sale price,
- recommended market price,
- ambitious sale price.

## 2. Inputs

- canonical product model,
- variant,
- condition,
- included accessories,
- missing accessories,
- defects,
- age,
- warranty,
- seller type,
- country,
- region,
- currency,
- comparable records,
- historical records,
- user-confirmed transaction outcomes.

## 3. Comparable selection

Comparables should be ranked by:

1. exact model,
2. exact variant,
3. condition similarity,
4. accessory similarity,
5. geographic relevance,
6. time relevance,
7. seller-type relevance,
8. source reliability.

Comparables from different countries must not be mixed without explicit currency normalization, market weighting, shipping consideration, and regional compatibility checks.

## 4. Exclusions

Exclude or heavily down-weight:

- spare parts,
- broken-only listings,
- unclear bundles,
- wanted ads,
- rental offers,
- listings without price,
- duplicate listings,
- extreme outliers,
- incompatible variants,
- suspicious currency values.

## 5. Statistical basis

Initial methods:

- median,
- trimmed mean,
- interquartile range,
- median absolute deviation,
- weighted median.

Do not use simple average as the only price estimate.

## 6. Suggested value calculation

```text
base_value = weighted_median(comparables)

condition_adjustment
accessory_adjustment
age_adjustment
warranty_adjustment
region_adjustment
market_trend_adjustment
```

```text
estimated_market_value =
base_value
× condition_factor
× accessory_factor
× age_factor
× warranty_factor
× region_factor
× trend_factor
```

## 7. Sell price bands

### Quick-sale price

Designed for speed and high probability of sale.

### Recommended market price

Balanced price and sale duration.

### Ambitious price

Higher price with lower expected conversion and longer sale duration.

## 8. Confidence score

Confidence should consider:

- number of comparables,
- model-match quality,
- condition data completeness,
- price dispersion,
- source diversity,
- data freshness,
- verified transaction outcomes.

Example:

```text
confidence =
25% comparable count
25% product-match quality
15% condition completeness
15% price consistency
10% source diversity
10% freshness
```

## 9. Demand score

Potential inputs:

- number of active offers,
- listing disappearance rate,
- average days active,
- price reductions,
- saved-search interest where available,
- user outcome data.

Demand score must initially be labeled experimental until enough data exists.

## 10. Outcome learning

Store:

- estimated price,
- actual sale price,
- estimate error,
- estimated sale duration,
- actual sale duration.

Do not silently retrain or alter logic without versioning.

The Phase 4 outcome boundary now stores actual purchase, categorized costs, sale price, and sale
duration as immutable attributed evidence, but it does not feed those facts back into price bands
or scoring. Estimate error requires a separate explicit immutable link to one exact analysis and
estimate run. Automated learning remains disabled until versioning, consent, retention, data
quality, and governance are separately approved.

Every price estimate must store:

- algorithm version,
- input snapshot,
- comparable IDs,
- adjustment factors,
- calculated result.

It must also store the target country or market scope, original currencies, exchange rates, and the rate timestamp used in the calculation.

## 11. Fallback behavior

If insufficient comparables exist:

- use category-level rules,
- use new-price discount rules,
- request additional input,
- lower confidence,
- never present an overly precise number.

Example:

```text
Estimated value: €280–€370
Confidence: Low
Reason: only two relevant comparable offers were available.
```

## 12. Current implementation boundary

The current Phase 2 implementation completes the smallest reproducible price-estimation boundary:

- global `ExchangeRate` evidence is immutable and preserves exact base/quote decimal rate,
  provider/reference, effective/published/fetched timestamps, raw evidence, and evidence hash,
- the resolver is calculation-time-aware, supports identity/direct/inverse direction, and rejects
  stale, missing, or not-yet-known evidence,
- currency conversion uses exact decimal arithmetic and integer minor units; binary floating-point
  money is not used,
- `PriceEstimate` is tenant-owned, append-only, idempotent, hard bounded, and linked to one exact
  ready `ComparableSet`,
- every `PriceEstimateItem` preserves the original and normalized amounts, rate provenance,
  selector evidence, weight, decision, and reason codes,
- v2 records weighted median, median, Q1/Q3, median absolute deviation, dispersion, confidence
  components, versioned input hash, and complete replay snapshot,
- extreme MAD outliers remain explicit evidence, while high dispersion produces a visible
  `low_confidence` result rather than false precision,
- incomplete conversion evidence produces `needs_input`; no current-rate lookup is performed
  during replay,
- Analysis detail explains bands, statistics, confidence, included/outlier items, and rate
  provenance.

The Buy selector now supports cross-country or cross-currency evidence only through an explicit
immutable `ComparableMarketNormalization` for the exact analysis and comparable. The analyst must
confirm regional compatibility or incompatibility, the evidence reference and note, the
observation time, and—when compatible—the market factor plus explicit shipping, import duty, tax,
and other target-currency costs. Factors are bounded to 50.00%–150.00%; the application provides no
default factor and derives no customs or tax value.

The compatible calculation is exact and reproducible:

```text
converted_amount =
  dated_exchange_rate(source_amount, source_currency, target_currency, observed_at)

market_adjusted_amount =
  round_half_even(converted_amount * market_factor_basis_points / 10000)

normalized_amount =
  market_adjusted_amount + shipping + import_duty + tax + other_cost
```

Missing/stale dated rates, mismatched source/target facts, unsupported amounts, absent attestation,
or missing evidence fail closed. Incompatible evidence is append-only and excludes the comparable
without producing a normalized amount. Later evidence supersedes only the current projection; all
prior normalizations, selections, estimates, and hashes remain intact.

`deterministic-comparable-selector:v2` gives a confirmed cross-country item reduced geographic
relevance and freezes the full normalization snapshot into its selection evidence.
`deterministic-weighted-median:v2` consumes that exact normalized amount and rate provenance,
without fetching a new rate or converting twice. Same-country/same-currency behavior is unchanged.
This boundary still does not invent condition, accessory, age, warranty, trend, transaction-price,
quick-sale, or ambitious-sale adjustments. It does not provide live FX ingestion, automatic
country-factor profiles, or customs/tax engines.

The completed downstream risk, cost/profit, opportunity, and deal-score boundaries consume the
resulting exact evidence chain without reinterpreting comparable prices.

## 13. Current Sell implementation boundary

Phase 3 now has a separate Sell price-intelligence aggregate:

- `SellComparableRecord` accepts approved manual evidence only for the exact current ready
  `OwnedProductAssessment`; Buy comparables are never copied implicitly,
- original source identity, integer minor-unit asking price, currency, country, timestamps,
  condition, accessories, reliability, raw input, evidence hash, assessment hash, and actor are
  append-only,
- deterministic selector v2 is bounded, versioned, idempotent, preserves every
  inclusion/exclusion, and admits another country/currency only through one exact compatible
  `SellComparableMarketNormalization` for the current assessment, comparable, and target scope,
- normalization preserves a dated immutable rate, 50%–150% explicit market factor, target-currency
  shipping/duty/tax/other costs, compatibility reference/note/attestation, exact half-even formula,
  actor, observation time, version, and hash; no value is inferred,
- assessed default market/currency scopes and observed alternate currency scopes refresh
  atomically, so an old result cannot remain current while omitting new evidence,
- fewer than three eligible values produces `needs_input`; MAD outliers remain explicit evidence,
- quick-sale is Q1 to the clamped weighted median, recommended is Q1 to Q3, and ambitious is the
  clamped weighted median to Q3,
- confidence follows the documented 25/25/15/15/10/10 component split for count, match quality,
  condition completeness, consistency, source diversity, and freshness,
- every `SellPriceBand` preserves selected item IDs, weights, outlier decisions, statistics,
  confidence, assessment completeness, unknown facts, verification actions, method version, stable
  input hash, and full replay snapshot,
- the application projection returns no more than 25 comparable records and the 10 newest
  normalization decisions for each record; this presentation bound never determines the current
  selection and does not truncate the read-only operations ledger,
- price-band v2 consumes the normalization snapshot's target amount and provenance without a
  second rate resolution or double conversion; invalid or mismatched evidence fails closed,
- realized transaction price and expected sale duration remain unknown; the UI labels all results
  as asking-price guidance rather than a guaranteed sale result.

Sell price intelligence itself does not generate listing content. A separate downstream,
production-safe listing-draft aggregate now consumes one exact current complete price band, stores
the user's explicit target asking price and selected strategy, and preserves the selected range
without modifying the price calculation. It does not convert currency or reinterpret comparable
evidence.

The downstream draft boundary still does not publish listings, create a sale portfolio, record
actual money, scrape marketplaces, or call an external AI service.
