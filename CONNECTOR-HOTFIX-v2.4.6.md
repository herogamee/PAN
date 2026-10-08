# PAN Shopee Connector v2.4.6

Hotfix จาก live response วันที่ 2026-09-17:

`Shopee API error 33800002 · list_type=12`

## พฤติกรรมใหม่

- `list_type=9/12` เป็น optional non-purchase statuses ใน PAN
- ถ้า Shopee ตอบ error `33800002` เฉพาะสองสถานะนี้ ระบบจะบันทึก `skippedStatusTypes` + `lastOptionalStatusError` แล้วเดินต่อ
- `list_type=3/7/8` (purchase) ยังคง hard stop เมื่อเจอ error นี้
- `list_type=4` (cancelled cleanup) ยังคง hard stop เพื่อไม่ให้ลบ/คงข้อมูลผิด
- Resume จาก checkpoint เดิมได้ ไม่ต้องล้าง checkpoint
- รองรับทั้ง Full Sync และ Update ล่าสุดใน status fallback

เหตุผล: live account แสดงว่า status 3/7/8 เดินผ่านแล้ว แต่ endpoint ปฏิเสธ list_type 12 ด้วย code 33800002. PAN ไม่ตีความรหัสนี้เกินกว่าหลักฐานที่เห็น แต่จัด 9/12 เป็น best-effort non-purchase เพื่อไม่ให้บล็อก purchase/cancellation sync.
