# 19 - Production Go-Live Register

Last updated: 2026-08-05

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
- `FRONTEND_URLS` and `SANCTUM_STATEFUL_DOMAINS` limited to the same approved production origin
- `TRUSTED_PROXIES` containing only the exact load-balancer/reverse-proxy IPs or CIDRs; catch-all
  values such as `*`, `0.0.0.0/0`, and `::/0` are prohibited
- `APP_KEY` from the encrypted secret manager; it must remain stable across releases
- `LOG_CHANNEL`, `LOG_LEVEL`, and the production log aggregation destination
- production MySQL connection variables with `DB_TIMEZONE=+00:00`, strict SQL mode, `utf8mb4`,
  a dedicated non-root identity, and no placeholder database name
- shared production cache and queue connection variables, with `REDIS_URL=rediss://...`,
  `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `QUEUE_FAILED_DRIVER=database-uuids`, and
  `REDIS_QUEUE_RETRY_AFTER` greater than the 900-second connector-job timeout
- `OPERATIONS_READINESS_CACHE_STORE=redis` or the exact approved shared cache store
- `OPERATIONS_DASHBOARD_CACHE_STORE=redis` or the same approved shared cache store
- reviewed `OPERATIONS_DASHBOARD_CACHE_TTL_SECONDS` value; repository default is 30 seconds
- `OPERATIONS_QUEUE_HEARTBEATS_ENABLED=false` until every documented production worker pool and
  the singleton scheduler are running
- reviewed `OPERATIONS_QUEUE_HEARTBEAT_QUEUES`,
  `OPERATIONS_QUEUE_HEARTBEAT_MAX_AGE_SECONDS`,
  `OPERATIONS_QUEUE_HEARTBEAT_MAX_LATENCY_SECONDS`, and
  `OPERATIONS_QUEUE_HEARTBEAT_TTL_SECONDS` values
- production sessions using database/Redis plus `SESSION_ENCRYPT=true`,
  `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and reviewed `lax` or `strict` SameSite
- production mail transport variables
- production filesystem/S3-compatible storage variables; default, listing, owned-product, import,
  and broker-report disks must resolve to private storage with `AWS_THROW=true`
- `ANALYSIS_SUBMISSION_ENABLED=false` while the fake provider is configured or a real provider has
  not completed the activation procedure in section 7
- `ANALYSIS_MANUAL_RETRY_ENABLED=false` until the Analysis Operations activation record below is
  approved
- approved bounded values for `ANALYSIS_MANUAL_RETRY_ATTEMPTS` and
  `ANALYSIS_MANUAL_RETRY_MAX_RUNS`
- release and CI build hosts use the exact Node.js version pinned in `.nvmrc`; Angular must never
  be built with an unsupported system-default runtime

Production hosts must not accept analysis submissions through the fake AI/product-matching
providers, use local filesystem for durable private evidence, use database cache across a
multi-host cluster, or use an unmonitored single-process queue. Start from
`deploy/env/procura.production.env.example`; it is deliberately incomplete and must never be used
unchanged or filled with secrets inside Git.

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
php artisan operations:production-preflight --json
```

`npm run build:frontend` also verifies the reviewed nginx security/routing headers, Supervisor
worker pool ownership and timeouts, Redis retry timing, scheduler recovery coverage, and sanitized
production-template defaults. CI separately applies the complete ledger and strict schema/session
compatibility contract on MySQL 8.4 and checks cached readiness through Redis 7.4; that required job
must be green before release approval. The PHP-version matrix retains the complete functional suite.

The preflight reads effective cached configuration and emits one secret-free JSON document.
`blocked` or a non-zero exit code prohibits activation. `review_required` is deployable only when
every warning is explicitly matched to an intentionally excluded/disabled integration in the
release record. `--strict` additionally fails on every warning and is the target for the complete
advertised feature set. `--allow-non-production` exists only for staging/configuration rehearsal.

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

### 3.6 Capacity baseline, queue throughput, Analysis pipeline load/stage attribution, and dashboard aggregate cache

Status: **deterministic query, bounded queue-workload, full Analysis API/pipeline workload,
six-stage attribution, and critical-browser percentile harnesses complete; production-shaped
staging execution and broader saturation/soak evidence pending**

Application controls now present:

- the twelve non-readiness Filament overview counters use one validated 30-second shared-cache
  snapshot with a distributed anti-stampede lock;
- cache failure falls back to the same fixed cold query path; readiness remains independently live;
- the tenant Analysis index API and diagnostic harness use the same tenant-scoped, ordered,
  eager-loaded query builder;
- versioned budgets cover the cold dashboard metrics path, Analysis Operations count, and one
  bounded 50-row tenant Analysis page;
- automated fixtures prove constant query counts with 2,000 Analysis rows;
- `operations:capacity-baseline` performs bounded reads only, emits no SQL or business payloads,
  and refuses production unless `--allow-production-read-only` is explicit;
- `operations:queue-throughput` dispatches bounded synthetic no-op jobs through one configured
  worker pool, stores only expiring timing receipts in shared cache, reports completion,
  jobs/second, and p50/p95/p99 dispatch-to-process latency, and permanently refuses production;
- the repository baselines are 100 jobs, a 60-second timeout, at least 5 jobs/second, p95 no more
  than 15 seconds, and p99 no more than 30 seconds. A release may supply stricter targets but the
  command rejects weaker overrides;
- `operations:issue-analysis-workload-permit` issues an expiring, actor-bound permit for at most 250
  reviewed scenarios and exactly two Analysis mutations per scenario. It works only in staging,
  requires Redis queue/cache and enabled Analysis submission, writes the token only to ignored
  private storage, and has no production override;
- `tools/performance/analysis-pipeline-workload.mjs` authenticates through the normal Sanctum
  cookie/CSRF flow, respects tenant authorization, subscription quota and the API throttle, creates
  unique drafts, submits them to the real `analyses` worker pool, polls normal detail resources,
  and reports aggregate draft/submit/pipeline p50/p95/p99, throughput, failure rate, terminal states
  and HTTP status counts. It never outputs actors, emails, passwords, cookies, permits, Analysis IDs
  or listing IDs;
- the versioned Analysis workload baseline is at least 0.25 completed scenarios/second, draft p95
  no more than 2 seconds, submit p95 no more than 2 seconds, pipeline p95 no more than 60 seconds,
  zero failed scenarios, zero `429`, and zero server errors. A fake-provider rehearsal is always
  marked `evidence_eligible=false` and fails the release gate.
- `operations:issue-browser-workload-permit` issues a staging-only, expiring permit bound to at
  most 20 verified actors, the exact HTTPS staging origin, all three approved critical scenarios,
  a versioned desktop/network/CPU profile, versioned budgets, and a separately consumed allowance
  for every cold sample. The contract hash prevents a private permit file from being edited into a
  weaker evidence claim; production issuance and use have no override;
- `tools/performance/browser-workload.mjs` authenticates every dedicated actor through the normal
  Angular/Sanctum cookie and CSRF flow, opens a fresh cache-cold Chromium context for every
  `/app/overview`, `/app/buy`, and `/app/sell` sample, and waits for application-owned ready state
  after tenant API data resolves. It fails on page/console/network/API errors and emits only
  aggregate document-TTFB, route-ready, LCP and CLS p50/p95/p99 plus generic status/failure counts;
- browser release evidence requires at least 20 samples per scenario, zero failures, TTFB p95 no
  more than 1 second, route-ready p95 no more than 4 seconds, LCP p95 no more than 2.5 seconds, CLS
  p95 no more than 0.1, the exact `browser-desktop-profile:v1`, and the exact
  `browser-workload-budget:v1`. An undersampled rehearsal is always ineligible;
- every terminal AI attempt writes one immutable best-effort metric row with fixed microsecond
  timings for provider analysis, product matching, comparable selection, price/rate estimation,
  risk assessment, finalization and total. It stores no request/result/error payload, tenant/user/
  listing identifier, credential, provider response, hash, or external identifier;
- `operations:analysis-pipeline-stage-metrics` reads a bounded recent staging sample and emits
  identifier-free p50/p95/p99 plus attempt/provider-scope counts. Release evidence requires one
  pipeline version, production-shaped providers, no truncation, at least 20 samples for every
  stage, zero failures, and all `analysis-pipeline-stage-budget:v1` p95 limits. Production has no
  override;
- `operations:purge-analysis-pipeline-metrics` deletes only rows older than the configured
  retention in a bounded batch. The singleton scheduler runs it daily at 02:45; the default and
  sanitized-template retention is 30 days and configuration cannot exceed 90 days.
- migration `2026_08_06_100000_create_sell_price_intelligence_metrics_table.php` adds the anonymous
  append-only Sell performance ledger. Run it through the normal release migration step and verify
  the InnoDB table, primary key, retention index and operation/version/report index before enabling
  traffic; it has no foreign key to business data because it stores no business identifier;
- every successfully committed Sell comparable or normalization recalculation schedules one
  best-effort metric after commit. It contains only a closed operation type, metric/selector/
  algorithm versions, aggregate scope/work counts, scope-discovery/selection/selection-persistence/
  price-band-estimation/price-band-persistence timings and total duration. It stores no tenant,
  user, product, assessment, comparable, country, currency, URL, evidence hash or payload, and a
  telemetry outage cannot roll back or alter the Sell mutation;
- `operations:sell-price-intelligence-stage-metrics` aggregates only comparable recalculations in a
  bounded staging window. Release evidence requires the exact reviewed operation and total-scope
  counts, at least two scopes in every operation, fresh selection/price-band writes, one metric/
  selector/algorithm version, no truncation, at least 20 operations and every
  `sell-price-intelligence-stage-budget:v1` p95 limit. Normalization rows are ordinary monitoring,
  not multi-scope release evidence, and production aggregation has no override;
- `operations:purge-sell-price-intelligence-metrics` deletes one bounded expired batch. The
  singleton scheduler runs it daily at 02:50; retention defaults to 30 days and cannot exceed 90.

Required staging configuration:

```dotenv
OPERATIONS_DASHBOARD_CACHE_STORE=redis
OPERATIONS_DASHBOARD_CACHE_TTL_SECONDS=30
PERFORMANCE_ANALYSIS_WORKLOAD_ENABLED=true
PERFORMANCE_ANALYSIS_WORKLOAD_CACHE_STORE=redis
PERFORMANCE_BROWSER_WORKLOAD_ENABLED=true
PERFORMANCE_BROWSER_WORKLOAD_CACHE_STORE=redis
PERFORMANCE_ANALYSIS_METRICS_ENABLED=true
PERFORMANCE_ANALYSIS_METRICS_RETENTION_DAYS=30
PERFORMANCE_SELL_METRICS_ENABLED=true
PERFORMANCE_SELL_METRICS_RETENTION_DAYS=30
```

Production must set both `PERFORMANCE_ANALYSIS_WORKLOAD_ENABLED=false` and
`PERFORMANCE_BROWSER_WORKLOAD_ENABLED=false`. The sanitized production template already carries
those values, and `operations:production-preflight` fails if either is enabled.
While `ANALYSIS_SUBMISSION_ENABLED=false`, production may keep
`PERFORMANCE_ANALYSIS_METRICS_ENABLED=false`. Enabling Analysis submission requires setting it true;
preflight blocks the release if metric retention/budgets are invalid or the required switch is off.
Sell APIs have no production activation switch, so production must always keep
`PERFORMANCE_SELL_METRICS_ENABLED=true`; preflight fails if that switch is off or its retention,
metric version or versioned budgets are invalid. Both metric recorders are best effort, but disabling
required observability is not an approved steady production state.

Staging procedure:

1. Use MySQL and shared Redis versions/configuration equivalent to production. Never use SQLite,
   array/file cache, or the local database cache as launch evidence. Synchronize the command host
   and every worker host to the same monitored UTC time source before interpreting dispatch-to-
   process percentiles.
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

5. Require exit code zero, query counts no greater than `12/1/2`, every duration within the
   versioned budgets, a 50-row tenant result when sufficient fixtures exist, and no SQL/error text
   in the retained JSON.
6. Record an approved per-pool target that is at least as strict as the repository baseline. With
   real Supervisor workers and normal staging monitoring active, run the bounded queue workload
   separately for `analyses`, `connectors`, `notifications`, and `default`:

```bash
php artisan operations:queue-throughput \
  --queue=<configured-queue> \
  --jobs=<approved-bounded-count> \
  --timeout=<approved-seconds> \
  --minimum-throughput=<approved-jobs-per-second> \
  --maximum-p95-ms=<approved-milliseconds> \
  --maximum-p99-ms=<approved-milliseconds> \
  --acknowledge-load \
  --json
```

7. Require exit code zero, `completed_jobs=expected_jobs`, `missed_jobs=0`, no `error_code`, and
   throughput/p95/p99 within the recorded targets. Preserve the secret-free JSON alongside queue
   depth, failed-job count, worker count/restarts, Redis CPU/memory/latency, database load, and the
   exact release. A local `--allow-non-staging` sync/fake rehearsal is contract verification only
   and is never launch evidence.
8. Exercise concurrent cold Admin requests at snapshot expiry and prove only one recomputation
   reaches MySQL. Confirm warm requests use the snapshot and that cache failure falls back to the
   cold budget while readiness becomes unavailable.
9. Prepare dedicated, verified staging actors with an active synthetic workspace, Analyst or higher
   role, a reviewed plan/quota covering the run, and no customer data. Use multiple actors for
   concurrency; the runner deliberately shapes each actor to 45 authenticated requests/minute below
   the application limit of 60 and treats every `429` as failure. Copy the sanitized examples to
   ignored private storage and replace placeholders from the staging secret manager and synthetic
   fixture inventory:

```bash
cp tools/performance/examples/analysis-workload-accounts.example.json \
  storage/app/private/analysis-workload-accounts.json
cp tools/performance/examples/analysis-workload-scenarios.example.json \
  storage/app/private/analysis-workload-scenarios.json
```

   Keep actor keys unique. Every scenario must reference one actor and a unique
   `(listing_id, target_country_code)` pair belonging to that actor's active workspace; otherwise
   draft idempotency would reuse work and invalidate the capacity claim. Scenario count must equal
   the requested permit count. Never place credentials in command options, shell history, logs or
   the retained report.
10. With approved non-fake analysis and product-matching providers, normal staging monitoring, and
    real workers active, issue the short-lived permit. Repeat `--actor-email` once per dedicated
    actor; use generic staging-only addresses and do not capture this command line as evidence:

```bash
php artisan operations:issue-analysis-workload-permit \
  --actor-email=<staging-load-actor-1> \
  --actor-email=<staging-load-actor-2> \
  --scenarios=<approved-count> \
  --ttl=<60-to-3600-seconds> \
  --acknowledge-load \
  --json
```

   The secret-free response identifies the generated file under `storage/app/private`. Do not copy
   that file into release evidence. If real providers are not yet installed, an operator may add
   `--allow-fake-provider-rehearsal` only to verify the harness; that permit cannot pass the release
   gate.
11. Set paths through environment variables so credentials and the permit never enter process
    arguments, then run the bounded client. The base URL must be the root of the HTTPS staging
    origin; HTTP, credentials in URLs, paths, query strings, and fragments are rejected:

```bash
export PROCURA_ANALYSIS_LOAD_BASE_URL=https://staging.example.invalid
export PROCURA_ANALYSIS_LOAD_PERMIT_FILE=/release/storage/app/private/<generated-permit-file>.json
export PROCURA_ANALYSIS_LOAD_ACCOUNTS_FILE=/release/storage/app/private/analysis-workload-accounts.json
export PROCURA_ANALYSIS_LOAD_SCENARIOS_FILE=/release/storage/app/private/analysis-workload-scenarios.json
export PROCURA_ANALYSIS_LOAD_CONCURRENCY=<1-to-20>
node tools/performance/analysis-pipeline-workload.mjs > analysis-pipeline-workload-report.json
```

12. Require exit code zero, `status=passed`, `evidence_eligible=true`, exact expected completion,
    zero failure/rate-limit/server-error counts and all versioned budgets met. Preserve only the
    aggregate report alongside exact release/provider versions, worker/queue depth and restarts,
    MySQL/Redis CPU, memory, connections, locks and latency, host saturation, background traffic and
    dataset cardinalities. Independently verify the report contains no actor, credential, cookie,
    permit, Analysis or listing identifiers.
13. In the same isolated staging window, with no unrelated Analysis traffic, immediately aggregate
    the internal stage rows. Choose a bounded window beginning before the workload and require the
    sample count to equal the reviewed scenarios; a contaminated, mixed-version, truncated or
    undersampled window must be discarded and rerun, never edited:

```bash
php artisan operations:analysis-pipeline-stage-metrics \
  --window=<approved-bounded-minutes> \
  --limit=<approved-limit-at-least-scenario-count> \
  --minimum-samples=<approved-value-at-least-20> \
  --expected-samples=<exact-reviewed-scenario-count> \
  --json > analysis-pipeline-stage-report.json
```

    Require exit code zero, `status=passed`, `release_evidence=true`, no truncation, one pipeline
    version, zero rehearsal-provider rows, zero failed attempts, and passing p95 for all six stages
    and total. Retain only this aggregate JSON beside the Node report and infrastructure evidence;
    verify again that it contains no Analysis/attempt/tenant/user/listing identifiers or payloads.
14. Delete the local permit file immediately after the run and let the shared-cache permit expire;
    rotate a dedicated actor credential if the file may have escaped private storage. Retain real
    staging Analysis rows as synthetic evidence until release review, then reset them only through
    the approved staging dataset lifecycle, never ad hoc SQL deletion. Disable
    `PERFORMANCE_ANALYSIS_WORKLOAD_ENABLED`, rebuild configuration and reload web/CLI processes when
    the evidence window closes.
15. In a separate isolated Sell window, seed production-shaped synthetic comparable evidence so
    every reviewed comparable mutation recalculates at least two country/currency scopes and creates
    fresh selection and price-band projections. Record the exact operation count and the exact sum
    of scopes before running the bounded aggregator:

```bash
php artisan operations:sell-price-intelligence-stage-metrics \
  --window=<approved-bounded-minutes> \
  --limit=<approved-limit-at-least-operation-count> \
  --minimum-samples=<approved-value-at-least-20> \
  --expected-operations=<exact-reviewed-comparable-operation-count> \
  --expected-scopes=<exact-reviewed-total-scope-count> \
  --json > sell-price-intelligence-stage-report.json
```

    Require exit code zero, `status=passed`, `release_evidence=true`, no truncation, exact operation
    and scope matches, passing multi-scope/fresh-projection statuses, one metrics/selector/algorithm
    version and passing p95 for all five stages and total. Retain only the aggregate JSON with the
    reviewed synthetic dataset cardinalities and MySQL/host evidence; independently verify that it
    contains no business identifier or payload. A contaminated, replayed, single-scope, mixed-
    version, undersampled or over-budget run must be discarded and rerun, never edited.
16. On a dedicated staging runner using a repository-supported Node release, install the exact
    locked dependencies and Chromium build. Copy the accounts template into ignored private
    storage, replace credentials from the staging secret manager, and include reviewed actors for
    every role that is part of launch acceptance. Never put credentials in command arguments,
    logs, screenshots, or retained evidence:

```bash
npm --prefix frontend ci
npm --prefix frontend run install:browser-runtime
cp tools/performance/examples/browser-workload-accounts.example.json \
  storage/app/private/browser-workload-accounts.json
php artisan operations:issue-browser-workload-permit \
  --actor-email=<staging-browser-actor-1> \
  --actor-email=<staging-browser-actor-2> \
  --samples-per-scenario=<approved-value-at-least-20> \
  --ttl=<60-to-3600-seconds> \
  --acknowledge-load \
  --json
export PROCURA_BROWSER_LOAD_BASE_URL=https://staging.example.invalid
export PROCURA_BROWSER_LOAD_PERMIT_FILE=/release/storage/app/private/<generated-permit-file>.json
export PROCURA_BROWSER_LOAD_ACCOUNTS_FILE=/release/storage/app/private/browser-workload-accounts.json
node tools/performance/browser-workload.mjs > browser-workload-report.json
```

    The base URL must exactly match the HTTPS origin sealed into the permit. The runner paces each
    actor, uses a fresh browser cache per sample, consumes one server authorization per scenario,
    and records no screenshot, trace, response body, URL, email, cookie, credential or permit in
    its report. Require exit code zero, `status=passed`, `evidence_eligible=true`, exact samples and
    scenario keys, zero failure and HTTP-error counts, the reviewed Chromium major version and all
    versioned p95 budgets. Delete both private files after review and disable the staging switch.
    `--allow-undersampled-rehearsal` is contract testing only and cannot pass the release gate.
17. Continue with the remaining performance plan: database/cache/worker saturation beyond the
    bounded runs and an approved soak window. Queue throughput, Analysis, Sell, and browser reports
    prove only their covered boundaries.

Production diagnostic boundary:

- staging evidence is mandatory; passing either capacity command alone is not launch approval;
- `operations:queue-throughput` plus Analysis and browser workload permit issuance/use are always
  rejected in production and have no bypass. Do not copy, rename, invoke, or remove their guards to
  evade that boundary;
- `operations:analysis-pipeline-stage-metrics` is also always rejected in production. Production
  records ordinary low-cardinality stage rows only when Analysis is active and uses the normal
  external monitoring path; the scheduled retention purge remains active independently;
- `operations:sell-price-intelligence-stage-metrics` is always rejected in production. Ordinary
  production Sell metrics and the scheduled 02:50 retention purge remain active; never use the
  staging aggregator as a production load or diagnostic command;
- normal production monitoring uses real latency, slow-query, queue, saturation, and error-rate
  telemetry, not repeated capacity commands;
- one production read-baseline run requires an approved maintenance/incident ticket, an off-peak
  window,
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
- stop a staging throughput run by stopping the invoking command and, if necessary, pausing the
  affected staging pool. The marker and receipt keys contain no business data and expire after 15
  minutes; do not flush the complete shared cache to remove them;
- stop an Analysis workload by terminating the Node runner, disabling
  `PERFORMANCE_ANALYSIS_WORKLOAD_ENABLED`, rebuilding configuration, and reloading web/CLI runtimes.
  Allow already accepted Analysis jobs to finish unless the provider/queue incident procedure says
  otherwise; use `ANALYSIS_SUBMISSION_ENABLED=false` to stop all new submissions. Delete the permit
  file, let its cache state expire, preserve aggregate evidence, and never repair staging rows with
  direct SQL;
- if metric writes cause a confirmed operational incident, set
  `PERFORMANCE_ANALYSIS_METRICS_ENABLED=false`, rebuild cached configuration and reload all web/CLI/
  worker processes. This does not change existing Analysis results, but production preflight then
  requires Analysis submission to remain disabled until telemetry is restored. Preserve existing
  metric rows until normal retention; do not delete them ad hoc or roll back the metric migration
  while Analysis workers still run;
- if Sell metric writes cause a confirmed incident, setting `PERFORMANCE_SELL_METRICS_ENABLED=false`
  is a temporary incident action only. Rebuild cached configuration and reload web/CLI/worker
  runtimes, preserve existing rows for normal retention, and do not roll back the metric migration
  while Sell traffic continues. Production preflight must remain failed until telemetry is restored;
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

`ANALYSIS_SUBMISSION_ENABLED` is the independent fail-closed traffic boundary. Keep it `false` in
the initial production deployment: users may prepare immutable drafts, but submission consumes no
quota, creates no dispatch, and performs no provider call. Before setting it `true`:

1. implement and review both configured `ListingAiAnalyzer` and `ProductMatcher` adapters;
2. prove the container resolves non-fake adapters after configuration is cached;
3. complete privacy/retention, regional processing, cost-limit, timeout, retry, circuit-breaker,
   provider-outage, evaluation, and incident-disable evidence;
4. run controlled staging submissions through every queue/recovery path and record accuracy,
   latency, cost, malformed-response, timeout, rate-limit, and outage evidence;
5. set the switch true, rebuild configuration, reload web/workers, rerun
   `operations:production-preflight --strict --json`, and perform one approved low-risk smoke case.

Rollback starts by setting `ANALYSIS_SUBMISSION_ENABLED=false`, rebuilding configuration, and
reloading web/workers. Preserve existing analyses and dispatch history; drain or quarantine queued
work according to the provider incident procedure rather than deleting ledger rows.

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

### 8.5 Broker-request activation and operations

The delivered broker boundary accepts/reviews sourcing requests, records manually reviewed supplier
offers, discloses a versioned commission, opens a transaction/commission ledger on acceptance, and
records evidence for external payment, supplier-order, shipping, delivery, completion, and
commission-settlement outcomes. It also records reviewed refund/dispute investigations after
payment confirmation without changing fulfillment or commission history. It does not contact a
supplier, publish to a marketplace, hold funds, charge a payment method, execute a refund or
chargeback, place an order, call a carrier, or transfer/reverse commission. It can generate a
first-party evidence-derived private PDF after completion; none of these records prove that an
external operation occurred. Name the product owner, commercial-policy owner, finance owner,
operations owner, privacy owner, and incident substitute before activation.

Release procedure:

1. Run `2026_07_29_120000_create_broker_request_tables.php` and
   `2026_07_29_130000_create_broker_request_offer_tables.php`, then
   `2026_07_29_140000_create_broker_transaction_tables.php` and
   `2026_07_29_150000_create_broker_report_tables.php`, followed by
   `2026_08_05_100000_create_broker_payment_case_tables.php`, through the normal release migration.
   Verify the 22-column request projection, 17-column request-event ledger, 35-column immutable
   offer projection, 18-column offer-event ledger, 23-column transaction projection, 19-column
   transaction-event ledger, 20-column commission projection, 20-column commission-event ledger,
   the report projection/immutable report-event ledger, and the payment-case projection/immutable
   payment-case-event ledger. Verify current/previous/source event
   foreign keys, composite tenant/request/offer/
   transaction constraints, one-to-one request/offer/transaction ownership, idempotency and
   sequence uniqueness, actor `SET NULL`, currency/country/category references, and
   status/validity/type/hash/expiry indexes, report logical-source uniqueness, payment-case logical
   identity uniqueness, and transaction-scoped payment-case idempotency.
2. Confirm every production plan version has an intentional `broker_requests.monthly` value.
   Missing or exhausted entitlement must fail closed; do not rely on a hidden browser control or
   client-supplied plan value.
3. Obtain written commercial/finance approval for one versioned commission rule. Set
   `BROKER_COMMISSION_RULE_VERSION` to its immutable identifier and
   `BROKER_COMMISSION_RATE_BASIS_POINTS` to the approved positive integer from `1` through `10000`.
   `250` means 2.50%. New offer presentation fails closed for a blank version, zero/invalid rate,
   or unsafe exact total. A later policy change requires a new rule version; it never rewrites an
   already presented offer, transaction, or commission.
4. Before enabling any switch, prove there are no pre-existing presented offers with a zero,
   invalid, or mathematically inconsistent commission/base/payable snapshot. The migration adds
   zero defaults only for additive schema safety; it does not infer a historical commercial
   agreement. If any legacy row exists, keep all broker write switches false and resolve it through
   a reviewed additive migration/procedure. Never silently backfill or accept it.
5. Set `BROKER_REQUESTS_ENABLED=true` while keeping `BROKER_OFFERS_ENABLED=false`,
   `BROKER_TRANSACTIONS_ENABLED=false`, `BROKER_PAYMENT_CASES_ENABLED=false`, and
   `BROKER_REPORTS_ENABLED=false`. Rebuild the
   configuration cache and reload web, queue, and
   CLI workers. Verify effective config on every runtime pool. The switches gate writes, not
   existing read/audit access. Enable `BROKER_OFFERS_ENABLED=true` only after offer-specific
   staging evidence is approved; keep transaction acceptance/operations disabled until the full
   lifecycle evidence below passes.

   Independently review and set the broker lifecycle attention thresholds. The repository defaults
   are an operational starting point, not an SLA or commercial promise:

```text
BROKER_MONITOR_REQUEST_AGE_HOURS=48
BROKER_MONITOR_OFFER_EXPIRY_GRACE_HOURS=1
BROKER_MONITOR_TRANSACTION_AGE_HOURS=24
BROKER_MONITOR_COMMISSION_AGE_HOURS=72
BROKER_MONITOR_REPORT_PURGE_GRACE_HOURS=26
BROKER_MONITOR_PAYMENT_CASE_AGE_HOURS=48
```

   Rebuild configuration after any threshold change. Production preflight fails when an effective
   value is outside its bounded contract. Changes must be recorded with the operational owner,
   rationale, effective time, affected alert rules, and rollback value; they never rewrite ledger
   timestamps or historical events.
6. With a dedicated staging organization, exercise owner/administrator/analyst/viewer access,
   cross-tenant `404`, draft-only edit, exact create/update/submit/cancel replay, changed replay,
   stale expected head, quota exhaustion/rollback, safe timeline projection, five locales, and
   account-erasure blocking for an active personal request. Then enable offers in staging and prove
   operator-only presentation, exact supplier/commission/customer-payable calculation and
   disclosure, integer half-up rounding, exact request/offer replay, changed replay, stale/expired/
   inconsistent-term rejection, multi-offer alternative closure, cancellation cleanup, viewer
   refusal, cross-tenant `404`, private supplier-reference exclusion, and cross-currency warning.
7. Set `BROKER_TRANSACTIONS_ENABLED=true` in staging only. Prove acceptance atomically creates
   exactly one `awaiting_payment` transaction and one `pending` commission, then prove exact replay,
   changed replay, stale-head rejection, evidence-required operator authorization, every allowed
   fulfillment step, skipped-step refusal, pre-payment cancellation/waiver, post-payment
   cancellation refusal, atomic request completion/commission earning, earned-only settlement, and
   safe subject/Admin projections in all five locales. Rehearse disabling and re-enabling the switch
   without modifying existing history.
8. Verify only a verified super administrator can open the six read-only Broker Admin resources
   and run operator commands. Start review from `submitted`, then search from `reviewing`, retaining
   the new current event after each command:

```text
php artisan broker-requests:transition <request-ulid> reviewing \
  --actor-email=<verified-super-admin> \
  --expected-event=<submitted-current-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=operator_review_started \
  --evidence=<external-case-reference>

php artisan broker-requests:transition <request-ulid> searching \
  --actor-email=<verified-super-admin> \
  --expected-event=<reviewing-current-event-ulid> \
  --idempotency=<new-uuid> \
  --reason-code=operator_search_started \
  --evidence=<external-case-reference>

php artisan broker-offers:present <request-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-request-event=<searching-current-event-ulid> \
  --idempotency=<new-uuid> \
  --supplier-name="<tenant-safe-display-name>" \
  --supplier-reference=<private-vault-or-case-reference> \
  --description="<exact-included-terms>" \
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

Retain an exact UUID only for an exact retry. A changed operation requires a new reviewed command.
The generic command must reject `offers_available`, `accepted`, and `completed`; no operator note
or database update may substitute for the dedicated offer/acceptance/transaction actions. Never
place supplier credentials, personal contacts, quote contents, payment data, or evidence contents
in shell history.

After the corresponding external operation has actually completed and its evidence is stored in
the approved external case/ledger, append exactly one transaction step:

```text
php artisan broker-transactions:transition <transaction-ulid> <target-status> \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-transaction-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=<approved-code> \
  --evidence=<external-ledger-or-case-reference>
```

Allowed sequence:

```text
awaiting_payment -> payment_confirmed -> supplier_ordered -> shipped
-> delivered -> completed
awaiting_payment -> cancelled
```

There is deliberately no transaction cancellation after payment confirmation. Do not edit a row or
overload a fulfillment state for a refund/dispute. Completion atomically completes the request and
earns the commission; pre-payment cancellation atomically cancels the request and waives it.

Keep `BROKER_PAYMENT_CASES_ENABLED=false` until finance, privacy, support, and incident owners have
approved the external refund/dispute procedure, evidence-vault reference format, reason-code list,
and outcome semantics. Then enable it in staging only and open the investigation after the external
provider/support case exists:

```text
php artisan broker-payment-cases:open <transaction-ulid> <refund|dispute> \
  --actor-email=<verified-super-admin> \
  --expected-transaction-event=<current-payment-confirmed-or-later-event-ulid> \
  --idempotency=<uuid> \
  --amount-minor=<positive-integer-no-greater-than-customer-payable> \
  --external-case=<approved-external-case-reference> \
  --reason-code=<approved-code> \
  --evidence=<approved-reviewed-evidence-reference>
```

Move it to review, and only then resolve it with a type-compatible outcome:

```text
php artisan broker-payment-cases:transition <payment-case-ulid> under_review \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-payment-case-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=<approved-code> \
  --evidence=<approved-reviewed-evidence-reference>

php artisan broker-payment-cases:transition <payment-case-ulid> resolved \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-payment-case-event-ulid> \
  --idempotency=<new-uuid> \
  --outcome=<refund_confirmed|refund_rejected|dispute_won|dispute_lost> \
  --resolved-amount-minor=<integer-minor-units> \
  --reason-code=<approved-code> \
  --evidence=<approved-reviewed-evidence-reference>
```

`refund_confirmed` and `dispute_lost` require a positive resolved amount no greater than the
requested amount; `refund_rejected` and `dispute_won` require zero. Cancellation is allowed from
`open` or `under_review` without outcome/amount. Prove exact replay, changed replay, stale heads,
duplicate logical reference, pre-payment refusal, skipped review, incompatible outcome/amount,
history cap, authorization, five-locale safe subject projection, Admin redaction, and erasure
blocking. These commands only record reviewed evidence; perform the real refund/chargeback through
the separately approved provider procedure and never place payment data or evidence contents in
shell history.

After external settlement of an earned commission:

```text
php artisan broker-commissions:settle <commission-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-commission-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=external_commission_settled \
  --evidence=<external-settlement-ledger-reference>
```

9. Provision an approved private object-storage prefix and service identity with read/write/delete
   access limited to broker-report objects. Set and independently review:

```text
BROKER_REPORTS_ENABLED=false
BROKER_REPORT_VERSION=broker-transaction-report:v1
BROKER_REPORT_DISK=<approved-private-disk>
BROKER_REPORT_RETENTION_DAYS=<approved-1..3650>
BROKER_REPORT_DOWNLOAD_TTL_MINUTES=<approved-1..60>
BROKER_REPORT_MAX_BYTES=<1024..10485760>
BROKER_REPORT_PURGE_BATCH=<1..500>
```

   Verify the configured disk is private, encryption/backup/replication/legal-hold behavior matches
   the retention policy, web and CLI identities can write/read/delete only the intended prefix, and
   logs do not contain object bytes, signed URLs, private paths, source snapshots, or evidence.
   Confirm the daily `broker-reports:purge-expired` singleton appears in `schedule:list`, runs from
   a scheduler with the shared cache lock, and alerts on non-zero exit/failure count. Purge must run
   even while generation is disabled.

   Enable `BROKER_REPORTS_ENABLED=true` in staging only, rebuild config, and generate against one
   completed transaction with an earned or settled commission:

```text
php artisan broker-reports:generate <transaction-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-transaction-event=<current-transaction-event-ulid> \
  --expected-commission-event=<current-commission-event-ulid> \
  --idempotency=<uuid> \
  --locale=<en|de|es|fr|sr-Latn> \
  --reason-code=completed_transaction_report \
  --evidence=<external-case-reference>
```

   Prove exact replay is inert; changed replay/logical duplicate, stale source heads, incomplete/
   cancelled transactions, pending/waived commissions, unverified/non-admin actors, and unsupported
   locale fail closed. Render every locale with Poppler and inspect every page for A4 layout,
   diacritics, translated labels, clean breaks and page numbering. Verify the subject API/Admin omit
   paths, disks, source heads, snapshots, hashes, evidence and replay keys. Verify the short-lived
   relative URL still enforces active tenant membership and policy, cross-tenant access is `404`,
   and expired/missing/size-mismatched/checksum-mismatched/purged artifacts are not delivered.
   Expire a staging artifact, run `php artisan broker-reports:purge-expired --limit=100`, and prove
   deletion occurs before the immutable purge event; simulate deletion failure and prove the report
   stays available for retry. Confirm an available personal report blocks erasure and the approved
   privacy export/erasure inventory contracts use `privacy-data-inventory:v4` and
   `privacy-erasure-inventory:v4`.

10. Attach the staging evidence, commercial rule approval, data-protection review, incident
   rehearsal, external operating procedure, and independent release approval. Only then enable
   `BROKER_TRANSACTIONS_ENABLED=true` and `BROKER_REPORTS_ENABLED=true` in production. Enable
   `BROKER_PAYMENT_CASES_ENABLED=true` only if its separate evidence and owner approvals have also
   passed. Reload every web/queue/CLI runtime. Run one
   approved low-value end-to-end acceptance case before expanding access. A transaction/commission
   row is an application ledger, not proof that Procura processed money.

Monitor request creation/submission/cancellation rate, quota rejection, stale/conflicting commands,
requests aging in `submitted`/`reviewing`/`searching`/`offers_available`, target dates, offer
presentation/acceptance/cancellation rate, expired offers, stale/conflicting acceptance,
transactions aging in every non-terminal state, commissions aging in `earned`, report generation
rate/failure/size/page/locale, artifacts nearing/over retention, purge processed/failed counts,
signed-download `403`/`404`, integrity failures, invalid disclosed term rejection, forbidden/skipped
transitions, payment cases aging in `open`/`under_review`, outcome and amount anomalies, payment-case
quota/conflict/stale rejection, settlement rate, unauthorized attempts, command evidence
completeness, database latency, and all six event-history growth rates. Never log product
notes, supplier references, offer terms, payment data, snapshots, replay keys, hashes, or evidence
contents.

Procura provides the bounded projection-side monitoring contract:

```text
php artisan broker-operations:status --json
php artisan broker-operations:status --json --fail-on-attention
```

Run the alerting form at least every five minutes from one monitored scheduler or external job. It
returns non-zero when any aged/past-due request, expired presented offer, delayed non-terminal
transaction, earned unsettled commission, overdue available report artifact, or aged open payment
case is present. Alert on command failure separately from a valid `attention_required` report.
Store the one-line JSON as release/incident evidence only under the approved retention policy. It
contains counts and thresholds, never tenant/user identifiers, supplier/offer facts, money,
storage coordinates, payment data, evidence, hashes, snapshots, or replay keys. Use the six
authoritative read-only Admin resources for reviewed investigation; do not expand the command into
a customer-data export.

Before production activation, attach one clear-state run and one controlled staging
`attention_required` run proving the non-zero exit, dashboard tile, alert delivery, operator
acknowledgement, source-ledger investigation, resolution, and return to clear. Rehearse request,
offer, transaction, commission, report-purge, and payment-case signals independently. This
application contract does not replace provider, refund, supplier, carrier, storage, security, or
database monitoring.

Rollback/incident boundary:

- set `BROKER_REPORTS_ENABLED=false` first to stop generation while keeping the purge schedule
  running. Set `BROKER_PAYMENT_CASES_ENABLED=false` to stop new payment-case writes while retaining
  read/audit access. Then set `BROKER_TRANSACTIONS_ENABLED=false` to stop acceptance, fulfillment
  transitions, and commission settlement. Pause the corresponding external payment/refund/
  supplier/settlement procedures;
- set `BROKER_OFFERS_ENABLED=false` to stop new offer presentation; set
  `BROKER_REQUESTS_ENABLED=false` when all remaining broker request mutations must stop. Rebuild
  configuration and reload every runtime process while preserving read/audit access;
- do not edit/delete request, offer, transaction, commission, report, payment-case, or event rows to
  correct history and do not decrement usage directly;
- retain affected requests/events/evidence under the incident hold;
- do not roll back any of the five migrations while any broker projection/event table contains
  rows or while a downstream record references the added offer columns;
- preserve read/download access for valid existing reports unless security/privacy incident policy
  requires containment; continue retention purge and never bulk-delete object prefixes. Payment/
  refund/chargeback execution, supplier/carrier integration, and marketplace mutation remain
  inactive until their later approved provider procedures exist.

## 9. Privacy request operations

The application contains an audited intake/state ledger, a disabled-by-default data-export
completion boundary, and a separately disabled account-erasure/tombstone boundary. It does not
assemble/deliver archives or infer external object-store, processor, log, analytics, queue, or
backup deletion. Production must keep both `PRIVACY_FULFILLMENT_ENABLED=false` and
`PRIVACY_ERASURE_ENABLED=false` until the following owner-approved record is complete:

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

1. Run `2026_07_28_100000_create_privacy_request_tables.php`,
   `2026_07_29_100000_create_privacy_request_fulfillments.php`, and
   `2026_07_29_110000_add_account_erasure_execution_fields.php` through the normal release migration.
   Verify the request/current-event and event/previous-event chains, subject/actor null-on-delete
   behavior, one-receipt-per-request/event constraints, UUID/payload indexes, and immutable model
   guards.
2. Set and approve `PRIVACY_WORKFLOW_VERSION`, `PRIVACY_NOTICE_VERSION`,
   `PRIVACY_RESPONSE_TARGET_DAYS`, `PRIVACY_FULFILLMENT_VERSION`,
   `PRIVACY_DATA_INVENTORY_VERSION`, `PRIVACY_EXPORT_MAX_BYTES`, and
   `PRIVACY_EXPORT_MAX_RETENTION_DAYS`, plus `PRIVACY_ERASURE_VERSION`,
   `PRIVACY_ERASURE_INVENTORY_VERSION`, and `PRIVACY_BACKUP_MAX_RETENTION_DAYS`. Keep both activation
   switches false, rebuild the configuration cache, and verify the effective values. Repository defaults are
   `privacy-request-workflow:v1`, `privacy-notice:v1`, 30 response days,
   `privacy-fulfillment:v1`, `privacy-data-inventory:v4`, 104857600 bytes, 30 export-retention days,
   `privacy-erasure:v1`, `privacy-erasure-inventory:v4`, and 90 backup-retention days.
3. Verify `/api/v1/me/privacy-requests` requires a verified session, works without active
   organization context, is rate limited, returns cross-subject `404`, and exposes no internal
   hashes, artifact location/checksum, identity evidence, or idempotency values. A completed receipt
   may expose only its ID, type, execution/inventory versions, byte size, artifact expiry,
   backup-purge deadline, and completion time;
   the subject event timeline also exposes its bounded delivery receipt reference.
4. Verify only verified super administrators can open the read-only Admin resource and run the
   commands. No browser control may mutate the ledger.
5. Exercise export and deletion cases in a non-production release environment, including every
   blocker, stale expected-event conflict, exact idempotent retry, cancellation, rejection,
   evidence-bound approval, disabled fulfillment, inventory mismatch, malformed checksum, zero or
   oversized artifact, expired/over-retained artifact, unauthorized operator, changed replay, safe
   projections, erasure-switch/inventory mismatch, incomplete/extra clearance, live ownership,
   active subscription, super-admin continuity, present/unreadable private files, transactional
   rollback, access revocation/tombstone, deadline monitoring, and restored-backup follow-up.
6. Complete the owner-approved archive inventory/format/encryption/malware-scan procedure outside
   this application. Store the archive in the approved private vault, calculate SHA-256 and exact
   byte size, deliver through the approved channel, independently verify the recipient and delivery,
   and set an expiry no later than the configured maximum. Never place contents, credentials,
   identity documents, or a public/download URL in a command option.
7. After export staging evidence is signed off, set `PRIVACY_FULFILLMENT_ENABLED=true`. Enable
   `PRIVACY_ERASURE_ENABLED=true` only after the independent erasure staging/owner sign-off. Rebuild
   and reload configuration on every web/CLI worker and rerun both disabled/enabled smoke cases. A
   configuration change is not active until all long-lived processes have reloaded it.
8. Record the external case/evidence reference on every approval or rejection. Record data-export
   `fulfilled` only through `privacy-requests:complete-export` after secure delivery and independent
   verification. The generic transition command must reject `fulfilled`.
9. Before account erasure, freeze the approved subject from new activity through the reviewed
   operational isolation procedure; clear every snapshotted blocker; remove every inventoried
   private object; finish required processor requests; and retain references only, never evidence
   contents, in command options. Record `fulfilled` only through
   `privacy-requests:complete-erasure`. Evidence cannot override live ownership, billing or
   super-admin blockers, and a known file that exists or cannot be checked blocks the transaction.
10. Monitor open requests by response target and alert the named owner before the approved internal
   escalation threshold. There is intentionally no automatic scheduled transition.

Review/approval/rejection command:

```text
php artisan privacy-requests:transition <request-ulid> <status> \
  --actor-email=<verified-super-admin> \
  --expected-event=<current-event-ulid> \
  --idempotency=<uuid> \
  --reason-code=<approved-code> \
  --note="<reviewed operational note>" \
  --evidence=<external-case-or-artifact-reference>
```

Data-export completion command:

```text
php artisan privacy-requests:complete-export <request-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-event=<approved-current-event-ulid> \
  --idempotency=<uuid> \
  --inventory-version=<exact-approved-inventory-version> \
  --identity-evidence=<external-identity-reference> \
  --artifact-reference=<private-vault-reference> \
  --artifact-sha256=<64-lowercase-or-uppercase-hex> \
  --artifact-size-bytes=<exact-integer> \
  --artifact-expires-at=<ISO-8601> \
  --delivery-evidence=<external-delivery-reference> \
  --note="<reviewed completion note>"
```

Account-erasure completion command:

```text
php artisan privacy-requests:complete-erasure <request-ulid> \
  --actor-email=<verified-super-admin> \
  --expected-event=<approved-current-event-ulid> \
  --idempotency=<uuid> \
  --inventory-version=<exact-approved-erasure-inventory> \
  --identity-evidence=<external-identity-reference> \
  --erasure-evidence=<isolated-erasure-run-reference> \
  --storage-evidence=<private-storage-clearance-reference> \
  --processor-evidence=<processor-clearance-reference> \
  --completion-evidence=<subject-safe-completion-reference> \
  --clearance=<snapshot-blocker-code>=<external-clearance-reference> \
  --backup-purge-due-at=<ISO-8601> \
  --note="<reviewed completion note>"
```

Repeat `--clearance` exactly once for every code stored in `blocking_reason_codes`. Confirm
business ownership, active subscriptions and super-admin status are actually absent. Confirm every
known personal listing image, owned-product image and import artifact has been removed from its
configured private disk; a database row may remain until this command, but its referenced object
must not. The transaction then deletes the personal organization and personal database boundary,
revokes sessions/tokens/integrations, removes personal searches/alerts and memberships, anonymizes
invitation addresses, writes an unverified non-admin tombstone, and retains immutable/business rows
under the pseudonymous user key. Track the backup purge to the recorded deadline and prevent a
restored backup from reactivating the tombstone or its credentials.

Retain the exact UUID and every option only for an exact retry. A changed operation requires review
before a new command; after one receipt exists, changed input conflicts and does not change the
terminal request. Never put customer export contents, credentials, secrets, raw identity documents,
or a public artifact URL in the note/reference fields.

Rollback/incident boundary:

- immediately set the affected `PRIVACY_FULFILLMENT_ENABLED` and/or
  `PRIVACY_ERASURE_ENABLED` switch false, rebuild configuration, and reload all processes if
  evidence, delivery, inventory, artifact disposal, erasure isolation, processor clearance or
  backup handling is uncertain; this blocks new receipts but preserves existing requests/deadlines;
- disable self-service ingress at the edge only under the approved incident process; preserve
  access to existing ledger records and deadline monitoring;
- never edit or delete request/event/fulfillment rows to “correct” history; append only an allowed
  reviewed next event before terminal completion;
- never use direct SQL deletion or `fulfilled` status as a substitute for the approved erasure
  procedure;
- place affected evidence and external processor requests under incident hold;
- do not roll back any privacy migration while any request, event, fulfillment or user tombstone
  exists.

## 10. Final launch gate

Production activation requires all applicable entries above to be marked complete in the release
system, with evidence. At minimum:

- backup restore has been tested,
- migrations and rollback implications have been reviewed,
- secrets exist only in the encrypted production secret manager,
- TLS, mail, storage, queue, scheduler, monitoring, and alerts are operational,
- `operations:production-preflight --strict --json` exits zero for the complete advertised launch
  scope, or every warning in a non-strict successful report is signed off as intentionally excluded,
- `operations:readiness --require-queue-heartbeats --json` exits zero and the external readiness
  monitor confirms controlled failure and recovery,
- production-shaped staging capacity evidence passes the versioned query/duration budgets, every
  configured worker pool passes the bounded queue-throughput completion/p95/p99 target, and the
  remaining full-pipeline concurrent load/saturation/soak scenarios meet the approved launch target,
- privacy, terms, billing, tax, refund, retention, and incident-response procedures are approved,
- load, security, tenancy, authorization, localization, accessibility, and disaster-recovery checks
  meet the launch target,
- a named operator owns every external provider and rotation schedule,
- an independently approved rollback decision and procedure exist.

Open items must remain explicit. A successful local test suite is necessary but is never sufficient
evidence for production readiness.
