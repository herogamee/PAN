# PAN 2.5.0 + Shopee Connector 2.4.8 — Release Validation

Date: 2026-10-06

## Identity

- PAN core: **2.5.0**
- Shopee Connector: **2.4.8**
- Product: PAN — น้องแพน
- Brand: itoom.work

## Implemented gates

### Data integrity
- Full Sync per-page account guard
- Mixed structural schema page hard-stop before import/checkpoint advance
- Reconcile failure propagates and prevents false completed state
- Order-item snapshot reconciliation
- Account-scoped PAN purchase counters

### Detail enrichment
- `detail_state`: pending / complete / partial / error
- broader nested payment/shipping/tracking/time extraction
- Recent Sync auto-detail for new/status-changed/pending/error orders
- paged missing-detail queue + advanced all-detail action
- UI distinguishes waiting / Shopee omitted / error / complete

### Product Explorer / Category
- Product Explorer KPIs, filters and sorts
- marketplace shop/item/model/category fields
- deterministic Product Family foundation
- separate product/category enrichment queue and API
- category enrichment failure does not block Order Sync

### Hardening
- public First Run `PAN_SETUP_TOKEN`
- SQLite→MySQL final row-count verification before commit
- SQLite backup WAL checkpoint busy check
- streamed all-json export
- dashboard/analytics date priority alignment

## Automated validation

- PHP syntax: **PASS** (all project PHP files)
- `background.js`: **PASS** (`node --check`)
- `popup.js`: **PASS** (`node --check`)
- `manifest.json`: **PASS** JSON parse
- Shopee Connector regression suite: **28 PASS / 0 FAIL**
  - includes mixed-schema hard stop
  - nested payment/logistics parser
  - category breadcrumb parser
  - Full Sync per-page account guard
- Product Explorer aggregate/correlated-price query: **PASS** in SQLite in-memory SQL sanity test

## Runtime limitations of build environment

This build environment has PDO core but does **not** have `pdo_sqlite` or `pdo_mysql`. Therefore a real PHP PDO import/migration transaction was not executed here. Product Explorer SQL was sanity-tested with Python SQLite, and PHP/JS/schema paths were syntax/regression validated.

Shopee buyer/private web endpoints are session-dependent and do not provide a stable public contract. Live Order Detail and Product/Category enrichment must be verified once after deployment using the user's logged-in Shopee session. Category enrichment is intentionally non-blocking so failure cannot make Order Sync fail.

## Release rule

Do not include live `storage/config.php`, SQLite/DB runtime files, credentials, cookies, passwords or OTP in Complete/Patch packages.
