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
- Platform Audit Events
- Notification Deliveries
- Telegram Connections
- Billing Provider Events
- Buy Comparable Market Normalizations
- Sell Comparable Market Normalizations
- Privacy Requests
- Analysis Operations

These resources are read-only except for organization plan assignment and the narrowly scoped
Analysis Operations manual retry. Both delegate to transactional application actions and require a
verified super administrator plus a reason of at least ten characters. Plan assignment records the
administrator, organization, old and new values, IP address, user agent, and timestamp. Analysis
retry additionally requires the exact current dispatch, UUID idempotency, terminal/no-auto-retry
state, bounded run/attempt policy, one immutable retry event, and one platform audit event. The
first super administrator can only be initialized once through the audit-producing
`admin:bootstrap-super-admin` command.

### 1.1 Admin interface localization

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

The following broader product/operations resources remain scheduled for their corresponding
phases:

- Marketplace Sources
- general tenant Analyses beyond the focused exception queue
- Listings
- Products
- Product Aliases
- AI Analyses
- Price Estimates
- Risk Assessments
- Deal Scores
- Saved Searches
- Alerts
- Broker Requests
- Settings
- Audit Logs

## 2. Operational queues

Admin review queues:

- unmatched products,
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
version, and resolution time without requester-email, payload, active-key, or idempotency hashes.
The dashboard counts open requests.

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

The exact UUID must be retained for a retry of the same transition. Terminal approved, fulfilled,
or rejected transitions require evidence. `fulfilled` is permitted only after the reviewed external
export-delivery or erasure procedure has completed; the command itself never exports or deletes
customer data.

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
- operational readiness across the database, shared cache, and configured queue worker pools.

The operational-readiness tile is localized in all five Admin locales. `Ready` means all configured
checks and queue heartbeats are current. `Core ready` means only database and cache checks are
active because queue-heartbeat monitoring is disabled. `Unavailable` requires operator attention.
The dashboard never displays queue names, connection names, exceptions, or cache keys.

All non-readiness overview counts share one validated, stampede-protected cache snapshot for at
most 30 seconds. The cache contains eleven non-negative global counters only. Privacy, failure, and
Analysis Operations tiles can therefore lag their source ledgers by the configured short TTL;
their underlying resources remain authoritative. A cache outage falls back to the fixed cold query
path while the readiness tile independently reports the cache failure.

For exact internal queue evidence, operators use:

```text
php artisan operations:readiness --require-queue-heartbeats
php artisan operations:readiness --require-queue-heartbeats --json
```

The JSON form is intended for deployment automation and emits one JSON document with a non-zero
exit code when readiness fails. The public `GET /api/v1/health` projection is deliberately
language-neutral and topology-free because it is a machine/load-balancer contract, not user copy.

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
