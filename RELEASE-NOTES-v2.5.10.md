# PAN Core v2.5.10 — User-Attested Quantity Safety (Shopee Connector v2.4.14)

## Scope
- The owner can repair the quantity of one historical order with an offline **private** backup and a scope-limited script; this source release does **not** publish or embed any user data, order number, secret, session, or real database.
- In the Orders expanded panel, show a clear note when any `order_items.import_source = pan_user_attested_quantity`: quantity is owner-confirmed, while variant detail and money allocation may be unverified. Do NOT display old unit prices and line totals for such provisional rows.
- Full/Recent Sync with a stale 2-piece snapshot must fail closed if it would overwrite an attested 5-piece order. Only a `single_order_recheck` with matching total, all previously known SKU quantities and replacement verified variant lines may overwrite the provisional rows.
- Historical `orders.total_paid` is **not** recomputed or changed by manual confirmation. Existing raw order lines are preserved for audit where possible.
- Core only changes in this release; Chrome Connector remains 2.4.14. No schema migration.

## Verification
- PHP syntax and PHP template regressions.
- Disposable SQLite/MariaDB integration tests must verify the rejection of stale Full/Recent Sync and safe targeted complete snapshot replacement.
- User-specific quantity correction is not proof of the private Shopee API response. Only the user can confirm the missing option and payment breakdown.

## Safe deployment
1. Back up PAN files, live database, SQLite WAL and `storage/config.php` before changing files; do NOT upload these to GitHub.
2. Update from exact Core 2.5.9 source to 2.5.10. The Chrome Connector does not change.
3. Apply user-private correction **only after** a dry-run on the intended database and a consistent backup. The private correction is not included in the public Complete source release.
4. For the affected Order, confirm 3 saved lines / 5 owner-confirmed pieces, distinct variants (one placeholder pending), and no invented per-unit price. Avoid Full Sync until the official Buyer snapshot is verified.
5. Any failed API read or missing option keeps manual data in place; errors must not advance checkpoint silently.
