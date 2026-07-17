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
