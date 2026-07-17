# 05 — User Flows

## Flow A — Buy Analysis

```text
User registers
→ personal organization is created
→ user opens Buy Analysis
→ user selects the market scope and target country
→ user enters listing data
→ system stores original input
→ normalization job runs
→ AI extraction runs
→ product matching runs
→ comparable records are selected
→ price estimate is calculated
→ risk assessment is calculated
→ user enters or confirms costs
→ profit calculation runs
→ deal score is calculated
→ result page is displayed
→ user chooses:
   - interested
   - contacted seller
   - purchased
   - rejected
   - archived
```

### Result states

```text
draft
queued
processing
needs_input
completed
failed
archived
```

### Needs-input examples

- price missing,
- model uncertain,
- images insufficient,
- condition unknown,
- no comparable records,
- currency unclear.

## Flow B — Sell Analysis

```text
User opens Sell Analysis
→ enters owned-product details
→ selects target countries and cross-border preference
→ uploads photos
→ system identifies product
→ system evaluates condition and completeness
→ comparable market data is selected
→ quick-sale, market, and ambitious prices are calculated
→ system generates listing content
→ user selects target price
→ product enters sale portfolio
→ user records listing publication
→ system tracks price changes manually or through approved integrations
→ user records completed sale
→ actual result is compared with estimate
```

## Flow C — Compare offers

```text
User creates comparison
→ adds two or more listings
→ system normalizes products
→ incompatible products are flagged
→ total acquisition cost is calculated
→ offers are ranked
→ user sees explanation of ranking
```

## Flow D — Actual profit tracking

```text
Analysis marked purchased
→ purchase record created
→ user enters actual purchase and costs
→ user creates sale listing record
→ sale completed
→ actual profit calculated
→ estimate accuracy recorded
```

## Flow E — Admin product-match review

```text
Low-confidence match enters review queue
→ admin opens listing
→ admin sees suggested products
→ admin selects existing model or creates a new model
→ admin optionally creates alias
→ match is confirmed
→ downstream price and score calculations rerun
```
