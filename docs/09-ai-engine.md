# 09 — AI Engine

## 1. AI responsibilities

AI may assist with:

- language detection,
- translation,
- product-category extraction,
- brand and model extraction,
- variant extraction,
- condition extraction,
- included-item extraction,
- missing-item inference,
- defect detection,
- suspicious-language detection,
- image-content analysis,
- listing-title generation,
- listing-description generation.

## 2. AI must not be sole authority for

- final price,
- legal conclusions,
- fraud guarantees,
- tax calculation,
- customs calculation,
- automatic purchasing,
- automatic seller payment.

## 3. Structured output

Every AI task must return validated structured data.

Store:

```text
provider
model
prompt_version
input_hash
input_snapshot
result_json
validation_status
confidence
tokens_in
tokens_out
estimated_cost
started_at
completed_at
error
```

## 4. Provider abstraction

```php
interface ListingAiAnalyzer
{
    public function analyze(AnalysisInputData $input): AiAnalysisData;
}
```

Implement:

- real provider,
- fake provider,
- optional secondary fallback provider.

## 5. Prompt versioning

Every prompt must have a version.

Example:

```text
buy-analysis-extraction:v1
sell-analysis-copy:v1
risk-language:v1
```

Changing a prompt must not overwrite historical interpretation.

## 6. Validation

AI output must be checked for:

- valid JSON,
- allowed enum values,
- valid numeric ranges,
- required keys,
- impossible contradictions,
- confidence bounds.

## 7. Image analysis

For tool products, AI may inspect:

- visible brand,
- model markings,
- batteries,
- charger,
- case,
- damage,
- serial plate presence,
- obvious missing parts.

AI must not claim authenticity solely from images.

## 8. Cost controls

Implement:

- per-user usage,
- per-organization usage,
- monthly global budget,
- per-task maximum cost,
- caching by input hash,
- image count limits,
- retry limits.

## 9. Human review triggers

Require review when:

- model confidence is low,
- price impact is high,
- images contradict text,
- high-value product,
- high-risk classification,
- no comparable products,
- AI output fails validation.

## 10. AI explanation

User-facing AI text should explain:

- extracted facts,
- assumptions,
- uncertainty,
- required manual checks.

Avoid statements such as:

```text
This product is definitely authentic.
This seller is safe.
You will make €120.
```

Prefer:

```text
Based on the available information, estimated net profit is €80–€130. This depends on the product condition and the actual resale price.
```

## 11. Current implementation boundary

The Phase 2 foundation implements the documented `ListingAiAnalyzer` interface with a deterministic
fake provider only. It stores provider, model, prompt version, immutable input hash and snapshot,
validated structured output, confidence, timing, zero fixture token/cost fields, and errors in one
append-only `AiAnalysis` row per attempt.

The fake AI provider normalizes preserved listing facts and identifies missing price, currency, or
image evidence. It does not itself claim a canonical product, select market evidence, or claim that
price estimation, risk assessment, profit calculation, or deal scoring has run. A separate
`ProductMatcher` contract consumes the validated normalized facts, reads the global catalog, and
stores append-only, versioned, explainable match evidence. Its deterministic fake implementation
supports exact, ambiguous, unmatched, and region-incompatible fixtures without silently creating a
product.

A separate deterministic `ComparableSelector` consumes only a confirmed product match and
tenant-owned approved source evidence. It records bounded ranking and exclusion evidence without
using AI to invent prices, exchange rates, market compatibility, condition, accessories, or seller
facts. A separately versioned deterministic `PriceEstimator` then consumes one exact ready set,
uses recorded dated exchange-rate evidence, and appends weighted-median bands, statistical
decisions, dispersion, and confidence without asking AI to calculate or justify money. A separate
deterministic `RiskEvaluator` then records bounded, explainable transaction-uncertainty signals
from that exact evidence chain. Unknown facts remain zero-point unknowns with verification actions;
AI does not invent seller, condition, ownership, payment, or shipping evidence. A real AI provider
must retain the extraction contract, validate provider-specific output before persistence, enforce
budgets, and pass golden-data tests before it can replace the fake binding.
