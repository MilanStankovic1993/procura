# 12 — Security and Compliance

## 1. Authorization

Every tenant-owned model must have:

- policy,
- organization scope,
- feature tests for cross-tenant access.

## 2. Uploaded files

Require:

- MIME validation,
- extension validation,
- size limits,
- image dimension limits,
- private storage,
- signed URLs,
- sanitized filenames.

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

## 5. GDPR

Support:

- privacy notice,
- lawful purpose,
- data export,
- account deletion workflow,
- retention policy,
- consent where required,
- processor inventory.

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

## 8. Security testing

Required:

- cross-tenant authorization tests,
- upload validation tests,
- rate-limit tests,
- subscription bypass tests,
- admin authorization tests,
- signed URL tests.
