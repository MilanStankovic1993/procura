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

Current controls:

- per-user, per-organization, and global monthly USD-minor-unit budgets are enforced atomically
  before every external request;
- every request reserves a conservative upper cost bound and converts it to returned usage after a
  valid response; failed/unknown provider outcomes consume the reservation conservatively;
- a reviewed per-task maximum cost blocks the request before any provider data leaves Procura;
- persistent provider/model circuit state opens after a bounded failure threshold and permits one
  probe after the reviewed cooldown;
- input snapshots and retry counts are already bounded, while reusable validated-result caching by
  input hash remains a later optimization and must not bypass attempt/governance evidence.

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

The Phase 2 foundation implements the documented `ListingAiAnalyzer` interface with three explicit
bindings: a deterministic `fake` provider for automated tests, a native Gemini adapter for free-tier
staging evaluation, and an OpenAI Responses API adapter as the first production candidate. The
external adapters use provider-enforced JSON schemas followed by independent application semantic
validation. Every attempt stores provider, configured model, prompt version, immutable input hash
and snapshot, validated structured output, confidence, timing, returned token counts, conservatively
rounded USD minor-unit cost, and a sanitized error in one append-only `AiAnalysis` row.

External prompts are data-minimized. They include listing title, description, marketplace label,
recorded money, source/target country codes, and a bounded technical evidence summary. They exclude
tenant/user/listing/snapshot/image identifiers, source URL, external marketplace identifier, seller
information, location, checksums, private storage paths, credentials, and image bytes. AI may
normalize title and description only; recorded money, currency, market scope, and evidence count are
projected from the immutable first-party snapshot and cannot be replaced by provider output.

External calls also cross a durable governance boundary before the HTTP request. A monthly ledger
atomically reserves the calculated maximum against global, organization, and requesting-user
budgets. The upper bound treats the complete request byte length as a conservative input-token
ceiling and combines it with the configured maximum output. Free-tier requests reserve one cent so
they cannot bypass the same controls, then settle to the returned zero cost. Successful responses
release the reservation into actual cost; failures with unavailable usage are charged at the
reserved maximum. Budget and circuit rejections are terminal and do not consume queue retries.

Circuit state is provider/model specific and stored outside cache. Consecutive provider failures
open it for the configured cooldown, calls fail before outbound data while open, and exactly one
half-open probe may close it. An abandoned processing lease is reconciled conservatively before a
later attempt. The governance tables contain no prompt, response, credential, listing content, or
customer-facing identifier beyond the existing internal relational keys.

`gemini-3.7-flash` is the staging default and `gpt-5.6-luna` is the production-candidate default.
Both are configuration values rather than permanent model aliases. Gemini free-tier evaluation must
use synthetic, non-confidential data until the processor/data-use review is complete. OpenAI calls
set `store=false` and use a stable idempotency key. Neither adapter performs an automatic HTTP retry;
the existing bounded Analysis dispatch and append-only attempt lifecycle remains the only retry
owner.

The fake provider still supplies deterministic golden fixtures. A separate deterministic
`ProductMatcher` consumes validated normalized facts, reads the global catalog, and records
append-only, versioned, explainable match evidence. Deterministic comparable selection, price
estimation, risk evaluation, profit calculation, and deal scoring remain outside model authority.
Unknown facts remain zero-point unknowns with verification actions; AI does not invent seller,
condition, ownership, payment, shipping, market, exchange-rate, tax, customs, or price evidence.

The adapters do not activate production AI by themselves. `ANALYSIS_PROVIDER=fake` remains the
repository default, `ANALYSIS_SUBMISSION_ENABLED` remains the independent production kill switch,
and the product matcher is still deterministic rehearsal infrastructure. Production activation
still requires approved processor/privacy terms, version pinning policy, global and per-organization
budget values, provider monitoring, golden-data evaluation, controlled staging evidence, and a
production-shaped product matcher. Budget enforcement and circuit breaking are implemented but
remain inactive while the fake provider is selected.
