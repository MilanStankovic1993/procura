# 14 — Testing Strategy

## 1. Unit tests

Required:

- price estimation,
- outlier detection,
- profit calculation,
- risk scoring,
- deal scoring,
- normalization,
- product alias matching,
- confidence scoring.

## 2. Feature tests

Required:

- registration,
- personal organization creation,
- organization switching,
- buy analysis creation,
- sell analysis creation,
- image upload,
- analysis pipeline,
- subscription enforcement,
- saved search matching,
- outcome recording.

## 3. Authorization tests

Required:

- no cross-organization read,
- no cross-organization update,
- members cannot manage billing,
- non-admin users cannot access Filament admin,
- users cannot override estimates without permission.

## 4. Integration tests

Use fake providers for:

- AI,
- email,
- Telegram,
- payment,
- marketplace connectors.

Do not call real external services in automated tests.

## 5. Golden-data tests

Maintain a curated dataset of product listings with expected:

- model,
- condition,
- accessories,
- price range,
- risk signals.

Use it to detect regression in AI prompts and matching logic.

## 6. Acceptance tests

MVP acceptance scenarios:

### Buy Analysis

User submits listing and receives a complete result.

### Sell Analysis

User submits owned product and receives price bands and listing content.

### Outcome

User records actual purchase and sale and sees actual profit.

## 7. Performance tests

Measure:

- analysis creation response,
- queue throughput,
- admin listing search,
- comparable selection,
- dashboard queries.
