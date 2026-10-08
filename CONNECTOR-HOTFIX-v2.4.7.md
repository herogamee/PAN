# PAN Shopee Connector v2.4.7 — Truthful Sync Counters

- Core PAN: 2.4.1
- Connector: 2.4.7

แก้ความสับสนที่ตัวเลข `ดึงแล้ว` สามารถเป็น 2 เท่าของ Unique Order เมื่อ primary scan และ status fallback อ่าน Order เดิมซ้ำ.

ตัวเลขใหม่:
- อ่านจาก Shopee: จำนวน order records ที่ endpoint ส่งมา
- Order ไม่ซ้ำที่อ่านเจอ: order identity ไม่ซ้ำภายใน scan ปัจจุบัน
- PAN มีจริง: verified purchase Unique Order ในฐาน
- เพิ่มใหม่รอบนี้: order_no ที่ยังไม่มีใน PAN ก่อน import batch
- อัปเดต Order เดิม: order_no ที่มีอยู่แล้วและถูก upsert
- อ่านซ้ำ / ตรวจสถานะ: order identity ที่เจอซ้ำภายใน scan เดียว
- พบรายการยกเลิก: records ที่ Shopee ระบุเป็น cancelled
- ลบรายการยกเลิกจาก PAN: จำนวน row ที่ DELETE ได้จริง

Dashboard เปลี่ยนชื่อ KPI เป็น `คำสั่งซื้อจริงใน PAN` และแสดงสถานะ 3/7/8 ใต้ KPI.
