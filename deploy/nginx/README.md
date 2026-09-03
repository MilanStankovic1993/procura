# Procura production web boundary

This configuration serves the compiled Angular application and Laravel from one HTTPS origin.
Angular handles browser routes. Laravel remains authoritative for APIs, Sanctum, Filament, Livewire,
storage routes, and health checks.

## Release layout

The example expects an atomic release symlink:

```text
/var/www/procura/current
|-- artisan
|-- public
|   |-- index.php
|   `-- spa
|       |-- index.html
|       `-- content-hashed Angular assets
`-- vendor
```

Build and validate the SPA before switching the `current` symlink:

```bash
composer install --no-dev --classmap-authoritative --no-interaction
npm ci --prefix frontend
npm run build:frontend
php artisan optimize
php artisan migrate --force
sudo nginx -t
```

`npm run build:frontend` emits directly to `public/spa` and runs both production serving and
deployment-contract verifiers. A failed output, missing hashed asset, source map, unsafe SPA
fallback, incomplete Laravel route boundary, missing security header, or drift in the associated
Supervisor/environment/scheduler contract fails the build.

Copy `procura.conf.example` into the nginx site configuration, then replace the example domain,
certificate paths, release root, and PHP-FPM socket for the target environment. Do not enable the
site until `nginx -t` succeeds.

After switching the atomic `current` symlink, restart long-running queue workers and confirm the
scheduler using the separate checklist in `deploy/supervisor/README.md`. Web deployment is not
complete while the analysis worker or once-per-minute scheduler is absent.

Configure infrastructure probes deliberately: `/up` is PHP process liveness, while
`/api/v1/health` is traffic readiness and may return `503` for database, shared-cache, or queue
worker failure. A load balancer must not cache the readiness response. Complete the heartbeat
activation and controlled failure/recovery procedure in `docs/19-production-go-live.md` before
enabling paid traffic.

The production environment must use the same HTTPS origin for `APP_URL`, `FRONTEND_URL`, and
`FRONTEND_URLS`. Set `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`,
`SANCTUM_STATEFUL_DOMAINS` to the hostname without a scheme, `APP_ENV=production`, and
`APP_DEBUG=false`. Configure trusted proxies only when a real load balancer or reverse proxy exists;
do not use a wildcard proxy trust boundary.

## Routing contract

| Request | Owner |
| --- | --- |
| `/api/*`, `/sanctum/*` | Laravel |
| `/admin*`, `/filament/*`, `/flux/*` | Laravel / Filament |
| `/livewire-{fingerprint}/*` | Laravel / Livewire |
| `/storage/*`, `/up` | Laravel |
| `/spa/*` | Compiled Angular assets only |
| Other `GET` and `HEAD` requests | Angular `index.html` fallback |
| Other methods outside Laravel prefixes | Rejected by nginx |

The Angular shell is never persistently cached. Content-hashed JavaScript and CSS are immutable for
one year. Deployments must use new release directories so an interrupted build cannot partially
replace the live asset set.

## Post-deployment smoke checks

Run these against the real HTTPS hostname after every release:

```bash
curl --fail --silent --show-error https://procura.example.com/api/v1/health
curl --fail --silent --show-error https://procura.example.com/login
curl --fail --silent --show-error https://procura.example.com/app/overview
curl --head --fail https://procura.example.com/admin/login
curl --head https://procura.example.com/spa/index.html
```

Expected behavior:

- API health returns non-cacheable JSON from Laravel with `ok` database, cache, and queue checks;
- direct Angular route refreshes return the Angular shell;
- `/admin/login` reaches Filament rather than the SPA;
- a direct request to `/spa/index.html` is rejected because the shell is internal-only;
- generated `/spa/*.js` and `/spa/*.css` responses include immutable caching;
- the HTML shell includes `no-cache, no-store, must-revalidate`.
