# PAN v2.5.1 + Shopee Connector v2.4.9 — Queue & Account Safety

2026-10-08 · maintenance release built from exact PAN 2.5.0 + Connector 2.4.8 source.

## Resolved
- Cancellation endpoint: after account-scoped deletion, counts use the correct `$accountId` (previous code referenced undefined `$batchAccountId`, risking a success-then-error response and repeated retries).
- Category queue: stable **keyset** pagination over `(last_order_id DESC, shop_id ASC, item_id ASC)`. Failed category lookups do not permanently block later products; successful lookups can disappear without making later pages skip records.
- Connector: category run takes each product once per pass; resume by next cursor, reject looping/invalid cursors, halt when account changes or Shopee denies session instead of silently hammering endpoints.
- Detail enrichment: reject attempts to update another account's existing order; prevent cross-account metadata pollution.
- Repo hygiene: `.gitignore` excludes node_modules, live database/config, browser sessions and secrets; source remains reinstallable with `npm ci`.

## Kept from v2.5.0
- Safe full/recent sync; account guard; snapshot reconciliation; cancel/reconcile boundaries.
- Four-state detail coverage, automatic recent-detail enrichment, page-wise repair queue.
- Product Explorer filtering and sorting; category enrichment remains separate from Order Sync.

## Validation/limitations
- Run `node --test connectors/shopee-extension/test/*.test.mjs` and `php -l` across PHP source files.
- Real SQLite/MySQL integration needs `pdo_sqlite` / `pdo_mysql` on deployment; Shopee private endpoints require logged-in user session for live verification.
- Do not copy production `storage/config.php`, `pan.sqlite` or connector session profiles into GitHub.
