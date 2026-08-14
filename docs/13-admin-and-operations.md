# 13 — Admin and Operations

## 1. Filament modules

Phase 1 currently implements:

- Users
- Organizations
- Organization Memberships
- Plans
- Plan Entitlements
- Organization Plan Assignments
- Subscription Usage
- Countries
- Currencies
- Catalog Imports
- Catalog Import Rows
- Marketplace Sources Explorer
- Brands
- Product Categories
- Product Models
- Product Variants
- Product Variant Markets
- Product Aliases
- Platform Audit Events
- Notification Deliveries
- Telegram Connections
- Billing Provider Events
- Buy Comparable Market Normalizations
- Sell Comparable Market Normalizations
- Privacy Requests
- Listing Explorer
- Analysis Explorer
- AI Analyses Explorer
- Price Estimates Explorer
- Risk Assessments Explorer
- Analysis Operations
- Product Match Reviews
- Broker Requests
- Broker Offers
- Broker Transactions
- Broker Commissions

These resources are read-only except for controlled catalog import, organization plan assignment,
the narrowly scoped Analysis Operations manual retry, and Product Match Review
confirmation/rejection. All mutations delegate to transactional application actions and require a
verified super administrator. Catalog import separately requires dataset provenance, licensing,
and explicit source-rights confirmation; assignment, retry, and review actions require a reason of
at least ten characters. Plan assignment records the
administrator, organization, old and new values, IP address, user agent, and timestamp. Analysis
retry additionally requires the exact current dispatch, UUID idempotency, terminal/no-auto-retry
state, bounded run/attempt policy, one immutable retry event, and one platform audit event. The
product-match queue requires the exact current match head and UUID idempotency. Confirmation
selects only an existing active canonical model and optional valid variant, may create one scoped
operator alias, appends a reviewed match plus an immutable review event, and recalculates dependent
Buy evidence. Rejection preserves the original candidates and leaves the analysis explicitly
blocked for better identification evidence. Both decisions append one platform audit event. The
first super administrator can only be initialized once through the audit-producing
`admin:bootstrap-super-admin` command.

### 1.1 Administrative authority model

Super administrators have full operational authority, not unrestricted raw-table CRUD. The admin
surface follows three explicit mutation classes:

- global master/configuration records may receive controlled create/edit/deactivate workflows only
  through validated application actions, policies, and platform audit evidence;
- tenant and commercial lifecycle changes use named transactional domain actions with exact-head,
  idempotency, reason/evidence, entitlement, and kill-switch controls where applicable;
- immutable evidence, event ledgers, provider receipts, analyses, estimates, assessments, and
  scores remain read-only. Corrections append a new version/event or use a dedicated retention or
  privacy action; even a super administrator cannot rewrite or generically delete history.

Direct Filament CRUD must never be enabled merely because an actor is a super administrator. Each
new mutation requires an approved business transition, backend authorization, audit design,
concurrency/idempotency behavior, negative tests, and rollback/operations documentation.

### 1.2 Admin interface localization

The complete Filament administration surface ships in English, German, Spanish, French, and
Serbian Latin. The authenticated user's validated `users.preferred_locale` is authoritative for
both Angular and Filament, while the admin login page resolves a supported `Accept-Language` value
and otherwise falls back to English. Serbian Latin is stored as the BCP 47 value `sr-Latn` and
mapped to Laravel's `sr_Latn` catalog directory only at the server boundary.

The user menu contains a personal language action. It shares the same
`UpdatePreferredLocale` application action as the primary application, changes the active request
locale before its success notification is created, and returns the operator to the current admin
resource. It does not change organization, market, currency, role, or authorization context.

Every custom resource label, navigation group, table column, action, modal, dashboard statistic,
known enum/domain value, country name, and currency name uses the central `admin.php` catalog.
English is the reference key contract; all five catalogs must contain the same non-empty keys.
Application-owned Filament vendor overrides fill upstream gaps for required accessibility labels,
notifications, boolean states, and result counts without modifying files under `vendor/`.

The read-only **Analysis Explorer** gives verified super administrators one support-oriented view
of every tenant analysis. It projects organization, listing, market route, lifecycle status,
canonical product, current price estimate, current risk assessment, and current deal score through
bounded eager-loaded relationships. It intentionally excludes request and result payloads, listing
snapshots, hashes, AI input/output, provider errors, and internal diagnostic messages. Failed heads
that are eligible for intervention remain separately owned by **Analysis Operations**.

The read-only **AI Analyses Explorer** exposes the append-only provider-attempt ledger needed for
support and quality monitoring: tenant, parent analysis/listing, attempt, lifecycle and validation
status, provider/model/prompt version, confidence, elapsed time, and aggregate product-match count.
It never renders the input hash or snapshot, structured result, provider error, token counts, or
estimated cost. Mutations and retries remain outside this resource and continue through the
existing guarded Analysis Operations boundary.

The read-only **Price Estimates Explorer** projects immutable pricing heads for global support:
tenant and parent listing, run/status, localized target market, ISO-minor-unit-aware estimate and
range, confidence, aggregate input/included/outlier/unresolved counts, dispersion, and algorithm/
rate-resolver versions. It does not load estimate items and never renders input/estimate hashes,
reason or confidence JSON, input snapshots, comparable identities, or item-level FX evidence.

The read-only **Risk Assessments Explorer** provides the global critical-risk/low-confidence queue:
tenant and parent listing, market route, immutable run/status, score, level, confidence, and
aggregate signal/unknown counts, with optional evaluator version and support IDs. It does not load
signals and never renders input/assessment hashes, reason or confidence JSON, verification actions,
input snapshots, upstream evidence IDs, signal codes/sources, or signal-level evidence/actions.

The read-only **Listing Explorer** provides the corresponding global intake overview. It exposes
only organization, title, marketplace, ISO-minor-unit-aware asking price, market route, lifecycle
status, and aggregate image/analysis counts, with optional support identifiers hidden by default.
Description, seller information, location, notes, raw input, and source URL are never rendered.
Filtering is available by status, connector, source market, target market, and currency.

The read-only **Marketplace Sources Explorer** exposes the global connector registry's public
operational projection: connector type, compliance state, geographic coverage, listing/import
counts, quality scores, cross-border capability, active state, and optional capability/terms
metadata. Compliance filtering is data-driven because the stored status is deliberately not a
closed enum. Contact people, legal basis, allowed/prohibited operations, rate limits, retention and
attribution rules, and internal review notes are never rendered.

The following broader product/operations resources remain scheduled for their corresponding
phases:

- Deal Scores
- Saved Searches
- Alerts
- Settings
- Audit Logs

## 2. Operational queues

Admin review queues:

- unmatched and ambiguous products (implemented),
- low-confidence analyses,
- failed AI jobs,
- failed notifications,
- high-value analyses,
- critical-risk analyses,
- disputed estimates.

The read-only Filament Notification Deliveries resource exposes the append-only channel, event,
recipient, organization, saved-search, sequence, attempt, timestamp, and bounded failure summary.
The read-only Telegram Connections resource exposes lifecycle status, bot name, safe truncated
identity hash, challenge expiry, connection time, and revocation time without ciphertext,
plaintext identifiers, tokens, or provider credentials. Neither resource permits mutation or
provider retry from the browser.

The read-only Billing Provider Events resource exposes organization, translated subscription event
type, provider status, projection outcome/reason, projected internal plan, occurrence time, and
test/live mode. It intentionally excludes provider event, customer, subscription and Price IDs,
payload hashes, raw payloads, credentials, and payment data. The Subscriptions resource also shows
assignment source and safe provider status so operators can distinguish manual from Stripe-owned
state. Neither resource permits provider mutation or replay.

The Buy and Sell market-normalization resources are separate read-only ledgers. They expose tenant,
source/target route, original and normalized integer-minor-unit amounts, factor, total explicit
landed costs, dated rate provider/reference/effective time, evidence reference/note, actor, version,
hash, and observation time. Raw evidence, credentials, and mutation controls are not exposed.

The Privacy Requests resource is also read-only. It exposes the subject, request type/status,
response target, blocker snapshot, bounded reason, current event/evidence reference, workflow
version, resolution time, and safe fulfillment receipt/version/expiry metadata without
requester-email, payload, active-key, artifact location/checksum, identity evidence, or idempotency
hashes. The bounded delivery receipt reference remains visible as current-event evidence. The
dashboard counts open requests.

The Broker Requests resource is a localized read-only operations ledger. It exposes the
organization, requester, request/status/condition, quantity, bounded budget, target markets,
needed-by date, current event reason/evidence, actor, sequence, and submit/resolution times. It
does not expose request snapshots, payload/request hashes, idempotency keys, or any browser
mutation. Operators start review/search or cancel only through:

```text
php artisan broker-requests:transition <request-ulid> <reviewing|searching|cancelled> \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=<approved-code> \
  --evidence=<external-case-reference>
```

`offers_available`, `accepted`, and `completed` are deliberately rejected by that generic command.
The first two are owned by the dedicated offer actions. The localized Broker Offers resource is
also read-only and exposes safe supplier/price/validity/status/current-evidence operations data
without snapshots, hashes, or replay keys. Present a reviewed supplier quote only through:

```text
php artisan broker-offers:present <searching-request-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-request-event=<current-request-event-ulid> \
  --idempotency=<uuid> \
  --supplier-name="<tenant-safe-display-name>" \
  --supplier-reference=<private-vault-or-case-reference> \
  --description="<exact included terms>" \
  --condition=<new|used|refurbished> \
  --quantity=<whole-number> \
  --unit-price-minor=<integer-minor-units> \
  --shipping-minor=<integer-minor-units> \
  --tax-duty-minor=<integer-minor-units> \
  --other-cost-minor=<integer-minor-units> \
  --currency=<ISO-4217> \
  --valid-until=<future-ISO-8601-time> \
  --evidence=<external-quote-evidence-reference>
```

Do not put supplier credentials, quote contents, or personal contact data in command history.
Retain the UUID only for an exact retry. A corrected quote is a new immutable offer, never an edit.

The Broker Transactions and Broker Commissions resources are separate localized, read-only
operations ledgers. Transactions expose exact supplier/commission/payable totals, status,
timestamps, bounded current evidence, actor, and sequence. Commissions expose the immutable rule
version/rate/base/amount, status, timestamps, bounded current evidence, actor, and sequence. Neither
resource exposes snapshots, hashes, replay keys, or a browser mutation.

Procura records evidence about externally completed payment and supplier operations; it does not
execute them. A verified super administrator appends one exact-head transaction step only through:

```text
php artisan broker-transactions:transition <transaction-ulid> \
  <payment_confirmed|supplier_ordered|shipped|delivered|completed|cancelled> \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-transaction-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=<approved-code> \
  --evidence=<external-ledger-or-case-reference>
```

The only cancellation path is `awaiting_payment -> cancelled`; after payment confirmation the
transaction ledger is never overloaded with cancellation. Post-payment refund/dispute exceptions
use the dedicated payment-case ledger below and do not rewrite transaction or commission history.
Completion atomically completes the request and earns the commission. Pre-payment cancellation
atomically cancels the request and waives the commission. An earned commission is settled
independently:

```text
php artisan broker-commissions:settle <commission-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-commission-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=<approved-code> \
  --evidence=<external-settlement-reference>
```

Retain a UUID only when retrying the exact same target, head, reason, and evidence. A changed
operation requires a new UUID and a new exact current event. Neither command proves that Procura
held funds, charged a payment method, placed an order, or transferred commission.

The Broker Payment Cases resource is a fifth localized, verified-super-admin-only, read-only
ledger. It displays bounded external case/evidence references for operations while the tenant
projection omits both. Open a case only after reviewed external payment confirmation:

```text
php artisan broker-payment-cases:open <transaction-ulid> <refund|dispute> \
  --actor-email=<verified-super-admin> \
  --expected-transaction-event=<current-transaction-event-ulid> \
  --idempotency=<uuid> \
  --amount-minor=<positive-amount-no-greater-than-customer-payable> \
  --external-case=<approved-support-or-provider-case-reference> \
  --reason-code=<approved-code> \
  --evidence=<reviewed-external-evidence-reference>

php artisan broker-payment-cases:transition <payment-case-ulid> under_review \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-payment-case-event-ulid> \
  --idempotency=<new-uuid> \
  --reason-code=<approved-code> \
  --evidence=<review-evidence-reference>

php artisan broker-payment-cases:transition <payment-case-ulid> resolved \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-payment-case-event-ulid> \
  --idempotency=<new-uuid> \
  --outcome=<refund_confirmed|refund_rejected|dispute_won|dispute_lost> \
  --resolved-amount-minor=<type-compatible-amount> \
  --reason-code=<approved-code> \
  --evidence=<reviewed-outcome-evidence-reference>
```

Refund confirmation and dispute loss require a positive resolved amount no greater than the
requested amount. Refund rejection and dispute win require exactly zero. `cancelled` is permitted
from `open` or `under_review` without outcome/amount. These commands do not submit a refund or
chargeback, move funds, or adjust commission; a real provider operation remains separately gated.

The Broker Reports resource is a sixth localized, verified-super-admin-only, read-only ledger. It
shows organization/request, availability, locale, sequence, page/size metadata, generator and
retention times. It deliberately omits private storage coordinates, source heads, evidence,
snapshots, hashes, and replay keys. Generate a report only after completion through:

```text
php artisan broker-reports:generate <completed-transaction-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-transaction-event=<current-transaction-event-ulid> \
  --expected-commission-event=<current-commission-event-ulid> \
  --idempotency=<uuid> \
  --locale=<en|de|es|fr|sr-Latn> \
  --reason-code=<approved-code> \
  --evidence=<external-case-reference>
```

Retain the UUID for an exact retry. A changed locale, source head, reason, or evidence is a distinct
reviewed operation; the same logical source/version/locale must reuse its original UUID. Expired
artifacts are removed by the daily singleton schedule. A controlled manual recovery run is:

```text
php artisan broker-reports:purge-expired --limit=100
```

The purge action remains active when report generation is disabled so retention obligations cannot
be disabled with the generation switch. A failed storage deletion is reported and retried later;
operators must never mark a report purged or delete its database row manually.

Operators transition a request only through:

```text
php artisan privacy-requests:transition <request-ulid> <status>
  --actor-email=<verified-super-admin>
  --expected-event=<current-event-ulid>
  --idempotency=<uuid>
  --reason-code=<approved-code>
  --note="<operational explanation>"
  --evidence=<external-evidence-reference>
```

The exact UUID must be retained for a retry of the same transition. Approved and rejected
transitions require evidence. `fulfilled` is reserved and this generic command rejects it.

After a separately approved export archive has been securely delivered, the operator records its
completion only through:

```text
php artisan privacy-requests:complete-export <request-ulid>
  --actor-email=<verified-super-admin>
  --expected-event=<approved-event-ulid>
  --idempotency=<uuid>
  --inventory-version=<approved-version>
  --identity-evidence=<external-reference>
  --artifact-reference=<private-vault-reference>
  --artifact-sha256=<64-hex>
  --artifact-size-bytes=<integer>
  --artifact-expires-at=<ISO-8601>
  --delivery-evidence=<external-reference>
  --note="<reviewed completion explanation>"
```

The command never assembles or delivers the archive. It atomically records the terminal event,
immutable receipt, and platform audit evidence.

After the approved erasure run has removed every inventoried external/private artifact and resolved
all blockers, account deletion is completed only through:

```text
php artisan privacy-requests:complete-erasure <request-ulid>
  --actor-email=<verified-super-admin>
  --expected-event=<approved-event-ulid>
  --idempotency=<uuid>
  --inventory-version=<approved-erasure-inventory>
  --identity-evidence=<external-reference>
  --erasure-evidence=<isolated-run-reference>
  --storage-evidence=<private-storage-clearance-reference>
  --processor-evidence=<processor-clearance-reference>
  --completion-evidence=<subject-safe-receipt-reference>
  --clearance=<blocker-code>=<evidence-reference>
  --backup-purge-due-at=<ISO-8601>
  --note="<reviewed completion explanation>"
```

Repeat `--clearance` exactly once for every blocker snapshotted on the request. The command still
recalculates business ownership, active subscriptions and super-admin state; evidence cannot
override a live blocker. It verifies known private files are absent, then atomically removes the
personal database boundary and revocable access, creates the tombstone/receipt/audit record, and
leaves retained business history pseudonymous. It does not perform or imply external
object-storage, processor, log, analytics, queue or backup deletion.

The Analysis Operations resource is a focused exception queue, not a general tenant Analysis
browser. It lists terminal failures, stale processing leases, failed dispatch heads, and stale
dispatch claims. It exposes only localized safe error codes, bounded run/attempt metadata, timing,
tenant/listing identity, and the last reviewed retry reason/operator. Raw analysis and dispatch
errors, payload/idempotency/error hashes, provider secrets, and result/request payloads are excluded.
The dashboard shows the same query count.

The resource has one controlled mutation: a verified super administrator may retry only a terminal
failed analysis with no automatic retry scheduled. The modal requires a reviewed reason and carries
the exact current dispatch plus a generated UUID. It delegates to the same transaction/audit action
as the CLI, creates a new append-only dispatch run and immutable retry event, preserves the original
quota charge, and rejects stale state or changed UUID reuse. The action and its Admin visibility are
fail-closed while `ANALYSIS_MANUAL_RETRY_ENABLED=false`, which is the repository default.

Equivalent operator command:

```text
php artisan analyses:manual-retry <analysis-ulid>
  --actor-email=<verified-super-admin>
  --expected-dispatch=<current-failed-dispatch-ulid>
  --idempotency=<uuid>
  --reason="<reviewed operational reason>"
```

Retain the UUID only when retrying the exact same command. A changed reason, dispatch, analysis, or
operator requires a new UUID. Do not retry poison input, an unresolved provider incident, or an
analysis that already has an automatic recovery scheduled.

Retryable email and Telegram heads are recovered by scheduled bounded commands; unused Telegram
challenges are expired every minute. Exhausted or uncertain outcomes require a separately audited
operator workflow before any future manual resend. Production deployment must run a dedicated
`notifications` worker pool and register the public HTTPS webhook through
`notifications:configure-telegram-webhook`.

## 3. Dashboard metrics

- active users,
- paid users,
- analyses today,
- analyses this month,
- completion rate,
- AI cost,
- average AI cost per analysis,
- unmatched percentage,
- high-risk percentage,
- subscription conversion,
- verified user value,
- estimate accuracy,
- active Telegram connections,
- failed email delivery heads,
- failed Telegram delivery heads,
- rejected billing projections and active-subscription conflicts,
- open privacy requests and response targets,
- analyses requiring operator attention,
- broker lifecycle heads requiring operator attention,
- operational readiness across the database, shared cache, and configured queue worker pools.

The operational-readiness tile is localized in all five Admin locales. `Ready` means all configured
checks and queue heartbeats are current. `Core ready` means only database and cache checks are
active because queue-heartbeat monitoring is disabled. `Unavailable` requires operator attention.
The dashboard never displays queue names, connection names, exceptions, or cache keys.

All non-readiness overview counts share one validated, stampede-protected cache snapshot for at
most 30 seconds. The cache contains twelve non-negative global counters only. Privacy, failure,
Analysis Operations, and broker lifecycle tiles can therefore lag their source ledgers by the configured short TTL;
their underlying resources remain authoritative. A cache outage falls back to the fixed cold query
path while the readiness tile independently reports the cache failure.

The broker tile is backed by one constant-query classifier. It covers current-state age for
submitted/reviewing/searching/offers-available requests, past needed-by dates, presented offers
beyond their expiry grace, non-terminal transactions, earned commissions awaiting settlement,
available report artifacts beyond purge grace, and open/under-review payment cases. Operators use:

```text
php artisan broker-operations:status --json
php artisan broker-operations:status --json --fail-on-attention
```

The first command is report-only. The second exits non-zero when any attention head exists and is
the monitoring/alerting contract. Both outputs contain stable counts and effective thresholds only;
they never contain organization/user identifiers, supplier or offer facts, money, private storage,
evidence, hashes, snapshots, or replay keys.

For exact internal queue evidence, operators use:

```text
php artisan operations:readiness --require-queue-heartbeats
php artisan operations:readiness --require-queue-heartbeats --json
```

The JSON form is intended for deployment automation and emits one JSON document with a non-zero
exit code when readiness fails. The public `GET /api/v1/health` projection is deliberately
language-neutral and topology-free because it is a machine/load-balancer contract, not user copy.

Before switching an inactive release into service, operators use:

```text
php artisan operations:production-preflight --json
php artisan operations:production-preflight --strict --json
```

The first form blocks unsafe effective configuration while allowing explicit warnings for
disabled/excluded external integrations. The strict form also blocks warnings and is the target for
the complete advertised feature set. Output contains stable check names and guidance only: it never
contains application keys, database/storage/provider credentials, webhook secrets, or tokens.
Checks cover the HTTPS/CORS/Sanctum origin, trusted hosts/proxies, key/debug mode, strict UTC/
`utf8mb4` MySQL, encrypted Redis cache/queue, queue visibility timeout, secure shared sessions,
private fail-loud S3 storage, real mail, analysis-provider kill switch, heartbeat pools, broker/
privacy dependencies, optional integration state, cached release state, and the built Angular shell.

Capacity diagnostics use:

```text
php artisan operations:capacity-baseline --json
php artisan operations:capacity-baseline \
  --organization=<staging-organization-ulid> \
  --enforce-duration \
  --json
```

The tenant probe returns only a result count and aggregate timing/query evidence; it does not emit
organization identity or Analysis data. The command output is an operator/machine contract and is
not application UI, so it remains language-neutral.

## 4. Manual override

Overrides must require:

- reason,
- administrator identity,
- old value,
- new value,
- timestamp.

## 5. Feature flags

Use configurable feature flags for:

- AI provider,
- analysis submission (`ANALYSIS_SUBMISSION_ENABLED`),
- sell analysis,
- Telegram,
- marketplace connectors,
- broker module,
- public signup,
- payment enforcement.

## 6. Failure handling

Every failed pipeline must show:

- failed step,
- exception summary,
- retry count,
- last attempt,
- manual retry action.

The tenant Analysis detail screen currently exposes request, dispatch, attempt, retry, validation,
and terminal failure metadata. Queue-level exhausted failures and stale processing leases reconcile
the same domain state. The localized Analysis Operations queue and audited manual retry action now
cover terminal failed heads. Automatic recovery remains owned by the scheduled dispatcher; manual
retry remains unavailable while an automatic retry exists.

## 7. Controlled product catalog imports

The global product catalog accepts only reviewed UTF-8 CSV datasets uploaded by a verified super
administrator. Every upload requires a source name, immutable dataset version, license or
authorization statement, and explicit source-rights confirmation. The original file remains on the
private configured disk, and its SHA-256 checksum plus the operator identity are retained in the
audit trail.

Required columns:

```text
category_name,category_slug,brand_name,model_name,model_number,
model_canonical_key,variant_name,variant_canonical_key
```

Optional columns:

```text
sku,country_code,market_model_number,voltage_millivolts,plug_type,
measurement_system,warranty_applicable,model_specifications,variant_attributes,
included_accessories,aliases,alias_locale,active
```

`model_specifications` and `variant_attributes` are JSON objects. `included_accessories` is a JSON
list, and multiple aliases use `|` as the separator. Country codes must already exist in the ISO
reference table. Import processing is queued on `CATALOG_IMPORT_QUEUE`; operators inspect aggregate
jobs in **Catalog imports** and every imported, unchanged, or rejected row in **Catalog import
rows**. Existing canonical identities are never silently overwritten: identical records are marked
unchanged and conflicting records are rejected with row-level evidence.

The read-only catalog explorer exposes the resulting **Brands**, **Product Categories**, **Product
Models**, **Product Variants**, **Variant Markets**, and **Product Aliases** to verified super
administrators. These tables provide relationship-aware search, sorting, and operational filters,
but intentionally expose no direct create, edit, or delete action. Canonical mutations remain owned
by the provenance-preserving import and product-match review boundaries.

The scheduler runs `catalog-imports:dispatch-pending --limit=100` every minute to recover pending
or stale processing heads. Production workers and queue-heartbeat monitoring must include the
configured `CATALOG_IMPORT_QUEUE`.
