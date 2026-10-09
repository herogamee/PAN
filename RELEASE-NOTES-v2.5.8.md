# PAN v2.5.8 — Expandable Order Products

Core **2.5.8**; Shopee Connector **2.4.13 unchanged**. Exact base **PAN v2.5.7**.

## Feature

- On `?page=orders`, click an Order number or any non-link portion of its row to expand a panel **within the same table**. Click again to collapse. The expand button supports keyboard users through native button activation, `aria-expanded`, and `aria-controls`.
- All product lines already saved for that Order are displayed: thumbnail when present, product title, variant, quantity, unit price and line amount. Product links open safely in a separate tab; clicking shop links continues to filter by shop as before.
- If PAN has no stored products for an Order, the panel explicitly says so rather than pretending it contained no purchased goods. Item-line amounts can differ from order payment totals owing to vouchers and shipping.
- Item queries are performed **once per paginated Orders page** using only the Orders IDs already filtered and shown (no N+1, no new API, no changes to Shopee sessions). Up to 200 Orders per page, without extra writes.
- Works on legacy/stale/undated Orders as long as their order_items are present. No data migrations, import, sync or mutation changes.
- Mobile layout stacks quantity and prices. Image/link URL schemes are checked; product names/variants/URLs are HTML-escaped and external links use `noopener noreferrer`.

## Verification

- PHP lint all files, `php tests/order-ui.php`, `node --test tests/orders-browser.test.mjs`.
- GitHub Actions runs synthetic PDO SQLite/MariaDB fixtures to check two product lines under one Order, account/Order page isolation, empty Order fallback, variant grouping, line prices and safe external URLs.
- **Production database / Shopee live-session verification remains pending**; a saved Order item not present in PAN cannot be reconstructed by this UI.

## Upgrade

Back up application files, `storage/config.php` and DB securely. Apply the patch only to exact PAN v2.5.7 source or stage the Complete archive separately. Do not overwrite/remove runtime `storage/` and do not resync as a prerequisite. Confirm `VERSION` = 2.5.8; Shopee Extension remains v2.4.13.
