# PAN Core v2.5.11 + Shopee Connector v2.4.15 — Buyer Item Detail First

## Why this is a code fix, not another manual order correction

The buyer's legitimate Shopee order page may present **four purchased item rows** with quantities `1+1+2+1` (five units) while PAN previously saved an abbreviated two-row preview. A data-only/manual patch cannot prevent future imports from repeating that loss. This release repairs the **import pipeline**.

## Source behavior

1. Full Sync and Recent Sync read authenticated Shopee **Buyer Order Detail** before posting each order's items to PAN. They do not need a typed expected piece count.
2. Buyer item arrays supported when present: structured `info_card.order_list_cards[*].product_info.item_groups[*].items`, `data.item_list`, `data.product_info.item_groups` and `data.package_list[*].item_list`.
3. Four purchased rows remain **four database rows**, even if two rows have the same item ID, model, name, variant and price. No speculative merging by SKU; stable source row path is retained in synthetic raw metadata and order-item identity.
4. Purchased quantity is read only from explicit positive-integer fields (`amount`, `model_quantity_purchased`, etc.). A missing/conflicting quantity or inconsistent detail item representations fail before mutation/checkpoint movement.
5. Each saved row records `item_snapshot_source` provenance. PHP transaction guards prevent an abbreviated list preview from silently overwriting a previously verified Buyer Detail; a lower row/unit-count detail cannot regress an existing verified order.
6. Existing user-attested corrections may be replaced automatically ONLY when a complete Buyer Detail candidate has at least as many source lines and exactly the same total quantity. An incomplete detail preserves them.
7. A Buyer Detail unit price is shown as the per-item source price; PAN does not misrepresent proportional allocation of order-level vouchers/coins as verified item pricing. Final order amount remains separately recorded.
8. One-order recheck still accepts the order number as a lookup target, but **does not request or use a manually supplied expected quantity**. If the Buyer Detail is metadata-only, it writes nothing and displays the coverage limitation.
9. Existing data/schema, cancellation behavior, account isolation, versioned Connector and SQLite→MySQL support are preserved. This update does not automatically alter installed live databases or historical rows until the new authenticated sync runs.

## Verification and blockers

- Node synthetic tests include **4 distinct line rows x(1,1,2,1) = 5 units**, repeated variants, independent `item_list` detail source, incomplete list preview repaired before import, metadata-only no-write, account-switch and API-conflict rejection.
- PHP lint and UI tests cover source labels and safe display. Disposable SQLite/MariaDB CI asserts 2 old rows/2 units can be replaced with 4 rows/5 units transactionally; a low-confidence snapshot cannot overwrite verified detail, and a later incomplete snapshot is rejected.
- **Production live acceptance NOT RUN**: no access to the user's Shopee session or raw permitted Buyer API JSON. Field availability is private/session-dependent and may differ from synthetic fixtures. No claim that the buyer's specific order is already repaired.
- If Buyer Detail only returns metadata or a truncated array, do not force a rewrite, re-label it as complete or bypass Shopee authentication/anti-fraud. Collect only sanitized field-name and count diagnostics, not buyer/order raw JSON in a public repository.

## Safe upgrade from v2.5.10

1. Back up current PAN code, `storage/config.php`, active DB and (if SQLite) WAL consistently, without uploading credentials to GitHub.
2. Apply the exact-base v2.5.10→v2.5.11 Source Patch, or stage Complete separately. Do not overwrite live `storage/`.
3. Confirm `VERSION = 2.5.11` and Chrome Extension `manifest.version = 2.4.15`. Reload the unpacked Extension.
4. Test one order through the **read-only detail-first** stage and then use one-order recheck (number only) after confirming account identity, before starting a broad Full Sync.
5. For broad refresh, use Recent/Full Sync only after checking the account and backups. Observe `Item Detail ยืนยันได้` and `รายการย่อ/รอตรวจเพิ่ม` separately. An unresolved source must not be recorded as verified.
6. Compare real buyer order rows/quantity, saved DB rows and totals on a staging copy. Only after private live validation can Issue #8 be closed.

This package contains no private buyer database, cookies, OTP or credentials.
