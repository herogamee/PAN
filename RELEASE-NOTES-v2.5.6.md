# PAN Core v2.5.6 + Shopee Connector v2.4.13 — Retire Unverified Delivery Date

## Decision and evidence
PAN uses Shopee's **Buyer Web API** (`/api/v4/order/get_order_detail` via the user's authenticated browser), not the seller Open Platform API. The collector probes fields such as `delivered_time`, but those are best-effort structural candidates. **No authorized live Buyer response has established a reliable courier-to-recipient delivery-time contract.** Unit fixtures passing do not mean this field is available in reality. In accordance with the user's request, retire its user-facing feature rather than displaying guesses or making users Repair endlessly.

## Changes
1. Remove the **วันที่ได้รับพัสดุ** column and delivery-date message from Orders, preserving **วันที่สั่งซื้อ** independently.
2. Stop reporting raw `delivered_at` or `completed_at` as delivery-related date source counts in Analytics. The remaining date-source breakdown only concerns order creation.
3. No courier-delivery timestamp is required for a successful Order Detail inspection; formerly `partial` orders that only lack `delivered_at` and/or other retired Buyer payment/carrier labels are excluded from automatic/manual missing-detail queues and account-level pending counts. A failed fetch or truly uninspected order remains retryable.
4. Rename the successful Detail UI chip **✓ ตรวจ Detail แล้ว** instead of **Detail ครบ**, to distinguish successful Detail retrieval from completeness of an undocumented Buyer field.
5. Preserve historical raw columns (`delivered_at`, `delivery_date_source`, `completed_at`, `shipping_carrier`, `payment_method`, `tracking_number`, metadata) and any nonempty raw candidate. No destructive data update or schema migration.
6. Continue tracking real Order status from `list_type`, but do not infer a courier-delivery timestamp from `Complete`, buyer confirmation or seller dispatch.

## Validation
- PHP lint and static UI/provenance regressions
- Shopee extension test suite with missing buyer delivered-time fixture
- Synthetic disposable SQLite / MySQL/MariaDB integration through GitHub Actions, checking pending counters, historic records, failed Detail handling and order-account separation
- Complete ZIP / exact v2.5.5 patch overlay SHA parity and safe path omissions

## Not verified
- Actual Shopee Buyer API's shipment event availability and semantics on the user's account: NOT TESTED
- The user's real production historical DB and backup/restore rehearsal: NOT TESTED
- Live installation / rollout: NOT DEPLOYED

**Do not reintroduce this field** before an authorized, anonymized live Buyer API example proves the courier-received event. Seller Open Platform shipping data does not establish this.
