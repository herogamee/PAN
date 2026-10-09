# PAN v2.5.4 — Date & Courier-Delivered Acceptance

**Status:** Source changes and regression fixtures implemented. **Live API acceptance pending**. No production credentials or raw buyer data are stored here.

## Field contract

| Visible column | Trustworthy origin | Must NOT use | Missing / uncertain handling |
|---|---|---|---|
| วันที่สั่งซื้อ | order creation (`create_time`, `order_create_time`, confirmed source), timezone Asia/Bangkok for Unix timestamps | payment `pay_time`, tracking `ctime`, order `complete_time`, courier delivery | Date-only text with “Shopee ไม่ระบุเวลา”; suspect legacy fallback: “ไม่ทราบวันที่สั่งซื้อ” |
| วันที่ได้รับพัสดุ | confirmed courier-delivered event source: `delivered_time`, `actual_delivery_time`, `delivery_completed_time`, carrier/courier/parcel-delivered at | generic `delivery_time`, ETA, warehouse received, buyer click-received, `completed_at` or order list_type `3` | Show pending / unverified / API unavailable; never fall back to Complete |
| สถานะ Order | Existing Shopee list_type/status | no implication of courier delivery date | Separate field; Completed status does not prove delivery moment |

## Synthetic cases

- [x] Numeric epoch -> correct Bangkok local timestamp
- [x] Date-only -> no invented time
- [x] Payment/warehouse-tracking time -> cannot become order-created date
- [x] Shopee Complete only -> no courier delivered date
- [x] Estimated delivery / buyer received / generic delivery -> rejected
- [x] Explicit courier delivery source with independent Complete -> accepted
- [x] Legacy uncertain delivered_at retained in DB but hidden as delivery date
- [x] Purchase date SQL excludes foreign-event fallback
- [x] Payment column/filter/Analytics card removed; database raw values preserved
- [ ] Real PHP SQLite + MariaDB CI on exact release commit
- [ ] Authorized live Shopee API: verify with actual tracking evidence, redacted fixtures, account isolation and no shipping-event timestamp substitution
- [ ] Historical production database in staging: backup/restore and migration smoke test

## Production acceptance rule

If the buyer endpoint does not provide a **credible carrier delivered event**, the field must stay unknown, even when Shopee's UI calls the order “สำเร็จแล้ว / Complete”. Verify date precision and timezone only from documented or inspected source. Mark live test as **NOT RUN** until a logged-in, permitted session is tested and results are recorded in private sanitized evidence.
