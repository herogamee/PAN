# PAN v2.5.8 — Order Row Expansion Acceptance

Core version: 2.5.8. Shopee Connector: **unchanged 2.4.13**. Target exact base: 2.5.7.

## Definition of Done

- [x] Orders page has an accessible toggle on every Order number, and row clicking works without leaving `?page=orders`.
- [x] Expanding a row renders **all order_items stored for the selected order**, with image (if a safe URL exists), name, option/variant, quantity, item unit amount and line amount.
- [x] Clicking the same Order again hides its products; multiple Orders can remain open.
- [x] Existing shop name links navigate normally rather than toggling; product links are escaped and use `noopener noreferrer`.
- [x] The page fetches rows for **only the filtered/paginated order IDs**, using a single batched read-only SQL query rather than one query per Order.
- [x] Empty stored line list explicitly says PAN does not have product details, not that the Shopee order was empty.
- [x] Original Orders columns, filters, paged totals, order status, Thai Buddhist date formatting and historical visibility rules are unchanged.
- [x] PHP/JS regression coverage added. Integration fixtures validate multiple items per Order, deduplicated ID grouping and cross-Order isolation on disposable SQLite + MariaDB.
- [x] No database schema migration, no Order reimport or resync, no API endpoint and no additional Shopee session required.

## Still to verify on a real installation

- [ ] On a **private staging copy** of the user's database, check three Orders: multiple products, one product, and one without stored `order_items`.
- [ ] Check thumbnails, variants, quantity/amounts against the original authorized account.
- [ ] Test 10/50/200 Orders per page and responsive mobile view with existing filters.
- [ ] Back up and rehearse rollback before deploying. Note that item totals can differ from Order-level `total_paid` due to shipping/vouchers and other discounts.

**Data protection:** Do not paste real buyer/order IDs, invoices, cookies, profile data, API keys or database dumps into public issues. This feature reads only existing PAN data and cannot reconstruct lines never ingested.
