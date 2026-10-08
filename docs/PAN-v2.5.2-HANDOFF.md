# PAN 2.5.2 / Shopee Connector 2.4.9 — Engineering handoff

> Read [ROADMAP.md](../ROADMAP.md), [Acceptance Matrix](ACCEPTANCE-MATRIX.md) and [Release notes](../RELEASE-NOTES-v2.5.2.md).

## What actually changed

- New `app/login_throttle.php` used by `login.php`. It locks counters across PHP workers, hashes counter filenames, and rejects login safely when locked/unavailable. POST login requires session CSRF; success regenerates the session ID as before.
- `app/db.php::pan_validate_import_batch` now runs inside import transaction before mutations. Records with inconsistent source accounts / invalid schema and existing order-account mismatch hard fail, preserving the entire import page. This is in addition to checks already in the Shopee extension.
- `tests/login-throttle.php`, `tests/db-integration.php`, and `.github/workflows/ci.yml` add security and real PDO SQLite+MariaDB regression tests.
- Core only: `VERSION`, UI version references, installer/API/export return version `2.5.2`; Chrome Extension manifest still `2.4.9`.

## Mandatory release-gate evidence

- GitHub CI successful on **exact v2.5.2 commit** for PHP lint, login throttle, DB integration, extension and server connector tests.
- MySQL test uses isolated database `pan_ci_test`, not PAN's production config. SQLite uses disposable temp file. CI compares item/order ownership, rollback and migrated counts.
- Manual XAMPP3 and Ubuntu staging acceptance still required for real account / real data and restoring backups.
- Live Shopee payment/shipping/tracking/Repair data mapping **not verified** by synthetic tests.

## Deployment concerns

- `storage/auth-throttle/` must be writable by PHP and inaccessible through web server; no migrations required. With read-only storage login deliberately fails as HTTP 503.
- Behind proxies, `REMOTE_ADDR` is shared by edge/NAT clients; the limiter does not accept spoofable forwarded headers. Confirm the deployment's trusted proxy topology and test more than one real client before stable promotion.
- Never commit session profiles, cookies, OTP, database, API keys, raw order response or buyer PII to the public repository.
- If existing login page was open before upgrade, reload it for the new hidden CSRF token.
- If a strict import-validation error occurs, do not force checkpoint advance: examine sanitized schema fixture and correct the adapter before retrying.

## Explicitly still open

- Phase 1: live Shopee account mapping, SQLite/MySQL tests on the target deployment with real historical data, backup/restore rehearsal.
- Phase 2: payment/shipping/tracking accuracy and unavailable/missing-field semantics.
- Phase 7: monitor/alerts, log redaction, trusted-proxy policy and holistic security audit.
