# PAN Core v2.5.4 + Shopee Connector v2.4.11

## Orders: purchase date vs courier delivery date — semantic integrity

**Reason:** PAN v2.5.3 mixed dates from different events. Order date could come from pay/shipping activity when Shopee did not expose order creation. The Orders column **ส่งสำเร็จ / Complete** fell back from courier `delivered_at` to Shopee's `completed_at` (the order closing/confirmation event). These events do **not** necessarily occur on the same day.

### Changed

1. **Removed payment-method presentation:** Orders payment column, payment filter and Analytics payment chart. Historical `orders.payment_method` values remain untouched and no guessed mappings are used for KPIs. Other order/amount columns are preserved.
2. **วันที่สั่งซื้อ:** use only order-creation event (`info_card.create_time`, `info_card.order_create_time`, `order_create_time`, `create_time`, `pc_processing_info.create_time`). The connector now stores the real Bangkok date **and time** from Unix timestamps in `order_created_at` during list sync, not only the date. If the source contains a date-only string, show date-only with “Shopee ไม่ระบุเวลา”; never manufacture 00:00/07:00. `pay_time`, shipping tracking `ctime` and `completed_at` are not order-creation fallbacks.
3. **วันที่ได้รับพัสดุ:** show a courier-delivery timestamp **only** if `delivery_date_source` identifies an explicit delivered event: `delivered_time`, `actual_delivery_time`, `delivery_completed_time`, `courier_delivered_at`, `carrier_delivered_at`, `parcel_delivered_at` outside estimated/planned contexts. Do not use seller handover, hub reception, generic `delivery_time`, buyer confirmation (`buyer_received_time`), or Shopee's `completed_at`. For older ambiguous `delivered_at`, show “ยังไม่ยืนยันวันรับพัสดุ” and retain stored raw data.
4. **Safe legacy display:** if historical `date_source` indicates a payment/shipping/completion fallback, show “ไม่ทราบวันที่สั่งซื้อ” rather than presenting that old date as an order-created date. No destructive data rewrite.
5. **Consistent computations:** Orders filtering/sorting, recent-sync anchor, dashboard order-date chart, shop/product purchase-history sorting and Analytics purchase-period calculations no longer substitute shipping/completion dates for purchase dates.
6. **Repair semantics:** payment codes and missing `completed_at` no longer force incomplete **delivery** detail; completed orders with absent/ambiguous courier-delivery evidence are partial. Manual Repair may re-query such orders; no bulk silent background retry and no guarantee Shopee exposes the field.
7. **Tests:** PHP timeline/UI unit tests, Shopee Connector synthetic fixtures, SQLite/MariaDB real PDO integration tests (CI) for date provenance, repair state and missing fields.

### Live-platform limitation

PAN currently uses Shopee buyer-session private API `/api/v4/order/get_order_detail`. There is **no validated public guarantee** that this API returns an actual courier delivered timestamp for all orders. A blank delivery date is the correct result when no trustworthy delivery event is present. To confirm real behavior, compare a sanitized logged-in Shopee order against the value on Shopee's tracking details. **Do not guess missing delivery timestamps from Complete.**

### Compatibility / upgrade

- No schema migration; all original orders, items, payments, source timestamps, keys and DB records retained.
- Preserve and back up `storage/config.php`, SQLite WAL/DB or MySQL and user-owned files. Never overwrite runtime storage while upgrading.
- Reload unpacked Chrome Extension from `connectors/shopee-extension/` (manifest v2.4.11), then perform an authorized **manual** Repair/Recent Sync for dates you want to update. Existing records with only date precision will not magically gain time unless Shopee supplies it.
- No production deployment was performed here. CI synthetic validation is not live Shopee acceptance.
