# PAN v2.5.2 — Account Isolation & Login Security (candidate)

Released source candidate: 2026-10-08  · Shopee Connector remains **v2.4.9** unchanged.

## Functional changes

1. **Import API hardening:** PAN validates every item in an incoming page before the first upsert; inconsistent account IDs, invalid/unknown records, unsupported types, non-Shopee platform, and existing order account ownership conflict now cause the entire import call to fail. Reconcile and page checkpoint must not advance on an import failure. Database validation runs inside the import transaction; MySQL locks pre-existing order rows during checks.
2. **Login rate limiting:** persistent file-backed PHP worker-safe throttle using locked HMAC-keyed files inside `storage/auth-throttle/` (ignored by Git); no raw IP/user/password stored in counters. Five failures per username/IP or twenty per IP per 15-minute period block login for 15 minutes. Blocked attempt returns HTTP 429 + Retry-After. Storage corruption/permission failures refuse the login instead of bypassing the throttle.
3. **Login CSRF protection:** the Login form requires the existing session CSRF token; invalid/missing token is HTTP 419 with refresh guidance. No credentials are checked in this case.
4. **Regression and database testing:** PHP login-throttle tests and disposable PDO SQLite + MariaDB transaction integration checks added to GitHub Actions. Integration covers idempotent upsert, item snapshot replacement, mixed-account fail-closed import, cross-account Detail/cancellation, account-scoped reconcile, queued Repair, injected mid-batch failure rollback, and SQLite→MySQL migration.
5. PAN Core identity updated from 2.5.1 to 2.5.2 across runtime APIs/UI/installer/export and `VERSION`. No database schema change; no Chrome Extension changes.

## Security/compatibility notes

- The limiter uses `REMOTE_ADDR` only; it intentionally **does not trust request-controlled X-Forwarded-For or CF-Connecting-IP**. For reverse-proxy deployments, trusted edge-IP configuration and shared-IP operational tests are still needed before production use (Phase 7).
- `storage/` must be writable by the PHP service account and blocked from public HTTP access (`storage/.htaccess` under Apache; explicit Nginx deny rule). Login will fail closed if throttle storage is unwritable.
- Reload the login page once after upgrade so the new CSRF input is included. First Run still uses the normal installation login path after correctly setting the account.
- Existing Shopee Extension v2.4.9 sends normalized consistent records, so the strict import guard is designed for the existing connector. An unknown marketplace/source or malformed direct API request is rejected rather than partially imported.
- Public GitHub CI tests use **synthetic** data and **ephemeral MariaDB service credentials**. Never run the test runner against a production database. The runner additionally requires `PAN_CI_TEST=1` and exact disposable DB name `pan_ci_test` for MySQL.
- No local PHP PDO database drivers are present in the artifact-building environment; the actual SQLite/MariaDB PHP transaction results must be verified from CI. All live Shopee session/payment/logistics acceptance remains **pending** in Phase 1/2.

## Deployment/rollback

1. Backup source, `storage/config.php`, database (including SQLite WAL as appropriate), Extension profile and configuration using secure private storage.
2. Apply complete source to isolated **staging** or patch from the **exact PAN v2.5.1** source; keep runtime `storage/` data. Do not overwrite `storage/config.php` or DB.
3. Ensure service account can create `storage/auth-throttle/` and no web user can browse it. Reload Login page and verify valid login, failed-login counter and lockout/retry.
4. Run new PDO tests with isolated synthetic DB and verify local authenticated Shopee user flows only in staging.
5. Rollback application code to v2.5.1 if needed; the new throttle directory may remain and can be pruned privately. No database migration is required solely for this release.

**Do not call this production-accepted until Issue #1 and #7 gates pass.**
