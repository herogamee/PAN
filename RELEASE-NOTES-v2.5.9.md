# PAN Core 2.5.9 + Shopee Connector 2.4.14 — Quantity / Variant Integrity

## Incident
An Orders expansion can show only two recorded lines / two units even when an authorized buyer expects five (two distinct pants variants, one each, plus three fishing rods). In the original normalizer:

- `quantity = card.amount ?? card.quantity ?? 1` silently forced missing quantities to one. The Buyer order record may instead contain another explicitly named purchased-quantity field such as `model_quantity_purchased`.
- `product_key = shopee:shop:item:model_id` collided when two selected variants shared an item ID but lacked a `model_id`. The database's `UNIQUE(order_id, product_key)` upsert overwrote a previously imported variant instead of preserving each line.

A synthetic fixture reproduces two saved rows / two units from three actual source rows / five units. **This is evidence of a real code defect, not proof that the user's specific Shopee JSON has this exact shape.** PAN has not accessed the user's private database or Shopee session.

## Changes

1. Read positive integer quantities only from explicit purchased-item fields: `amount`, `quantity_purchased`, `model_quantity_purchased`, `purchased_quantity`, `quantity`. Missing, zero, noninteger or conflicting signals cause a **hard stop** with no import and no checkpoint advance — never assume 1.
2. Stable variant-aware product key when model ID is absent: hash the full normalized variant string. If two groups contain the **same** SKU/variant/price, aggregate purchased units. Distinct variants stay distinct. Conflicting duplicate identities fail closed.
3. Compare optional Buyer `info_card.product_count` conservatively: if it exceeds all observed purchased units including returned lines, refuse the incomplete snapshot. Do not assume this source field always means units instead of variant rows.
4. Core import preflight rejects duplicate `(order_no,product_key)`, missing/invalid purchased quantity and oversized keys before any SQL mutation. Entire batch transaction rolls back on failure.
5. Add **single-order refresh** to Chrome Shopee Connector. Operator supplies exact order number and independently checked purchased total (e.g. 5); Connector reads authenticated Buyer Detail first, or searches authorized order-list pages. It will update **only that order** when complete parsed units equal the user-confirmed value and Shopee account identity remains unchanged. It never changes Full/Recent Sync checkpoint. If data disagree, it displays a mismatch and writes nothing.
6. PAN Orders expansion and summary labels now explicitly describe quantities as *stored by PAN*, not a verified Shopee truth. They are not silently corrected until a successful targeted recheck.
7. Sanitize schema error diagnostics: no raw Shopee order JSON, buyer data, cookie or tokens in the exception message.

## No changes to
- Other Order records, order dates, payment/shipping/received-date fields or database schema.
- Existing historical data automatically; there is **no migration guessing that an old 1 must be 3**.
- Shopee login/anti-fraud or API permissions. Failed/blocked sessions remain blocked.

## Acceptance evidence
- Synthetic legacy-normalizer reproduction: 3 source item rows / expected 5 pieces → old DB-style overwrite yields 2 rows / 2 pieces.
- Connector regressions include variant identity, count provenance, same-SKU multi-group aggregation, fail-closed missing/mismatched quantity, safe one-order refresh, account-switch and no-reset checkpoint.
- PDO SQLite/MariaDB tests include original wrong 2-unit record, safe import into 3 lines / 5 units and idempotent replay; reject duplicate SKU/missing quantity before database mutation.
- **Live Shopee acceptance is pending.** Exact order [REDACTED-ORDER-ID] has not been fetched and cannot be claimed fixed until authorized recheck.

## Install and targeted recovery

1. Back up PAN source, active database (SQLite/WAL appropriately) and `storage/config.php` privately.
2. Apply this patch **only from exact PAN 2.5.8** or stage the Complete package separately. Keep runtime `storage/` unchanged.
3. Confirm Core `VERSION=2.5.9`, Connector `manifest.json` version `2.4.14`; reload unpacked Extension in Chrome.
4. Log into your own `shopee.co.th/user/purchase/`, open the Extension's **ตรวจจำนวนสินค้าเฉพาะ Order** section, type order number and the total pieces verified visually in Shopee.
5. Click **ตรวจและอัปเดตเฉพาะออเดอร์นี้**. It will import only when API-derived quantity equals the manually verified count. Refresh PAN Orders after success.
6. If mismatched or Shopee schema is unsupported, do not force Full Sync or hand-edit quantity without evidence. Record sanitized error type/counts and compare Shopee order page/authorized API for an adapter update.

See [acceptance matrix](docs/PAN-v2.5.9-QUANTITY-ACCEPTANCE.md). Regression success does not equal production account acceptance.
