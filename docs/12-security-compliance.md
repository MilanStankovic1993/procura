# 12 — Security and Compliance

## 1. Authorization

Every tenant-owned model must have:

- policy,
- organization scope,
- feature tests for cross-tenant access.

Saved searches, match evidence, alerts, and notification logs are tenant-owned. Search policies
must resolve current organization membership and role on every request. Inbox reads and state
commands require both the active organization and exact recipient user; knowing an alert ULID must
not reveal another member's notification. Expected-head checks and idempotency keys prevent stale
or repeated browser commands from corrupting the append-only ledgers.

Email opt-in is checked against the active backend plan when it is added. Delivery rechecks the
recipient's verified email, current organization membership, and current entitlement immediately
before provider access. Operational logs store bounded exception metadata and provider state, not
passwords, tokens, or a duplicated plaintext email address. Removing membership, losing
entitlement, or losing email verification produces a terminal suppressed event.

Telegram connection creation requires a current entitled organization, but the resulting
connection belongs to the authenticated person and grants no tenant rights. The link challenge is
high-entropy, short-lived, encrypted at rest, and located by a SHA-256 digest. Telegram user/chat
identifiers are encrypted and are uniquely located with HMAC-SHA256 indexes under a dedicated
identity key. Rotating that identity key requires an explicit reindex migration; it must not be
silently replaced during a deployment. Bot tokens, webhook secrets, identity keys, challenge
values, plaintext Telegram identifiers, and provider request URLs are secrets and must not enter
application logs, API resources, audit metadata, or notification failure summaries.

The Telegram webhook uses a constant-time comparison against the configured
`X-Telegram-Bot-Api-Secret-Token`, has its own rate limiter, accepts only exact private-chat
`/start` claims, and treats valid replay as inert. Delivery rechecks membership, plan entitlement,
provider configuration, recipient ownership, and the exact active connection. Revocation or
replacement produces terminal suppression before provider access. Connection lifecycle and
delivery lifecycle are append-only; browser administration is read-only.

Nullable database-unique HMAC indexes are the final concurrent ownership invariant for connected
Telegram user and private-chat identities. Revocation moves the hashes into immutable event
evidence and clears encrypted identifiers plus active indexes, permitting deliberate reassignment
without allowing two connected owners.

The personal interface locale is presentation state only. Changing it in Angular or Filament does
not select an organization, grant a role, alter a policy decision, or change market and currency
scope. Filament access continues to require a verified `is_super_admin` account on every request,
independently of the selected language.

The API and Filament resolve locale inside request middleware. An authenticated user's persisted
preference is authoritative; an unauthenticated `Accept-Language` value can choose only one of the
five compiled catalogs and otherwise falls back to English. The resolved state is reset after the
request, including exception paths, to prevent cross-request leakage in persistent PHP runtimes.
`Content-Language` describes presentation only and must never be used for authorization, tenancy,
pricing, currency conversion, evidence selection, or cache partitioning of business data.

Expected public API conflicts expose only a closed `ApiErrorCode`, the expected field where
applicable, and a message selected from the request locale's compiled `api_errors.php` catalog.
Renderers must not expose `Throwable::getMessage()`; raw provider, concurrency, payload, and
diagnostic details stay in server-side reporting. Catalog parity tests make a new public code
incomplete until all five safe messages exist.

## 2. Uploaded files

Require:

- MIME validation,
- extension validation,
- size limits,
- image dimension limits,
- private storage,
- signed URLs,
- sanitized filenames.

Current listing-evidence limits:

- JPEG, PNG, and WebP raster images only,
- 10 MB per file,
- dimensions from 200 x 200 through 8000 x 8000,
- at most 10 product images and 5 marketplace screenshots per listing,
- generated private storage paths; only a sanitized display filename is retained,
- short-lived signed URLs plus authentication, active-organization scope, and model policy,
- database rollback and private-file cleanup for failed upload batches.

Current owned-product evidence uses the same MIME, extension, 10 MB, and 200–8000 pixel bounds,
with at most 10 files per request and aggregate limits of 12 product, 4 serial-label, 8 defect, and
4 proof-of-purchase images. Signed content access rechecks authentication, active organization,
record policy, and parent/child ownership. Archived records reject upload and deletion, and any
failed batch rolls back metadata and deletes every newly written private file.

Owned-product assessment access inherits the parent product policy and active-organization scope.
The assessment action locks membership, product, exact snapshot, and image metadata before writing.
Only bounded image metadata and checksums enter the deterministic assessment input; private image
bytes and storage paths are not exposed to the matcher. Assessment history is immutable, while
cascade deletion remains limited to deletion of its tenant-owned parent aggregate.

## 3. Secrets

Do not store:

- marketplace passwords,
- payment card data,
- plaintext tokens,
- API secrets in database logs.

Encrypt credentials when storage is unavoidable.

## 4. Logging

Do not log:

- passwords,
- access tokens,
- full payment payloads,
- private uploaded content beyond necessary references.

Public readiness output is also a disclosure boundary. `GET /api/v1/health` may return only the
aggregate database, cache, and queue states plus service/version/time. It must never include
connection or queue names, drivers, hosts, cache keys/payloads, exceptions, SQL, stack traces, or
credentials. Exact per-queue age and latency are restricted to the local operator CLI and must be
protected by host access controls. Queue-heartbeat cache values contain only queue identity and
bounded dispatch/processing timing, expire automatically, and must never carry tenant, user, job
payload, or provider data.

## 5. GDPR

Support:

- privacy notice,
- lawful purpose,
- data export,
- account deletion workflow,
- retention policy,
- consent where required,
- processor inventory.

The implemented self-service boundary accepts only verified users, is independently rate limited,
and stores privacy-notice/workflow versions with every request. It exposes no requester-email,
payload, active-key, or idempotency hashes. Cross-user request identifiers resolve as `404`, while
Admin access requires a verified super administrator.

Privacy requests and their event histories are immutable. State changes use subject/operator
authorization, row locks, optimistic concurrency, UUID idempotency, stable payload hashes, a
previous-event chain, and platform audit events. Approved, fulfilled, and rejected states require a
reference to separately retained operational evidence.

Account deletion currently records deterministic blockers for retention review, business ownership
transfer, active subscription resolution, and super-admin continuity. The workflow deliberately
does not create export archives or erase data automatically. Before production activation, legal
owners must approve identity verification, jurisdictional deadlines, retention/legal holds, secure
export delivery, erasure sequencing, evidence storage/access, incident handling, and processor
inventory. Operators must never use direct SQL deletion as a substitute for that procedure.

Analysis manual retry is a platform operation, not a tenant capability. Only a verified
`is_super_admin` user may request it, through the shared application action. The command requires
the exact current dispatch, UUID idempotency key, and a 10–1000 character reviewed reason. Row locks,
unique run/idempotency constraints, composite tenant foreign keys, and stable payload hashing make
duplicate and stale requests fail safely. Automatic retries cannot be bypassed and a configured
total run ceiling plus per-run attempt bound limits operator escalation. The production kill switch
is false by default and is checked again inside the action, not only in the Admin presentation.

Retry evidence is append-only. The ledger hashes the previous raw exception and retains only the
safe class-level code; the localized Admin projection never renders raw analysis/dispatch error
messages, error hashes, payload hashes, or idempotency keys. The original dispatch may retain its
bounded internal failure evidence for restricted diagnostics, but operators must never place
credentials, provider tokens, personal data, or raw customer content in the retry reason.

Expected platform validation failures are selected from `ApplicationValidationCode`; application
services do not interpolate database state, provider diagnostics, exception messages, tokens, or
customer content into their public `422` copy. `ApplicationValidation` maps a safe code to the
request's EN/DE/ES/FR/sr-Latn catalog and keeps only the established field name in the response.
Stored evidence, authorization decisions, tenant boundaries, and concurrency checks remain
language-neutral.

## 6. User-generated marketplace data

Store only what is necessary.

Define retention and deletion for:

- copied descriptions,
- screenshots,
- seller contact data,
- uploaded product photos.

## 7. Recommendation disclaimer

Results must state that:

- prices are estimates,
- risk scores are not guarantees,
- taxes and customs may require professional advice,
- users remain responsible for transaction verification.

## 8. Billing security boundary

- Stripe secret keys and webhook signing secrets belong only in the deployment secret manager.
- Checkout and portal endpoints require an authenticated organization owner and an independent
  billing rate limit.
- The browser submits an internal plan/interval choice, never a trusted Price ID or amount.
- Hosted Checkout keeps payment-card data outside Procura.
- The webhook returns `503` when signing is not configured and rejects an invalid or missing
  `Stripe-Signature` before domain work.
- Browser success/cancel redirects never grant entitlements.
- Provider events are append-only and retain a SHA-256 payload digest, not the raw webhook payload.
- Unknown prices, multiple subscription items, inactive plan versions, and non-entitled provider
  states fail closed to Free.
- Billing operations views exclude provider customer, subscription, price and event identifiers,
  webhook payloads, payload hashes, credentials, and card data.
- Disabling new Checkout must never disable webhook reconciliation for existing subscriptions.

## 9. Security testing

Required:

- cross-tenant authorization tests,
- upload validation tests,
- rate-limit tests,
- subscription bypass tests,
- admin authorization tests,
- signed URL tests,
- privacy request ownership, verification, rate-limit, idempotency, optimistic-concurrency,
  immutable-ledger, blocker, terminal-evidence, localization, and sensitive-field projection tests.
- manual analysis-retry verified-super-admin authorization, current-dispatch concurrency,
  UUID replay/conflict, automatic-retry and run-limit enforcement, quota preservation, immutable
  retry/dispatch evidence, safe Admin projection, and CLI tests.

Sell price-intelligence commands additionally require tenant-scoped owned-product lookup, the
`owned-products.manage` capability, an exact current assessment ID, and composite database foreign
keys that keep organization, owned product, assessment, selection, and price-band evidence in the
same aggregate. Source evidence, selection decisions, and price-band items are append-only at the
model boundary. Raw input is never returned by the API, and no marketplace scraping or external
credential is introduced by the manual connector.

Sell listing-draft commands also require the `owned-products.manage` capability and exact submitted
assessment, price-band, country, currency, and version evidence. Composite foreign keys prevent a
draft from joining an assessment or price band from another owned product. Parent drafts, disclosed
facts, and photo checklist items are immutable at the model boundary. The API never exposes raw
replay input or private file paths.

Listing templates are first-party versioned files, not executable user content or external AI
prompts. Generated copy uses only validated source facts. Proof-of-purchase images remain private
evidence and the photo checklist explicitly warns against publishing them. This boundary performs
no marketplace publication or credentialed external request.

Sale-portfolio mutations require the same tenant-scoped `owned-products.manage` capability. Entry
creation revalidates the complete current listing-draft evidence chain under locks. Event commands
carry a tenant-scoped UUID idempotency key and expected current event ID; stale or changed replay
returns `409`. Marketplace key, external ID, HTTPS URL, reason, note, time, price, and currency are
bounded and validated. Events and entries are immutable at the model boundary, raw replay input is
not returned, and no marketplace credential or outbound request exists in this module.

Outcome mutations also require tenant-scoped `owned-products.manage`. Purchase, cost, and sale
commands carry UUID idempotency and expected immutable heads; stale state and changed replay return
`409`. Realized sale additionally requires the exact current portfolio event under lock. Amounts
are bounded safe integers, currencies must be active, timestamps cannot be future, reasons/notes
are bounded, and cross-currency records fail without an exact dated immutable rate.

The API exposes normalized evidence references and hashes but not raw command snapshots. Actual
purchase, itemized costs, sale outcomes, and profit records reject individual update/delete at the
model boundary. Cancelled/no-sale records cannot contain realized money, asking prices cannot be
promoted to proceeds, and a sold outcome blocks later portfolio events. This module holds no bank
credentials, payment tokens, buyer personal data, escrow state, or marketplace credentials and
performs no outbound request.
