# PAN 2.5.6 — Buyer Delivery Timestamp Retired / Acceptance Matrix

| Location | Behavior required | Acceptance evidence |
|---|---|---|
| Orders | No **วันที่ได้รับพัสดุ** column or guessed time | `tests/order-ui.php` + visual staging confirmation |
| Orders | **วันที่สั่งซื้อ** remains, with date-only precision preserved | `tests/order-timeline.php`, staging account verification |
| Analytics | Date sources restricted to order-created timestamp, date-only fallback or unknown; no `delivered_at`/`completed_at` classification | `app/analytics.php` + synthetic DB fixture |
| Detail | Successful API response without delivery timestamp does not set partial | `tests/db-integration.php`, Extension fixture |
| Repair | Legacy partial markers `delivered_at`/`payment_method`/`shipping_carrier` alone or together are not a re-fetch reason; failures/pending remain | `tests/carrier-repair.php` + synthetic SQLite/MariaDB |
| Raw data | No DB migration or deletion of historic delivery fields; blank re-fetch does not erase existing raw value | synthetic PDO test; private staging DB backup verification |
| Shopee | Actual order status retained independently, not proof of courier delivery | schema / API review; authorized live buyer test still pending |

## API findings

Current PAN Connector makes authenticated requests to the **Buyer Web API** `.../api/v4/order/get_order_detail`. The code was attempting to extract common delivered-like field names. Those names appear in synthetic fixtures, **not a verified real contract** for these logged-in Buyer responses. There is no basis to tell users the courier took the parcel to their home at that time, and no legitimate reason for the absence of such a field to trigger repeated Repair. We do not claim that Shopee never offers shipment tracking; we only establish that **PAN has not demonstrated a reliable delivery timestamp from its current Buyer API path**.

## Data preservation and recovery

- Store a private backup of production source, `storage/config.php`, SQLite WAL/DB or MySQL snapshots **before overlay**.
- Patch v2.5.5 → v2.5.6 only on the exact v2.5.5 baseline; update extension to v2.4.13 and check API status versions.
- Data stores continue to contain `delivered_at`, `delivery_date_source` and other historical fields. Do not treat these as verified.
- `pan_repair_required_sql` is shared by both `/api/status.php` pending counts and the queue; receipt-only missing fields are optional. Manual advanced `all=true` still allows intentional rechecks.
- Rollback uses the saved complete source plus the private database backup, not a mixed patch of selected files.

## Still pending for Production

- [ ] Authorized Shopee session, check detail fields (redact raw buyer/order/cookie/OTP)
- [ ] Staging SQLite/MySQL migration/backup restore with historical data
- [ ] Confirm web UI and account-scoped queue counts on real installation

Please update [Phase 2](https://github.com/herogamee/PAN/issues/2) and the repository Acceptance Matrix only on observed evidence.
