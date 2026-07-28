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

The choice is an append-only decision event tied to the exact current DealScore. A later score run
starts without a current decision while preserving older events as history. Marking an analysis
`purchased` at this step does not create the actual purchase/outcome aggregate used in Flow D.

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
→ reviews the immutable intake snapshot and marks the intake ready
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

The implemented Phase 3 boundary now continues through deterministic listing-draft and
photo-readiness generation and the sale portfolio. After native or explicitly normalized current
price bands exist, the user
explicitly selects one current country/currency band, quick/recommended/ambitious strategy, target
asking price, and listing language. A price outside the selected range requires a recorded reason.
The system generates append-only source-bound copy and a photo checklist without inventing facts.
Only a current fully ready draft may enter the portfolio.

Draft and ready owned products remain editable with append-only snapshots; archived records are
terminal. A changed snapshot, image manifest, assessment, selector/price version, listing template,
generator, or photo evaluator makes the prior downstream result historical. Evidence, candidate
decisions, outliers, confidence, unknown facts, generated disclosures, photo gaps, and calculation
history remain visible. The user then records external publication, price changes, reservation,
withdrawal, expiry, or relisting manually against the exact current event head. These immutable
events do not call marketplace APIs and do not convert an asking price into a completed sale.
Actual outcome and realized-money records remain a separate Phase 4 step.

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
Buyer workflow marked purchased or owned product prepared
→ no financial record is created automatically
→ user records immutable actual purchase evidence
→ user records an actual cost snapshot with known/unknown categories
→ review-complete listing enters sale portfolio
→ exact external publication lifecycle is recorded manually
→ user records sold, cancelled, or no-sale outcome against the current portfolio event
→ sold plus complete purchase/cost evidence in one reporting currency
→ immutable actual profit and sale duration calculated
→ otherwise explicit unknowns remain
→ estimate accuracy recorded in the following reporting boundary
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

## Flow F — Saved-search monitoring

```text
User opens Saved searches
→ creates explicit catalog, market, price, keyword and optional decision thresholds
→ backend enforces tenant role and current plan limit
→ immutable criteria version becomes the current search head
→ bounded queue job evaluates the latest snapshot of existing tenant listings
→ every new listing snapshot or relevant analysis evidence schedules reevaluation
→ deterministic matcher records matched, not matched, or insufficient evidence
→ matched result creates one deduplicated recipient alert
→ in-app delivery appends a delivered notification event
→ an entitled email opt-in appends queued and attempt evidence before provider delivery
→ an entitled Telegram opt-in additionally requires a verified, non-revoked personal connection
→ email and Telegram use independently recoverable delivery heads on the notifications queue
→ retryable failures use bounded backoff; success, exhaustion, or suppression is terminal
→ recipient may append read, unread, or terminal archived state
→ criteria edits, pause, resume, and archive require the expected current version
```

The matcher compares prices only in the exact configured currency. Radius filtering remains
`insufficient_evidence` until coordinates exist, and financial or scoring thresholds remain
unknown until the exact analysis evidence exists. Search, match, alert, and notification history
is append-only; a user never receives another workspace member's notification.
Email and Telegram content are localized from the recipient's stored personal locale and only
include financial or scoring values present in the immutable alert evidence.

## Flow G — Telegram connection

```text
User opens Notifications in an entitled workspace
→ backend verifies the complete platform-bot configuration
→ a short-lived random one-time challenge is stored encrypted and indexed only by its SHA-256 hash
→ user explicitly opens the generated `t.me` link
→ Telegram sends `/start <challenge>` from a private chat to the secret-authenticated webhook
→ backend locks the user and challenge, verifies expiry and exact user/chat identity
→ encrypted Telegram identifiers and dedicated-key HMAC indexes replace the consumed challenge
→ an immutable connected event is appended
→ repeated webhook delivery is inert
→ user may revoke the connection; an immutable revoked event is appended
→ pending or later delivery attempts recheck the exact connection and suppress after revocation
```

The connection belongs to the person and may be used across that person's entitled workspaces. It
does not grant organization membership or change the active organization. Expired challenges are
removed from the usable head by a bounded scheduled command.

## Flow H — Stripe subscription

```text
Organization owner opens Plan & usage
→ backend exposes only configured server-side Starter and Pro offers
→ owner selects monthly or annual billing
→ tenant-scoped idempotency key creates one hosted Stripe Checkout session
→ browser leaves Procura for Stripe-hosted payment
→ browser return URL changes no entitlement
→ signed Stripe subscription webhook reaches the dedicated API endpoint
→ Cashier persists the local organization subscription and items
→ append-only provider evidence rejects duplicates and stale events
→ exact Price ID and entitled status project one internal plan version
→ unknown price or non-entitled status fails closed to Free
→ existing Stripe customers manage payment and cancellation through the hosted billing portal
```

Only the organization owner may create Checkout or portal sessions. Administrators and other
members may view plan and usage information but cannot manage billing. A manual super-admin plan
assignment blocks self-service replacement until an explicit future operational reconciliation.
