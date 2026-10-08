# PAN 2.5.1 / Shopee Connector 2.4.9 — handoff

## Source of truth
This repository contains the deployable *source* of PAN, beginning with the verified PAN 2.5.0 + Shopee Connector 2.4.8 Complete package (SHA256 `e322e95bcdfed1f6879c0286f5bb88e535014a7db6a02070b8d07efd0a4a1b61`) and the v2.5.1 maintenance changes described below. No live `storage/config.php`, database files, cookies, credentials or `node_modules` are committed.

## v2.5.1 / 2.4.9 changes
- Fix cancellation API's undefined `$batchAccountId` after DELETE (use the requested account ID for PAN purchase counter).
- Reject cross-account detail/cancellation updates before mutating an existing order with a different nonempty account ID.
- Product-category queue now uses a **stable, exclusive `(shop_id,item_id)` cursor** instead of rereading the first changing batch. Failure of one product no longer starves later products; failures remain pending for future manual retries. Queue is ordered lexically by (shop_id,item_id) and is account-scoped.
- CI workflow and regression tests added for product cursor, partial Shopee category failures and existing extension flow.

## Deployment
1. Back up all application files, `storage/config.php`, DB and extensions from the current server.
2. Copy application source into the existing PAN directory (or deploy a separate staged directory), **do not delete/replace** the runtime `storage/` contents.
3. Load/reload unpacked `connectors/shopee-extension/` extension v2.4.9 in Chrome.
4. Run PHP lint and Node regression tests; confirm `api/status.php` returns Core 2.5.1 and Connector 2.4.9.
5. With a logged-in Shopee TH account, run one Recent Sync, one cancel test on a known cancellable order (after backup), one detail Repair and one category enrichment sample; verify actual purchase counts against PAN.

## Verified / not verified
- Static PHP lint and extension Node regression tests: see `docs/LOCAL-VALIDATION-v2.5.1.txt`.
- Live Shopee private web API and logged-in sessions: **not tested in development environment**; API endpoint availability/schema and session behavior must be checked on deployment.
- Real PHP PDO SQLite/MySQL transactions: **not tested in development environment** because PDO database drivers are unavailable. Test on XAMPP/server before production promotion.
- Server connector's real Chromium persistence test requires Playwright Chromium; CI installs it, development environment may not have it.

## Remaining work (do not silently call done)
1. End-to-end browser test with logged-in Shopee session and accurate pay/shipping/tracking fields.
2. Database integration test on both SQLite and MySQL for mixed-account import, item snapshots, cancelled orders, Repair paging and category queue cursor.
3. Payment, shipment and delivery fields: verify API data contract against live responses and show unknown values rather than fabricated labels.
4. Product matching beyond normalized identical names; manual merge/split before loose grouping.
5. Formal multi-marketplace unique identity migration before adding Lazada/TikTok Shop (today `orders.order_no` remains globally UNIQUE).
6. Production-first authentication rate limiting and monitoring plus backup/restore rehearsal.
