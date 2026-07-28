# 19 - Production Go-Live Register

Last updated: 2026-07-28

This file is the single operational source of truth for everything that must be configured outside
the Procura codebase before a production release can be activated. It contains variable names,
commands, external control-plane work, verification, monitoring, and rollback requirements.

Never place real credentials, tokens, private keys, database passwords, webhook secrets, or customer
data in this file. Store secret values in the target platform's encrypted secret manager and record
only the secret name, owner, rotation date, and verification evidence in the deployment system.

## 1. Mandatory update rule

Every change that introduces or changes any of the following must update this register in the same
pull request:

- environment variables or secrets,
- an external provider or provider dashboard setting,
- DNS, TLS, storage, database, cache, queue, scheduler, or mail configuration,
- a webhook, callback URL, OAuth application, signing key, or allowlist,
- a background worker, scheduled command, retention job, or monitoring alert,
- a migration, backfill, one-time command, or post-release verification step,
- a production-only limitation, manual dependency, or rollback requirement.

An integration is not production-complete until its register entry has configuration, activation,
verification, monitoring, rotation, and rollback instructions. `docs/18-development-handoff.md`
describes the current code state; this file owns deployment actions and production readiness.

## 2. Release ownership record

Complete this section in the deployment ticket or release system, not by committing personal data:

| Evidence | Required value |
| --- | --- |
| Release commit | Exact immutable Git commit |
| Release owner | Responsible operator |
| Approver | Independent production approver |
| Environment | Production |
| Database backup | Backup identifier and restore test evidence |
| Started / completed | UTC timestamps |
| Rollback release | Previously verified release identifier |
| Verification evidence | Logs, dashboards, and smoke-test links |

## 3. Base platform

### 3.1 Required environment

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=https://<production-host>`
- `FRONTEND_URL=https://<production-host>`
- `APP_KEY` from the encrypted secret manager; it must remain stable across releases
- `LOG_CHANNEL`, `LOG_LEVEL`, and the production log aggregation destination
- production MySQL connection variables
- shared production cache and queue connection variables
- `OPERATIONS_READINESS_CACHE_STORE=redis` or the exact approved shared cache store
- `OPERATIONS_DASHBOARD_CACHE_STORE=redis` or the same approved shared cache store
- reviewed `OPERATIONS_DASHBOARD_CACHE_TTL_SECONDS` value; repository default is 30 seconds
- `OPERATIONS_QUEUE_HEARTBEATS_ENABLED=false` until every documented production worker pool and
  the singleton scheduler are running
- reviewed `OPERATIONS_QUEUE_HEARTBEAT_QUEUES`,
  `OPERATIONS_QUEUE_HEARTBEAT_MAX_AGE_SECONDS`,
  `OPERATIONS_QUEUE_HEARTBEAT_MAX_LATENCY_SECONDS`, and
  `OPERATIONS_QUEUE_HEARTBEAT_TTL_SECONDS` values
- production session, trusted-proxy, cookie-domain, and secure-cookie settings
- production mail transport variables
- production filesystem/S3-compatible storage variables
- `ANALYSIS_MANUAL_RETRY_ENABLED=false` until the Analysis Operations activation record below is
  approved
- approved bounded values for `ANALYSIS_MANUAL_RETRY_ATTEMPTS` and
  `ANALYSIS_MANUAL_RETRY_MAX_RUNS`
- release and CI build hosts use the exact Node.js version pinned in `.nvmrc`; Angular must never
  be built with an unsupported system-default runtime

Production hosts must not use the fake AI provider, local filesystem for durable private evidence,
database cache across a multi-host cluster, or an unmonitored single-process queue.

### 3.2 Infrastructure and control-plane work

- Create production DNS records.
- Provision and automatically renew TLS certificates.
- Provision MySQL 8.4 LTS or a documented compatible successor with encrypted backups.
- Provision a shared Redis-compatible cache/queue backend before horizontal scaling.
- Provision private S3-compatible object storage with blocked public access, encryption, lifecycle
  policy, backup/replication policy, and least-privilege credentials.
- Configure centralized logs, error reporting, uptime checks, queue-depth alerts, failed-job alerts,
  database capacity alerts, certificate-expiry alerts, and backup-failure alerts.
- Apply the nginx contract from `deploy/nginx/procura.conf.example`.
- Apply the worker contract from `deploy/supervisor/procura.conf.example`.
- Install the once-per-minute scheduler contract from `deploy/supervisor/README.md`.

### 3.3 Release order

Run build and cache commands in an inactive release directory. Do not mutate bootstrap cache files
inside a release that is currently serving requests.

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm --prefix frontend ci
npm --prefix frontend run lint
npm --prefix frontend run test -- --run
npm run build:frontend
php -d memory_limit=512M vendor/bin/pest
php artisan migrate --force
php artisan db:seed --class=PlanSeeder --force
php artisan optimize
```

After successful checks, atomically switch the current-release symlink, reload PHP-FPM, restart
queue workers, and verify the scheduler. Never run `optimize:clear` against the active production
release during normal deployment.

The release artifact must include `lang/` and the exact Composer-installed Laravel framework
version used to build/test the release. The reverse proxy must preserve the browser
`Accept-Language` request header and the application's `Content-Language` response header. PHP-FPM,
Octane if introduced later, and every long-lived queue process must be reloaded after switching
releases so no previous translator catalog remains in memory. No localization provider, API key,
runtime translation service, or production environment variable is required. Atomic release
validation must also prove that every deployed `ApiErrorCode` has a matching key in all five
`lang/*/api_errors.php` catalogs and every deployed `ApplicationValidationCode` has a matching key
in all five `lang/*/application_validation.php` catalogs; never deploy application code and
language catalogs separately.

### 3.4 Required smoke checks

- `GET /up` returns `200`.
- `GET /api/v1/health` returns `200`, the expected service/version fields, and `ok` database,
  cache, and queue readiness checks.
- The Angular application shell and a direct authenticated browser-route refresh return `200`.
- An unauthenticated protected API request returns `401`.
- Login, logout, email verification, password reset, active-workspace switching, and role boundaries
  work against the production origin.
- One controlled invalid guest registration request with `Accept: application/json` and
  `Accept-Language: sr-RS` returns `422`, creates no account or workspace, includes
  `Content-Language: sr-Latn`, and contains the expected Serbian required-field message. Repeat
  with an unsupported language and require the English fallback; respect the registration
  rate-limit window.
- With an approved non-production acceptance account and an existing mutable test record, submit
  one deliberately stale-head command using `Accept-Language: de-DE`. Require `409`, a stable
  language-neutral `code`, `Content-Language: de`, the expected German catalog message, no state
  change, and no raw exception/provider detail. Remove the controlled test record under the
  approved data procedure after evidence capture.
- With the same approved acceptance account, request
  `GET /api/v1/products/search?q=--` using `Accept-Language: fr-FR`. Require a read-only `422`,
  `Content-Language: fr`, and the expected French `product_search_too_short` catalog message, with
  no database change or internal diagnostic.
- Queue jobs are consumed from `analyses`, `connectors`, `notifications`, and `default`.
- All scheduled commands have a recent successful run.
- No browser console error, server exception, failed job, or unexpected outbound provider call is
  produced by the smoke session.

### 3.5 Operational readiness and queue heartbeats

Status: **application boundary complete; production activation required**

The application now separates process liveness from dependency readiness:

- `/up` proves that one PHP application process responds;
- `GET /api/v1/health` probes the configured database and shared cache and returns `503` when either
  is unavailable;
- after queue monitoring is activated, the same endpoint also requires a recent,
  within-latency-policy processed heartbeat from every configured worker queue;
- the public response exposes only aggregate statuses. Exact queue age and latency are available
  only from the local operator command and the Admin dashboard exposes only a localized aggregate
  state.

Production configuration:

```dotenv
OPERATIONS_READINESS_DATABASE_CONNECTION=
OPERATIONS_READINESS_CACHE_STORE=redis
OPERATIONS_QUEUE_HEARTBEATS_ENABLED=true
OPERATIONS_QUEUE_HEARTBEAT_QUEUES=analyses,connectors,notifications,default
OPERATIONS_QUEUE_HEARTBEAT_MAX_AGE_SECONDS=180
OPERATIONS_QUEUE_HEARTBEAT_MAX_LATENCY_SECONDS=120
OPERATIONS_QUEUE_HEARTBEAT_TTL_SECONDS=900
```

An empty database connection uses the application default. The cache store must be a shared,
lock-capable production store visible to web nodes, the scheduler, and every worker. File, array,
null, or per-host cache is prohibited. Queue names must exactly match the Supervisor/Horizon
configuration. Maximum age must allow the one-minute schedule plus a reviewed transient margin;
maximum latency is the allowed dispatch-to-process delay; TTL must exceed maximum age.

Activation procedure:

1. Provision and verify the shared cache and queue backend on every application role.
2. Start independently monitored workers consuming `analyses`, `connectors`, `notifications`, and
   `default`; confirm their timeout and `retry_after` contracts.
3. Run exactly one production scheduler and verify
   `operations:dispatch-queue-heartbeats` in `php artisan schedule:list`.
4. Keep the feature false through the initial deploy and run
   `php artisan operations:readiness`; database and cache must report `ok`.
5. Set `OPERATIONS_QUEUE_HEARTBEATS_ENABLED=true` on web, scheduler, and worker hosts, rebuild the
   configuration cache, restart workers, then run:

```bash
php artisan operations:dispatch-queue-heartbeats
php artisan operations:readiness --require-queue-heartbeats --json
```

6. Within the approved latency window, require exit code zero, top-level `status=ok`, all three
   dependency checks `ok`, and every configured queue heartbeat `ok`.
7. Verify that a controlled paused staging worker makes its queue stale and produces public `503`,
   then resume it and preserve the recovery evidence. Never perform this failure injection against
   live paid traffic.
8. Configure the load balancer/orchestrator to use `/up` for liveness and `/api/v1/health` for
   readiness. Do not cache either response.

Monitoring:

- alert on sustained public readiness `503`, database/cache probe failure, missing/stale heartbeat,
  heartbeat latency above policy, failed `RecordQueueHeartbeat` jobs, scheduler gaps, queue age and
  depth, and worker restarts;
- record CLI JSON and external-monitor evidence in the release ticket without copying environment
  values, cache payloads, or exception traces into customer-visible systems;
- compare heartbeat latency with real queue depth and job runtime before scaling a pool; a fresh
  heartbeat is an availability signal, not a complete capacity test.

Incident and rollback boundary:

- investigate the named queue through the local CLI and worker control plane; the public endpoint
  intentionally cannot identify it;
- do not increase age/latency limits or disable monitoring merely to turn readiness green;
- if this new monitoring code itself causes a confirmed incident, set
  `OPERATIONS_QUEUE_HEARTBEATS_ENABLED=false`, rebuild configuration, restart affected processes,
  and retain independent queue-depth/failed-job monitoring until a reviewed fix is deployed;
- disabling heartbeat monitoring changes the Admin state to `Core ready` and removes queue state
  from the readiness decision; it is a temporary incident fallback, never production acceptance;
- no schema rollback is required. Heartbeat cache keys contain no business data and expire after
  the configured TTL.

### 3.6 Capacity baseline and dashboard aggregate cache

Status: **deterministic application/query baseline complete; production-shaped load evidence
pending**

Application controls now present:

- the eleven non-readiness Filament overview counters use one validated 30-second shared-cache
  snapshot with a distributed anti-stampede lock;
- cache failure falls back to the same fixed cold query path; readiness remains independently live;
- the tenant Analysis index API and diagnostic harness use the same tenant-scoped, ordered,
  eager-loaded query builder;
- versioned budgets cover the cold dashboard metrics path, Analysis Operations count, and one
  bounded 50-row tenant Analysis page;
- automated fixtures prove constant query counts with 2,000 Analysis rows;
- `operations:capacity-baseline` performs bounded reads only, emits no SQL or business payloads,
  and refuses production unless `--allow-production-read-only` is explicit.

Required staging configuration:

```dotenv
OPERATIONS_DASHBOARD_CACHE_STORE=redis
OPERATIONS_DASHBOARD_CACHE_TTL_SECONDS=30
```

Staging procedure:

1. Use MySQL and shared Redis versions/configuration equivalent to production. Never use SQLite,
   array/file cache, or the local database cache as launch evidence.
2. Load a synthetic or irreversibly anonymized dataset at or above the approved launch profile.
   It must include multiple tenants, at least one tenant with thousands of Analyses, terminal/stale
   operations heads, notification heads, privacy requests, subscriptions, and active markets.
   Customer production data must not be copied into staging.
3. Record the exact release, infrastructure sizes, dataset cardinalities, MySQL configuration,
   cache configuration, worker counts, and background traffic in the release evidence.
4. Run a cold baseline, then the exact tenant probe:

```bash
php artisan operations:capacity-baseline \
  --organization=<synthetic-staging-organization-ulid> \
  --enforce-duration \
  --json
```

5. Require exit code zero, query counts no greater than `11/1/2`, every duration within the
   versioned budgets, a 50-row tenant result when sufficient fixtures exist, and no SQL/error text
   in the retained JSON.
6. Exercise concurrent cold Admin requests at snapshot expiry and prove only one recomputation
   reaches MySQL. Confirm warm requests use the snapshot and that cache failure falls back to the
   cold budget while readiness becomes unavailable.
7. Continue with the remaining performance plan: concurrent creation, queue throughput,
   comparable selection, price/rate resolution, Sell multi-scope recalculation, endpoint/browser
   percentiles, database/cache/worker saturation, and an approved soak window.

Production diagnostic boundary:

- staging evidence is mandatory; passing this command alone is not launch approval;
- normal production monitoring uses real latency, slow-query, queue, saturation, and error-rate
  telemetry, not repeated capacity commands;
- one production run requires an approved maintenance/incident ticket, an off-peak window,
  `--allow-production-read-only`, and preferably no tenant probe unless its load is specifically
  approved;
- `--enforce-duration` should be interpreted against the actual host and current load, never used
  to conceal a known incident by raising repository budgets;
- the command never creates fixtures or cleans data. Direct production fixture generation is
  prohibited.

Rollback/incident boundary:

- set `OPERATIONS_DASHBOARD_CACHE_TTL_SECONDS` to a reviewed value between 5 and 300 seconds and
  rebuild configuration; do not set an unbounded TTL;
- if the dashboard snapshot causes a confirmed incident, deploy the prior release. Cache entries
  contain counters only and expire automatically; no database rollback exists;
- a shared-cache outage already falls back to the cold query path, so restrict Admin access during
  a prolonged outage if repeated cold aggregates add database pressure;
- never edit performance budgets during an incident merely to change command status.

## 4. Mail delivery

Status: **application boundary complete; production provider pending**

Required:

- configure the production mail transport and encrypted credentials,
- verify the sender domain, DKIM, SPF, and DMARC,
- set the verified `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`,
- confirm password-reset, verification, invitation, and saved-search alert templates in all
  supported locales,
- configure bounce, complaint, suppression, and provider-health monitoring,
- verify that the `notifications` worker consumes queued mail,
- document credential rotation and provider-disable rollback.

Activation evidence:

- one successful delivery to controlled addresses on each supported mail path,
- provider event/log evidence without exposing message content,
- one forced transient failure proving retry and recovery behavior.

## 5. Telegram

Status: **application boundary complete; production bot activation pending**

Required secret/configuration names:

- `MONITORING_TELEGRAM_PROVIDER=telegram-bot-api`
- `MONITORING_TELEGRAM_QUEUE=notifications`
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_BOT_USERNAME`
- `TELEGRAM_WEBHOOK_SECRET`
- `TELEGRAM_IDENTITY_HASH_KEY`
- `TELEGRAM_WEBHOOK_URL=https://<production-host>/api/v1/integrations/telegram/webhook`

`TELEGRAM_IDENTITY_HASH_KEY` is a dedicated stable high-entropy key. It is not `APP_KEY`, must be
identical on every application/worker host, and must not be rotated without a reviewed identity
re-indexing migration. Store it in the encrypted secret manager.

External activation:

1. Create and secure the Procura bot through Telegram's official bot administration.
2. Disable or restrict unused bot capabilities.
3. Set all required production secrets on every web and notification-worker host.
4. Deploy and verify `/up` and `/api/v1/health`.
5. Register the exact public HTTPS webhook:

   ```bash
   php artisan notifications:configure-telegram-webhook
   ```

6. Confirm Telegram reports the expected webhook URL and no pending delivery errors.

Verification:

- connect one controlled user through the private `/start` challenge,
- confirm that another account cannot claim the same Telegram identity,
- deliver one localized saved-search alert,
- revoke the connection and prove that later sends are blocked,
- verify that the Admin resource exposes operational state but no token, ciphertext, raw Telegram
  identifier, challenge, or secret.

Monitoring and rollback:

- alert on webhook authentication failures, sustained non-2xx provider responses, stale delivery
  heads, exhausted attempts, and notification queue depth,
- rollback by disabling new link creation, removing the Telegram webhook through the provider
  control plane, and stopping only Telegram delivery while preserving the append-only ledger,
- do not delete connection events or reuse a compromised identity-hash key.

## 6. Stripe billing

Status: **application boundary complete; production activation prohibited until this section is
complete and verified**

Expected configuration names:

- `BILLING_PROVIDER=stripe`
- `BILLING_CHECKOUT_ENABLED=false` until the complete live activation gate is approved
- `BILLING_ALLOW_PROMOTION_CODES`
- `BILLING_COLLECT_TAX_IDS`
- `CASHIER_CURRENCY`
- `CASHIER_CURRENCY_LOCALE`
- `STRIPE_KEY`
- `STRIPE_SECRET`
- `STRIPE_WEBHOOK_SECRET`
- `STRIPE_PRICE_STARTER_MONTHLY`
- `STRIPE_PRICE_STARTER_YEARLY`
- `STRIPE_PRICE_PRO_MONTHLY`
- `STRIPE_PRICE_PRO_YEARLY`
- `BILLING_PRICE_STARTER_MONTHLY_MINOR`
- `BILLING_PRICE_STARTER_YEARLY_MINOR`
- `BILLING_PRICE_PRO_MONTHLY_MINOR`
- `BILLING_PRICE_PRO_YEARLY_MINOR`

External Stripe work:

- create separate test and live Stripe accounts/environments,
- create version-controlled plan-to-price mappings without coupling Procura feature codes to Stripe
  product IDs,
- configure tax, invoice, statement descriptor, supported payment methods, customer emails, and
  billing-portal behavior,
- register the exact production webhook
  `https://<production-host>/api/v1/integrations/stripe/webhook` and enable only the reviewed event
  set; the application intentionally disables Cashier's default browser route,
- configure Stripe role access, MFA, restricted API keys, webhook-secret rotation, and alerts,
- complete legal review for pricing display, taxes, refunds, cancellation, invoices, privacy, and
  Strong Customer Authentication before accepting live money.

The four display amounts are integer minor units and must exactly match their Stripe Price objects
and configured currency. Checkout remains unavailable when any selected Price ID or display amount
is missing. Create the webhook explicitly with the reviewed public URL:

```bash
php artisan cashier:webhook \
  --url="https://<production-host>/api/v1/integrations/stripe/webhook"
```

Retrieve the resulting signing secret through the Stripe control plane, store it as
`STRIPE_WEBHOOK_SECRET`, redeploy configuration, and verify invalid-signature rejection before
enabling Checkout.

Required verification before live activation:

- owner-only Checkout and billing-portal authorization,
- signed webhook acceptance and invalid-signature rejection,
- idempotent duplicate and out-of-order webhook handling,
- successful monthly and annual subscription activation,
- upgrade, downgrade, cancellation, grace-period, failed-payment, incomplete/SCA, refund, and
  disputed-payment scenarios,
- webhook-driven internal entitlement projection with Free as the safe fallback,
- no entitlement escalation based only on a browser return URL,
- complete audit/operations visibility without card data or Stripe secret exposure,
- Stripe test-mode end-to-end evidence followed by a separately approved live-mode smoke payment.

Rollback must preserve Stripe and Procura event history. Disabling Checkout must not disable webhook
processing; already-created subscriptions continue to require reconciliation.

## 7. AI provider

Status: **deterministic fake provider only; production provider pending**

Before activation, record:

- approved provider/model and exact version policy,
- regional processing and data-retention terms,
- credentials, budget caps, per-organization cost controls, timeouts, retries, and circuit breaker,
- redaction/data-minimization rules,
- accuracy/evaluation evidence and deterministic fallback behavior,
- provider outage monitoring and disable switch.

Production must never silently fall back to fabricated AI results.

### 7.1 Analysis processing operations

Status: **review queue complete; manual retry disabled by default pending production approval**

Application controls now present:

- one shared query for terminal failed analyses, stale processing leases, failed dispatch heads,
  stale dispatch claims, and the Admin dashboard count;
- verified-super-admin-only retry through one transactional application action used by Filament
  and CLI;
- exact-current-dispatch optimistic concurrency, UUID idempotency/payload conflict detection,
  immutable retry evidence, composite tenant foreign keys, and platform audit evidence;
- no second quota charge, no mutation of prior dispatch/AI attempts, cumulative bounded attempts,
  and a total dispatch-run ceiling;
- localized EN/DE/ES/FR/sr-Latn operator UI that excludes raw failure messages and internal hashes;
- fail-closed `ANALYSIS_MANUAL_RETRY_ENABLED` kill switch.

Release and activation:

1. Run `2026_07_28_110000_create_analysis_retry_events.php` through the normal release migration.
   Verify `analysis_retry_events`, both migration-owned composite helper indexes, tenant/dispatch
   foreign keys, unique analysis/run, analysis/idempotency and new-dispatch constraints, and that
   pre-existing analysis/usage rows are unchanged.
2. Keep `ANALYSIS_MANUAL_RETRY_ENABLED=false` during deployment and smoke testing. Set
   `ANALYSIS_MANUAL_RETRY_ATTEMPTS` to the approved additional attempts per manual run (repository
   default `3`) and `ANALYSIS_MANUAL_RETRY_MAX_RUNS` to the approved total dispatch runs including
   the original run (repository default `5`, therefore at most four manual runs). Rebuild the
   configuration cache and record the captured values.
3. Verify the dedicated `analyses` worker pool, once-per-minute
   `analyses:dispatch-pending --limit=100` schedule, queue-depth/failed-job alerts, processing lease
   recovery, and Analysis Operations dashboard count.
4. In a non-production release environment, prove ordinary/unverified access denial, terminal-only
   eligibility, automatic-retry exclusion, stale-dispatch conflict, exact UUID replay, changed
   payload conflict, run ceiling, one new dispatch/retry/audit record, unchanged subscription
   usage, and absence of raw failures/hashes in rendered Admin HTML.
5. Assign a named primary operator and independent approver. Approve incident classification,
   provider-health evidence, poison-input handling, customer escalation, retry reason content,
   maximum repeat policy, and audit review cadence.
6. Set `ANALYSIS_MANUAL_RETRY_ENABLED=true` only after steps 1–5 have evidence. Rebuild
   configuration cache, restart workers, and perform one approved synthetic terminal-failure
   recovery without customer data.

Operator procedure:

1. Confirm the analysis is a terminal failure and has no scheduled automatic retry. Do not retry
   while the provider incident, invalid/poison input, rate limit, budget block, or data-quality cause
   remains unresolved.
2. Review restricted logs/evidence without copying credentials, provider tokens, personal data,
   raw customer input, or raw exceptions into the retry reason.
3. Record the exact analysis ULID, current failed dispatch ULID, operator, approver, bounded reason,
   and one generated UUID in the incident/change ticket.
4. Use either the localized Admin action or:

```text
php artisan analyses:manual-retry <analysis-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-dispatch=<current-failed-dispatch-ulid> \
  --idempotency=<uuid> \
  --reason="<reviewed operational reason>"
```

5. If the CLI outcome is uncertain, repeat the exact command with the same UUID. Any changed
   analysis, dispatch, operator, or reason requires a new UUID after a fresh review.
6. Verify one new dispatch run, one immutable retry event, one
   `analysis.manual_retry_requested` platform audit event, active worker consumption, and unchanged
   subscription usage. Monitor the new run through a terminal state and link the outcome.

Monitoring and rollback:

- alert on non-zero/ageing Analysis Operations counts, stale processing/dispatch heads, failed jobs,
  queue age/depth, repeated manual runs, run-ceiling rejections, provider latency/error rate, and
  analysis completion rate;
- under an incident, set `ANALYSIS_MANUAL_RETRY_ENABLED=false`, rebuild configuration cache, stop
  issuing new manual retries, and preserve the worker/scheduler path for already accepted runs
  unless the separate queue incident procedure requires a controlled pause;
- never edit/delete retry, dispatch, AI-attempt, usage, or platform-audit evidence to reverse an
  operator action;
- application rollback must preserve the ledger. Do not roll back the migration while any retry
  event exists. Restore the previous release only after confirming it tolerates the additive
  schema and continues to process already queued dispatches.

## 8. Marketplace, FX, catalog, and storage inputs

Status: **manual and authorized CSV application boundaries complete; production CSV activation
disabled by default; remote feeds pending**

### 8.1 Authorized CSV connector

Application controls now present:

- tenant-scoped `marketplace_imports` with UUID idempotency and content-hash deduplication,
- private source-file storage plus immutable per-row raw/normalized evidence,
- bounded 5 MB/10,000-row streaming validation,
- explicit imported, rejected, and duplicate outcomes,
- asynchronous `connectors` queue processing with unique jobs and stale-lease recovery,
- fail-closed schema, country, currency, URL, status, and source-identity validation,
- user authorization attestation and a read-only localized operations ledger,
- no remote fetch, scraping, browser automation, seller contact, or marketplace mutation.

Required production variables:

- `MARKETPLACE_CSV_IMPORT_ENABLED=false` until every activation item below is approved,
- `MARKETPLACE_IMPORT_DISK=s3` or the reviewed private durable storage disk,
- `MARKETPLACE_CONNECTOR_QUEUE=connectors`,
- `MARKETPLACE_IMPORT_MAX_FILE_KB=5120`,
- `MARKETPLACE_IMPORT_MAX_ROWS=10000`,
- `MARKETPLACE_IMPORT_PROCESSING_TIMEOUT_SECONDS=900`,
- `DB_QUEUE_RETRY_AFTER=960` or another reviewed value strictly greater than the connector worker
  timeout.

Activation:

1. Run migration `2026_07_26_230000_create_marketplace_import_tables.php` through the normal release
   migration step and verify both import tables, foreign keys, uniqueness constraints, and indexes.
2. Confirm the private object-storage bucket blocks public access, encrypts objects, logs access,
   and applies the approved retention/deletion lifecycle to `marketplace-imports/`.
3. Install the isolated Supervisor `procura-connector-worker` pool and the once-per-minute
   `marketplace-imports:dispatch-pending --limit=100` scheduler entry.
4. Approve the customer-facing authorization statement, privacy notice, acceptable-use rule,
   retention period, deletion/export procedure, and support escalation.
5. Record the named compliance and operations owners. A user attestation proves only an
   organization-controlled upload; it does not replace review of a contracted external feed.
6. Exercise valid, invalid-reference, duplicate-identity, UTF-8 BOM, and selected-delimiter rows.
   Verify private storage, queue completion, snapshot provenance, tenant isolation, counts, and
   Admin visibility.
7. Verify queue-depth/oldest-job, processing-age, failed-job, rejection-rate, duplicate-rate,
   object-storage error, database latency, and import-volume alerts.
8. Set `MARKETPLACE_CSV_IMPORT_ENABLED=true` only after evidence is attached to the release ticket
   and configuration caches/workers are refreshed.

Rollback:

- set `MARKETPLACE_CSV_IMPORT_ENABLED=false`, rebuild configuration cache, and restart workers;
- stop only the `procura-connector-worker` pool if processing itself must be halted;
- retain import, row, listing-snapshot, and source-file evidence under the approved incident hold;
- correct/delete customer data only through the reviewed privacy workflow, never by editing
  immutable evidence in place;
- do not roll back the migration while any import records exist.

### 8.2 Buy comparable market normalization and FX evidence

Status: **application boundary complete; production FX source and market-evidence governance
pending**

Application controls now present:

- tenant-scoped immutable `comparable_market_normalizations` linked to one exact analysis,
  comparable, actor, and optional immutable exchange-rate observation,
- explicit compatible/incompatible decision, evidence reference, note, observation time, and user
  attestation,
- exact source/target country, currency, and source amount captured from server-owned evidence,
- 50.00%–150.00% market-factor bound and explicit non-negative shipping, import duty, tax, and
  other costs in target-currency minor units,
- exact half-even decimal/integer calculation with a JavaScript-safe monetary ceiling,
- fail-closed missing/stale FX, same-market misuse, incompatible/mismatched evidence, tenant,
  permission, and canonical-product checks,
- append-only replacement semantics, selector/estimator input hashes and full replay snapshots,
- EN/DE/ES/FR/sr-Latn analyst workflow and read-only operations ledger,
- no default market factor, live rate lookup, customs/tax estimate, remote source call, or evidence
  mutation.

This boundary has no new secret or environment variable. It depends on approved immutable
`exchange_rates`. Until an approved automated FX feed has its own production register entry,
operators may append a reviewed observation with:

```bash
php artisan markets:record-exchange-rate \
  <BASE> <QUOTE> <EXACT_DECIMAL_RATE> <APPROVED_PROVIDER> <PROVIDER_REFERENCE> \
  <EFFECTIVE_AT_ISO8601> <SHA256_OF_PRESERVED_SOURCE_EVIDENCE> \
  --published-at=<ISO8601> --fetched-at=<ISO8601> \
  --note="<release/evidence reference>"
```

Production activation:

1. Run migration
   `2026_07_26_240000_create_comparable_market_normalizations.php` through the normal release
   migration step. Verify foreign keys, unique normalization key, evidence-hash index, and the
   analysis/comparable and target-market indexes.
2. Approve the FX provider/source, retrieval procedure, evidence storage, timestamp interpretation,
   maximum age, decimal precision, correction procedure, retention, and named operator. Keep the
   preserved source artifact outside mutable application logs and pass its SHA-256 to the command.
3. Approve who may determine regional compatibility and market factors, which external evidence is
   acceptable, and how shipping/duty/tax/other costs are sourced. A factor or cost must never be
   copied from an undocumented default or guessed by the application.
4. Record required currency pairs before analyst use. Verify direct, inverse, identity, missing,
   stale, not-yet-effective, duplicate, and corrected-observation cases in the release environment.
5. Exercise compatible and incompatible records, idempotent replay, later replacement evidence,
   factor/cost bounds, authorization, tenant isolation, exact normalized values, recalculated
   selection/estimate/risk, and read-only Admin visibility.
6. Monitor missing/stale rate failures, rate age, abnormal factor/cost values, normalization volume,
   incompatible rate, recalculation errors, database latency, and evidence entered by an unexpected
   actor. Review the immutable ledger periodically.
7. Attach the source artifacts, hashes, command output, calculation replay, authorization test, and
   named business/compliance approval to the release record before allowing production analysts to
   use cross-market evidence.

Rollback and incident handling:

- suspend the organization/user's analysis-management permission or operational use of this form;
  do not edit or delete normalization rows,
- stop an automated FX feed through its own kill switch while retaining already recorded rate and
  normalization evidence,
- append corrected rate evidence and then append replacement normalization evidence; never replace
  a rate or normalization in place,
- preserve all affected comparable sets, estimates, risks, hashes, and source artifacts under the
  incident hold,
- do not roll back the migration while normalization records exist.

Automated FX feeds, reusable country/category market factors, and customs/tax engines remain
inactive until separately implemented and approved here.

### 8.3 Sell comparable market normalization and FX evidence

Status: **application boundary complete; production FX source and Sell market-evidence governance
pending**

Application controls now present:

- separate tenant-scoped immutable `sell_comparable_market_normalizations` linked by database
  constraints to one owned product, exact current assessment, exact Sell comparable, actor, target
  country/currency, and optional immutable exchange-rate observation,
- explicit compatible/incompatible decision, evidence reference, note, observation time, and user
  attestation; later evidence supersedes only the current projection and never rewrites history,
- server-owned source country/currency/amount plus validated assessed target country and active
  target currency,
- 50.00%–150.00% market-factor bound, explicit non-negative target-currency shipping/import
  duty/tax/other costs, half-even math, and JavaScript-safe monetary ceiling,
- fail-closed missing/stale FX, same-market misuse, stale assessment, mismatched product/model,
  invalid normalization snapshot, permission, and tenant checks,
- atomic aggregate refresh of every assessed default country/currency scope and every explicitly
  observed alternate currency scope; selector and price-band v2 freeze and replay the same exact
  normalization without a second rate lookup,
- EN/DE/ES/FR/sr-Latn recording and provenance UI plus a separate read-only Filament operations
  ledger,
- bounded application projection of 25 comparable records and the 10 newest normalization
  decisions per record; full evidence remains available in the immutable operations ledger,
- no default factor, automated live rate, customs/tax estimate, Buy-evidence reuse, remote source
  call, or evidence mutation.

This boundary introduces no secret or environment variable. It uses the same approved immutable
`exchange_rates` and `markets:record-exchange-rate` procedure documented in section 8.2. Production
must not treat the manual command or identity conversion as approval of a provider, market factor,
shipping assumption, duty, or tax source.

Production activation:

1. Run migration
   `2026_07_26_250000_create_sell_comparable_market_normalizations.php` through the normal release
   migration step. Verify composite product/tenant and assessment/product foreign keys, comparable
   and exchange-rate constraints, unique normalization key, evidence-hash index, and exact
   assessment/comparable/target lookup index.
2. Complete the FX approvals and release evidence in section 8.2 for every required pair. Identity
   conversion still requires the regional compatibility/factor/cost evidence below.
3. Approve who may determine Sell regional compatibility and factors, which external artifacts are
   acceptable, whether shipping/duty/tax/other costs are appropriate for an asking-price
   comparison, and how corrections and retention work. Guessed or undocumented defaults are
   prohibited.
4. In the release environment exercise native default scopes, alternate currencies, compatible
   and incompatible evidence, newer superseding evidence, direct/inverse/identity and
   missing/stale rates, exact formula outputs, idempotent replay, tenant/role isolation, stale
   assessment rejection, read-only Admin visibility, and projection/build localization.
5. Monitor missing/stale rate failures, rate age, abnormal factors/costs, incompatible decisions,
   normalization and scope volume, recalculation/database latency, unexpected actors, and
   JavaScript-safe ceiling validation. Review the immutable ledger periodically.
6. Attach source artifacts and hashes, rate-command output, exact calculation replay,
   authorization/tenant test, migration inspection, five-locale evidence, and named
   business/compliance approval to the release record before production managers use the form.

Rollback and incident handling:

- suspend the relevant owned-product management permission or operational use of the form; never
  edit or delete normalization rows,
- stop an automated FX source only through its own future kill switch while retaining recorded
  rates and normalization evidence,
- append corrected rate evidence and then append a newer Sell normalization; never replace either
  record in place,
- retain the affected comparables, selections, bands, listing drafts, hashes, and source artifacts
  under the incident hold,
- do not roll back the migration while Sell normalization rows exist.

### 8.4 Per-source production approval

Each approved source must add its own subsection before activation containing:

- legal authorization and terms evidence,
- authentication and secret names,
- country/currency/market scope,
- rate limits and retry rules,
- source timestamps, provenance, retention, deletion, and correction workflow,
- schema/version change detection,
- monitoring, disable switch, replay/idempotency behavior, and rollback.

Email feeds, partner feeds, official APIs, live FX ingestion, and external catalog feeds remain
inactive until their individual subsections are complete. The generic CSV connector does not
authorize any marketplace or dataset by itself.

Scraping, browser extensions, automatic marketplace publication, and unapproved remote mutations
remain prohibited.

## 9. Privacy request operations

The application contains an audited intake and state-transition ledger, not an automatic export or
erasure engine. Production must keep privacy processing inactive until the following owner-approved
record is complete:

- named legal/privacy owner, security owner, primary operator, and escalation substitute;
- applicable jurisdictions, response deadlines, extensions, and identity-verification standard;
- versioned privacy notice and lawful-purpose/processor inventory;
- record-type retention schedule, legal/tax/fraud/security holds, deletion exceptions, and the
  authority that releases each hold;
- business-workspace ownership-transfer, active-subscription/billing, and super-admin continuity
  procedures;
- export inventory, archive format, malware scan, encryption, secure delivery channel, expiry,
  recipient confirmation, and failed-delivery handling;
- ordered erasure/anonymization procedure for database, object storage, cache, queue, logs,
  backups, analytics, mail/Telegram/Stripe and every approved processor;
- immutable evidence repository, access control, retention, evidence-reference format, and audit
  review cadence;
- breach/misdirection, disputed identity, missed-deadline, failed export, partial erasure, and
  restoration-from-backup incident playbooks.

Release procedure:

1. Run `2026_07_28_100000_create_privacy_request_tables.php` through the normal release migration
   and verify both tables, the current/previous-event foreign keys, subject-null-on-delete behavior,
   unique active and idempotency constraints, and status/response-target indexes.
2. Set and approve `PRIVACY_WORKFLOW_VERSION`, `PRIVACY_NOTICE_VERSION`, and
   `PRIVACY_RESPONSE_TARGET_DAYS`, rebuild the configuration cache, and verify the captured values.
   The repository defaults are `privacy-request-workflow:v1`, `privacy-notice:v1`, and 30 days.
3. Verify `/api/v1/me/privacy-requests` requires a verified session, works without active
   organization context, is rate limited, returns cross-subject `404`, and exposes no internal
   hashes or idempotency values.
4. Verify only verified super administrators can open the read-only Admin resource and run the
   transition command. No browser control may mutate the ledger.
5. Exercise export and deletion cases in a non-production release environment, including every
   blocker, stale expected-event conflict, exact idempotent retry, cancellation, rejection,
   evidence-bound approval/fulfillment, deadline monitoring, and restored-backup follow-up.
6. Record the external case/evidence reference on every approved, fulfilled, or rejected
   transition. Mark `fulfilled` only after secure delivery or approved erasure has completed and
   been independently checked.
7. Monitor open requests by response target and alert the named owner before the approved internal
   escalation threshold. There is intentionally no automatic scheduled transition.

Operator command:

```text
php artisan privacy-requests:transition <request-ulid> <status> \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=<approved-code> \
  --note="<reviewed operational note>" \
  --evidence=<external-case-or-artifact-reference>
```

Retain the exact UUID only for an exact retry. A changed operation requires a new UUID. Never put
customer export contents, credentials, secrets, or raw identity documents in the note/evidence
reference.

Rollback/incident boundary:

- disable self-service ingress at the edge only under the approved incident process; preserve
  access to existing ledger records and deadline monitoring;
- never edit or delete request/event rows to “correct” history; append the reviewed next event;
- never use direct SQL deletion or `fulfilled` status as a substitute for the approved erasure
  procedure;
- place affected evidence and external processor requests under incident hold;
- do not roll back the migration while any privacy request or event exists.

## 10. Final launch gate

Production activation requires all applicable entries above to be marked complete in the release
system, with evidence. At minimum:

- backup restore has been tested,
- migrations and rollback implications have been reviewed,
- secrets exist only in the encrypted production secret manager,
- TLS, mail, storage, queue, scheduler, monitoring, and alerts are operational,
- `operations:readiness --require-queue-heartbeats --json` exits zero and the external readiness
  monitor confirms controlled failure and recovery,
- production-shaped staging capacity evidence passes the versioned query/duration budgets and the
  remaining concurrent load/soak scenarios meet the approved launch target,
- privacy, terms, billing, tax, refund, retention, and incident-response procedures are approved,
- load, security, tenancy, authorization, localization, accessibility, and disaster-recovery checks
  meet the launch target,
- a named operator owns every external provider and rotation schedule,
- an independently approved rollback decision and procedure exist.

Open items must remain explicit. A successful local test suite is necessary but is never sufficient
evidence for production readiness.
