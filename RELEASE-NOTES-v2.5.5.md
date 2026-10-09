# PAN Core v2.5.5 + Shopee Connector v2.4.12 — Carrier Display Retired

## Decision

PAN is a **buyer-side purchase-history collector** and currently calls Shopee's
session-based `/api/v4/order/get_order_detail`. There is no validated contract
showing it supplies a readable shipping-carrier name consistently for this use.
Documented seller-side Shopee Open Platform v2 APIs expose `shipping_carrier`,
but **those seller privileges and endpoints are not PAN's buyer access**.
Do not conflate an empty buyer response with an API that can reliably report the
shipping provider. Production buyer-session verification remains outstanding.

## Changes

1. **Removed from UI:** Orders `ขนส่ง` table column, `บริษัทขนส่ง` filter,
   carrier charts and carrier breakdown from Analytics. Removed carrier query
   and carrier-derived Analytics payload. Reflowed weekday analytics chart into
   the existing 3-column row and restored a 2-column breakdown layout.
2. **Historical data is not erased:** `orders.shipping_carrier`, timestamps,
   tracking, order snapshots, source metadata and database schema remain.
   An empty response does not overwrite a nonempty historic raw carrier value.
3. **Stop false Repair requests:** Missing carrier name is no longer a required
   detail field in the Connector or in the Core enrichment state. Legacy partial
   rows missing only carrier and/or payment method no longer inflate Repair
   queue or account pending counters. Actual courier-delivered evidence remains
   mandatory for completed orders when it is not provided.
4. **Maintained provenance:** Shopee buyer API detail parser can still retain
   raw carrier candidates/metadata for future research and retains tracking
   number/courier-delivered event extraction independently. The v2.5.4 meaning
   of `วันที่สั่งซื้อ` and `วันที่ได้รับพัสดุ` is unchanged.
5. **Tests:** New JS detail-meta carrier-absent fixture, PHP Orders/Analytics
   source UI assertions, disposable SQLite/MySQL integration for missing carrier,
   old carrier-only partial flags, and count/queue consistency.

## Compatibility and limitations

- Core: **v2.5.5**, Shopee Chrome Extension: **v2.4.12**. No schema changes.
- Excluded carrier from the *analytics output contract* as it was not verified;
  raw DB data and JSON order exports (if explicitly requested) remain unchanged.
- No live Shopee user session, private buyer API response, real historical
  database or production upgrade was tested in the source editing environment.
  Synthetic regression alone does **not** prove API access or carrier accuracy.
- Read `INSTALL-v2.5.5.txt` and
  `docs/PAN-v2.5.5-SHIPPING-ACCEPTANCE.md` before release acceptance.
