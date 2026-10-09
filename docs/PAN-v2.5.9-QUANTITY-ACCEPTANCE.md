# PAN 2.5.9 — Purchased Quantity & Variant Acceptance

**Core 2.5.9 · Shopee Connector 2.4.14**. Do not expose buyer IDs, payment data, access tokens, cookies or raw Shopee response JSON in the public repository.

| Case | Expected behavior | Current evidence |
|---|---|---|
| 2 pants variants with missing model_id plus one 3-unit fishing rod | Three distinct item rows, total **5** units; stable variants and raw quantity field provenance | Synthetic Node fixture passes |
| `model_quantity_purchased: 3` but missing `amount`/`quantity` | Purchased quantity 3 (not guessed 1), source field recorded | Synthetic Node fixture passes |
| Two occurrences of same SKU/variant in different item groups | Combined quantity and correct single line; repeated imports idempotent | Synthetic Node + PDO regressions |
| Two distinct variants share item_id and absent model_id | They do not overwrite one another on `(order_id,product_key)` | Node + PDO regressions |
| Conflicting quantities / missing quantity / wrong number of items | **No import** and **no checkpoint advance**; existing rows remain | Synthetic Node + PDO regression |
| Duplicate product key within one incoming order page | Reject entire import transaction; no silent last-write-wins | PDO regression |
| User confirmed 5 but Buyer API shows 2 | No write, explicit mismatch result; do not change order totals based on a guess | Mock Connector regression |
| User confirmed 5 and Buyer API returns complete 5-unit snapshot | Re-import **only that order**, preserve account guard and unrelated records; full/recent checkpoint unchanged | Mock Connector + PDO regression |
| Different Shopee account during refresh | Abort, no PAN database write | Mock Connector regression |
| Existing 2-unit historical Order | **Not automatically backfilled**; updated only after authorized consistent data | Implementation audit |

## LIVE acceptance gate — NOT RUN

1. Back up live database/config and restore a private staging copy first.
2. While signed into the intended authorized Shopee Buyer account, compare **order [REDACTED-ORDER-ID]** in Shopee itself (pants two variants x1; rod x3; expected total 5) against the actual authenticated detail/list response.
3. Try Connector **ตรวจจำนวนสินค้าเฉพาะ Order** with manually confirmed 5 units. If API says 2, stop and record a **sanitized** schema/field summary only. Don't export cookies, names/addresses or raw order body.
4. If API says 5 and targeted import succeeds, verify PAN now shows three separate variants and **5 total pieces**. Check that other orders, totals, earlier item snapshots and the Full/Recent checkpoint remain unchanged.
5. Test accounts with distinct shop/item IDs, return/refund status, optional `product_count` semantics, and API schema changes. Do not force reconciliation on ambiguous shapes.
6. If live Seller/Buyer APIs differ, do not reuse Seller-only field contracts as proof of Buyer API access.

**Warning:** A successful synthetic test does not prove the private Buyer API exposes all products and purchased counts on the real account. When the API cannot establish 5 units, the safe outcome is **no overwrite**, not pretending it has been corrected.
