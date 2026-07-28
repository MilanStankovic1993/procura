# Procura queue worker and scheduler boundary

The Buy Analysis and notification APIs only persist and dispatch work. Continuously running,
independently scalable queue workers and a once-per-minute Laravel scheduler are therefore required
in every non-local environment.

## Queue worker

Copy `procura.conf.example` into the Supervisor configuration directory and replace the PHP binary,
release path, operating-system user, process count, and log path for the target host.

The example starts four analysis processes, two connector processes, and four notification
processes. The analysis pool gives
the latency-sensitive `analyses` queue priority over the general `default` queue. Its jobs own their
three-attempt backoff policy; the worker timeout is 60 seconds and must remain below the selected
queue connection's `retry_after` value. The 90-second Supervisor shutdown allowance lets an active
analysis stop cleanly.

The isolated `connectors` pool processes bounded private CSV imports without consuming
latency-sensitive analysis capacity. Connector jobs enforce a 5 MB/10,000-row application limit,
own their four-attempt backoff policy, and have a 900-second timeout for slower object storage and
large validated batches. Set the queue connection's `retry_after` above 900 seconds (the documented
database-queue value is 960) and keep the Supervisor shutdown allowance above the worker timeout.
Scale this pool from queue age, row throughput, rejection rate, object-storage latency, database
pressure, and failed jobs.

The isolated `notifications` pool prevents provider latency or a delivery spike from consuming
analysis capacity. Email and Telegram jobs own their four-attempt backoff policy and have a
20-second job timeout; the worker timeout is 30 seconds and its shutdown allowance is 45 seconds.
The queue connection's `retry_after` must exceed the worker timeout. Scale the two pools separately
from observed queue depth, provider rate limits, latency, database pressure, and delivery cost.

Load and validate the configuration:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status procura-analysis-worker:*
sudo supervisorctl status procura-connector-worker:*
sudo supervisorctl status procura-notification-worker:*
```

All hosts must share the same production queue and cache backends so unique-job and scheduler locks
are cluster-wide. Do not use file or array cache stores in a multi-host deployment.

After an atomic release symlink is switched, restart workers so they load the new application code:

```bash
cd /var/www/procura/current
php artisan queue:restart
sudo supervisorctl status procura-analysis-worker:*
sudo supervisorctl status procura-connector-worker:*
sudo supervisorctl status procura-notification-worker:*
```

## Scheduler

Run the scheduler as the same application user every minute:

```cron
* * * * * cd /var/www/procura/current && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduled `analyses:dispatch-pending` command recovers pending outbox rows, interrupted dispatch
claims, and stale processing leases. Recovery remains idempotent and never increases the configured
three provider attempts.

The scheduler also expires unused Telegram link challenges and recovers retryable email and Telegram
delivery heads. These recovery commands are bounded and idempotent; they do not bypass entitlement,
recipient, connection, or maximum-attempt checks.

The scheduler re-dispatches pending marketplace imports and imports whose processing lease expired.
Unique job locks plus immutable import-row keys make replay inert for already processed rows.

When `OPERATIONS_QUEUE_HEARTBEATS_ENABLED=true`, the scheduler also dispatches one lightweight
heartbeat to `analyses`, `connectors`, `notifications`, and `default`. The job is processed by the
same pool as real work and writes only bounded short-lived state to the configured shared cache.
An old delayed job cannot replace newer heartbeat evidence. Run only one production scheduler; the
schedule also uses a cluster-wide one-server mutex.

Verify the schedule after every deployment:

```bash
cd /var/www/procura/current
php artisan schedule:list
php artisan analyses:dispatch-pending --limit=100
php artisan marketplace-imports:dispatch-pending --limit=100
php artisan notifications:recover-email-deliveries --limit=100
php artisan notifications:recover-telegram-deliveries --limit=100
php artisan notifications:expire-telegram-connections --limit=100
php artisan operations:dispatch-queue-heartbeats
php artisan operations:readiness --require-queue-heartbeats
```

The recovery commands are safe to run manually: they only claim eligible records and emit unique
jobs. Alerting for queue depth by queue, failed jobs by channel, repeated stale leases, provider
latency, provider rate-limit responses, and provider cost must be connected to the production
monitoring system before paid traffic is enabled. Also alert when the readiness endpoint returns
`503`, a queue heartbeat is older than policy, heartbeat latency crosses policy, or a heartbeat job
appears in `failed_jobs`. Do not disable heartbeat monitoring merely to clear an alert.

`operations:capacity-baseline` is not a scheduled command and must never be added to cron or
Supervisor. It is a bounded release/diagnostic probe whose production execution requires the
explicit acknowledgement and the approval procedure in `docs/19-production-go-live.md`.
