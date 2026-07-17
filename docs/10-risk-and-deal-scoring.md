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
strong_opportunity
potential_opportunity
needs_verification
weak_opportunity
avoid
insufficient_data
```

## 7. User explanation

Every score page must show:

- score,
- factors increasing score,
- factors reducing score,
- assumptions,
- next recommended checks.
