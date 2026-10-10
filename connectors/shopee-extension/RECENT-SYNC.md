# PAN Shopee Connector 2.4.16 — Recent Sync + Detail/Product Enrichment

ใช้ Chrome Extension เดิมได้โดยอัปเดตไฟล์ในโฟลเดอร์เดิมและกด Reload ที่ `chrome://extensions` เพื่อรักษา checkpoint/storage เดิม

## Recent Sync

- อ่านประวัติล่าสุดโดยใช้ anchor ของบัญชี Shopee นั้นและทับซ้อน 7 วัน
- ตรวจบัญชีทุกช่วงสำคัญและหยุดถ้าบัญชีเปลี่ยน
- ไม่ใช้ Full-Sync reconcile เพื่อไม่ซ่อน Order เก่าที่ไม่ได้แวะอ่าน
- checkpoint ขยับเฉพาะเมื่อ page ถูกอ่านและนำเข้าได้อย่างปลอดภัย
- ถ้ามี Order ที่มีเลขอ้างอิงแต่รายการสินค้ายังอ่านไม่ครบ จะเก็บไว้ในคิวค้างตรวจเฉพาะบัญชีและนำเข้า Order ที่ผ่านได้ก่อน; เมื่อสแกนครบยังแสดง partial ไม่ใช่ completed และปุ่ม Retry เฉพาะรายการค้างช่วยตรวจใหม่ได้
- ถ้าไม่มี Order identity / บัญชีผิด / โครงสร้างสถานะไม่รู้จัก ยังคงหยุดโดยไม่เลื่อน checkpoint
- metadata-only ของ Shopee ใช้กฎ terminal/retry เดิมจาก 2.4.4–2.4.6

## Auto Detail Enrichment

หลัง Recent Sync นำเข้า page สำเร็จ Connector 2.4.9 จะเติม Order Detail อัตโนมัติเฉพาะ Order ที่ควร refresh เช่น Order ใหม่, status เปลี่ยน, หรือ Detail ยัง pending/error โดยไม่ยิง Detail ใหม่ทุก Order ทุกครั้ง

Detail ที่พยายามเติม ได้แก่ payment method, shipping/logistics carrier, tracking, paid/delivered/completed timestamps และ metadata ที่ Shopee ส่งกลับมา หาก Shopee ไม่ส่ง field บางตัว PAN จะบันทึก state เป็น `partial` แทนการแสดงว่า “ยังไม่ Repair” อย่างกำกวม

## Manual Detail

- **เติมรายละเอียดที่ยังขาด**: ทำเฉพาะ pending/error/partial ตาม queue แบบแบ่งหน้า
- **เติมรายละเอียดใหม่ทั้งหมด**: Advanced action สำหรับบังคับอ่าน Detail ของทุก Order ในบัญชีอีกครั้ง

คำว่า Repair ยังอาจปรากฏเป็นชื่อ internal state/key เพื่อ backward compatibility แต่ UI หลักใช้คำว่า “เติมรายละเอียด”

## Product/Category Enrichment

ปุ่ม **เติมหมวดสินค้าที่ยังขาด · บัญชีนี้** ทำงานแยกจาก Order Sync โดยใช้ `shop_id/item_id` ที่เก็บไว้แล้วเพื่อขอข้อมูลสินค้าและ category เพิ่มแบบ best-effort หาก Shopee product endpoint เปลี่ยนหรือปฏิเสธ งานนี้อาจมี error ได้โดย **ไม่ทำให้ Order Sync ล้ม**

PAN เก็บ Marketplace category และวาง foundation สำหรับ PAN unified category/product family; auto family ปัจจุบันเป็น deterministic normalized product name ไม่ใช่ fuzzy/AI matching

## Truthful counters

Extension แยก:
- อ่านจาก Shopee (records)
- Order ไม่ซ้ำที่อ่านเจอ
- PAN มีจริง (account-scoped unique purchase orders)
- เพิ่มใหม่
- อัปเดต Order เดิม
- อ่านซ้ำ/ตรวจสถานะ
- พบรายการยกเลิก vs ลบจาก PAN จริง

## Validation

รัน regression suite:

```powershell
node --test connectors/shopee-extension/test/recent.test.mjs
```

Connector 2.4.9 เพิ่ม regression สำหรับ mixed-schema hard stop, per-page account guard, nested payment/logistics parsing และ category breadcrumb parsing

## v2.4.16 quarantine and retry behavior

- Source product_count is only a clue, not always unit count. Never infer missing quantities from it.
- Pending Order IDs and sanitized source-shape keys remain in account-scoped Chrome local state; only aggregated reason counts/field keys are copied through debug.
- Full Sync never calls reconcile or advertises `done` while pending items remain; retrying them does not change the scan checkpoint.
- Missing/partial item arrays are not silently imported as complete snapshots. Use the **ตรวจสินค้าออเดอร์ที่ค้างใหม่** control, no database reset.
- The exact Buyer API response still requires real, consented staging validation; passing fixture tests is not Shopee live acceptance.
