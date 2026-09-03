# 10 — Risk and Deal Scoring

## 1. Risk score purpose

Risk score estimates transaction uncertainty.

It is not a fraud verdict.

## 2. Risk categories

### Listing risk

- contradictory description,
- missing model,
- unclear condition,
- copied text,
- suspiciously low price.

### Seller risk

- unverifiable identity,
- refusal of inspection,
- off-platform payment request,
- urgency pressure,
- unknown location.

### Product risk

- missing serial number,
- account lock,
- missing ownership proof,
- battery uncertainty,
- hidden repair risk,
- counterfeit indicators.

### Transaction risk

- unprotected payment,
- unusual shipping,
- cross-border complexity,
- customs uncertainty,
- no written terms.

## 3. Risk score

```text
0–24 low
25–49 medium
50–74 high
75–100 critical
```

Store each signal:

```text
code
category
severity
weight
evidence
source
confidence
```

## 4. Deal score

Deal score ranks opportunity attractiveness.

Initial weighting:

```text
35% estimated net margin
25% price-confidence score
15% resale demand
15% inverse risk
10% logistics simplicity
```

## 5. Deal-score rules

A high margin with low confidence must not receive a top score.

A critical risk may cap the score.

Suggested caps:

```text
critical risk → maximum deal score 40
low price confidence → maximum deal score 60
unknown product model → maximum deal score 50
```

## 6. Recommendation states

```text
80.00–100.00  strong_opportunity
65.00–79.99   potential_opportunity
50.00–64.99   needs_verification
30.00–49.99   weak_opportunity
0.00–29.99    avoid
no score       insufficient_data
```

The recommendation is derived from the final capped score, not the uncapped score.

## 7. Deterministic v1 calculation contract

All component values use integer basis points where `10000` represents a component score of 100.
Each weighted contribution is rounded half up in integer arithmetic and the five contributions sum
to the uncapped score. The lowest applicable cap is then applied. The API retains exact score basis
points and also exposes a half-up rounded whole-number display score.

The expected net-margin component is normalized explicitly:

```text
profit margin <= 0.00%  → normalized margin score 0
profit margin >= 40.00% → normalized margin score 100
between 0.00% and 40.00% → linear normalization
```

A negative margin is preserved unchanged in the input snapshot and receives the explicit
`negative_expected_margin` reason. It is never converted into a positive contribution. Margins
above 40% remain preserved as raw evidence and receive the explicit
`margin_normalization_ceiling_reached` reason.

The other normalized component scores are:

```text
price confidence → exact recorded price-confidence basis points
resale demand → exact recorded demand score
inverse risk → 100 minus the exact recorded risk score
logistics simplicity → exact recorded logistics score
```

Score confidence uses the same `35/25/15/15/10` weighting over the corresponding upstream
confidence values. A required upstream component with a null score produces `insufficient_data`
and no precise final score. Caps are explicit decisions persisted with their triggering evidence:

```text
critical risk → 40
low price confidence → 60
unknown product model → 50
```

## 8. User explanation

Every score page must show:

- score,
- factors increasing score,
- factors reducing score,
- assumptions,
- next recommended checks.

## 9. Current implementation boundary

The smallest explainable risk-assessment boundary is implemented:

- tenant-owned append-only `RiskAssessment` and `RiskSignal` records link one exact analysis,
  product match, comparable set, and price estimate,
- `deterministic-risk-evaluator:v1` preserves its complete input snapshot, stable hash, run number,
  confidence components, reason codes, unknown count, and verification actions,
- the score is reproducible from immutable signal contributions and uses the documented bands
  exactly,
- v1 scores only defensible recorded facts: asking price below the observed band (5/10/20/30
  points by deviation), low-confidence price evidence (10), and cross-border context (10),
- missing seller, location, images, condition, ownership/serial, payment protection, and
  shipping/return facts contribute zero points; they reduce confidence and create explicit next
  checks rather than being treated as safe,
- duplicate execution is idempotent, signals are hard bounded, and unavailable upstream price
  evidence leaves the risk stage pending,
- the tenant-safe API and separate Angular panel expose score, level, confidence, every signal,
  evidence source, assumptions, and the non-fraud disclaimer.

The explicit expected-profit boundary is also implemented:

- tenant-owned append-only `CostInput`, `CostInputItem`, `ProfitEstimate`, and
  `ProfitEstimateItem` records preserve one exact analysis/price/risk evidence chain,
- purchase price and eight additional-cost categories use integer minor units; null is unknown and
  zero is an explicitly known zero,
- v1 requires the exact price-estimate currency, performs no implicit conversion, and requires
  explicit transport, customs, tax, and regional-compatibility evidence for cross-border cases,
- deterministic formulas calculate gross margin, total cost, expected net profit, profit margin,
  and return on invested capital while retaining negative results and every contribution,
- unknown costs produce `needs_input` and null precise totals/ratios; confidence components and
  reason codes explain the result,
- changed inputs append new runs, immediate identical replay is idempotent, and the tenant-safe API
  plus Angular panel expose formulas, evidence, assumptions, and the estimate disclaimer.

The recorded logistics and demand component boundary is implemented:

- tenant-owned append-only `OpportunityInput` and `OpportunityInputItem` evidence links one exact
  comparable/price/risk/cost/profit chain and distinguishes required unknowns from confirmed false
  values and numeric zero,
- `deterministic-logistics-simplicity:v1` scores shipping method (20), distance (15), pickup (10),
  transport-cost certainty (15), tracking (10), insurance (10), packaging (10), and route readiness
  (10),
- `deterministic-resale-demand:v1` scores comparable breadth (25), median evidence recency (20),
  explicit sold-observation rate normalized by its window (25), and observed sale velocity (30),
- asking-price comparables provide breadth/recency evidence only and never prove completed sales;
  sold count, time window, median days, observation timestamp, and attributable source are explicit,
- every criterion is an immutable `OpportunityAssessmentItem` with maximum points, contribution or
  unknown state, and source snapshot,
- either component remains `needs_input` with a null score when required evidence is unknown.
  Immediate replay is idempotent, changed or reverted facts append runs, and upstream changes
  invalidate current projections.

The final weighted `DealScore` boundary is implemented:

- tenant-owned append-only `DealScore` and `DealScoreItem` records link the exact current product,
  price, risk, profit, opportunity-input, logistics, and demand evidence runs,
- `deterministic-deal-score:v1` applies the documented weights, integer basis-point arithmetic,
  explicit margin normalization, exact recommendation thresholds, and all three cap decisions,
- raw negative or above-ceiling margins remain visible in the immutable snapshot while their
  normalized contribution follows the documented floor/ceiling contract,
- missing required component scores produce `needs_input`, `insufficient_data`, and null precise
  capped/uncapped results,
- immediate identical evaluation is idempotent; changed and historical-value reversion inputs
  append runs, while stale upstream projections never expose a historical score as current,
- the tenant-safe API and isolated Angular panel expose capped and uncapped scores, every raw and
  weighted component, confidence, cap decisions, increasing/reducing factors, assumptions, next
  checks, reasons, and the non-guarantee disclaimer.
