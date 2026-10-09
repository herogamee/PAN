# PAN 2.5.7 — Order Visibility Incident & Date Acceptance

## Confirmed in source (NOT verified on user's production data)

- Old `reconcile_account_scan` set `validation_state='not_seen_full_scan'`, while Dashboard and account purchase count only considered `verified_*`; rows stayed on disk but disappeared in these views.
- Previous strict `pan_order_placed_sql` hid date-related events (paid, dispatch, tracking, Complete) to avoid labelling them as purchase dates. The year/month filter needs to keep this strict truthfulness, but the UI must provide an independent way to find undated records.
- Existing database schema is unchanged; this patch must not delete or modify old order rows.

## User-visible contract

| Case | Expected |
|---|---|
| `verified_v200` order with `order_created_at=2026-10-09 14:18:11` | `09/10/2569 14:18`, 24-hour Thai time |
| `order_date=2026-10-09`, no actual timestamp | `09/10/2569` + “Shopee ไม่ระบุเวลา” |
| timestamp `2026-10-09T23:30:00Z` | `10/10/2569 06:30` (Bangkok) |
| prior `not_seen_full_scan` purchase + known account | Visible under historical-not-seen list; distinguish from verified scan |
| `date_source=shipping.tracking_info.ctime`, no real order-created timestamp | “ไม่ทราบวันที่สั่งซื้อ”; visible under **undated** / **PAN found this month**, but **NOT** counted in true October purchase-date charts |
| Purchase of October with `list_type=7/8` | Show in monthly purchase report if purchase date is known (not only Complete status 3) |
| Full Sync does not rediscover an existing order | Do not mutate `validation_state`, do not remove a row, return mismatch count |
| suspicious legacy row or cross-account order | Do not automatically become verified; enforce existing account isolation |

## Evidence gates

- [x] Source-only PHP syntax + purchase date UI test fixtures
- [x] In-memory synthetic SQLite visibility/date filter regression test
- [ ] CI PDO SQLite and MariaDB integration on exact source commit
- [ ] Production-like staging copy of existing PAN database: compare counts, September/October rows, `validation_state`, `last_scan_id`, `created_at`, `last_seen_at`
- [ ] Authorized real Shopee account: compare actual purchase dates and status rows; do not invent timestamps
- [ ] Backup and restore rehearsal with exact version before production promotion

## Safe read-only diagnosis on an authorized PRIVATE copy of the PAN database

Before and after upgrade, obtain **counts only** (no order IDs/buyer details in public GitHub):

```sql
SELECT validation_state, purchase_state, list_type, COUNT(*) AS rows_count
FROM orders GROUP BY validation_state, purchase_state, list_type
ORDER BY rows_count DESC;

SELECT substr(order_date,1,7) AS stored_order_month,
       date_source, COUNT(*) AS rows_count
FROM orders
WHERE order_date LIKE '2026-10-%'
GROUP BY substr(order_date,1,7), date_source;

SELECT COUNT(*) AS old_not_seen_full_scan
FROM orders WHERE validation_state='not_seen_full_scan';

SELECT COUNT(*) AS records_seen_by_pan_in_october
FROM orders WHERE last_seen_at LIKE '2026-10-%';
```

`last_seen_at` is a PAN observation timestamp, not proof of October purchase. **Do not run UPDATE/DELETE or reset the database based on these results**. Raw data and account/session state must remain private.
