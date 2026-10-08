# PAN 2.5.0 + Shopee Connector 2.4.8

## Data Integrity & Production Hardening

- Full Sync ตรวจ Shopee account ทุกหน้า; เปลี่ยนบัญชีระหว่างงานแล้วหยุด
- ถ้า page มี Order โครงสร้างอ่านไม่ได้แม้เพียงบางรายการ จะไม่ import page และไม่เลื่อน checkpoint
- Full Sync reconcile ล้มเหลว = งานล้ม ไม่แกล้งรายงาน completed
- Reconcile `order_items` snapshot: line item เก่าที่ไม่อยู่ใน snapshot ล่าสุดถูกลบ
- SQLite→MySQL ตรวจ row-count ก่อน commit และ rollback ได้เมื่อ mismatch
- SQLite backup ตรวจ WAL checkpoint busy ก่อนปล่อย backup
- all-json export stream row-by-row ลด memory pressure
- First Run บน public host รองรับ/บังคับ `PAN_SETUP_TOKEN`

## Order Detail Enrichment 2.0

- payment/shipping/tracking/timestamps parser รองรับ nested field มากขึ้น
- เพิ่ม `detail_state`: pending / complete / partial / error
- แยก “Shopee ไม่มีข้อมูล” ออกจาก “รอเติมรายละเอียด” และ “เติมไม่สำเร็จ”
- Recent Sync เติม Detail อัตโนมัติเฉพาะ Order ใหม่/status เปลี่ยน/pending/error
- manual queue เปลี่ยนเป็น **เติมรายละเอียดที่ยังขาด** และมี Advanced **เติมรายละเอียดใหม่ทั้งหมด**
- queue รองรับ pagination และ account guard

## Product Explorer 2.0

- KPI: product family, shops, categories, qty, spend, recent orders
- filter: search, shop, category, Shopee account, year
- sort: latest, spend, purchase count, qty, shop count, lowest price, latest price, name
- Product Family foundation รวมสินค้า exact-normalized name ข้ามร้านอย่างปลอดภัย
- Product detail แสดง category, จำนวนร้าน, ยอดซื้อรวม, price/purchase history

## Product/Category Enrichment

- เก็บ shop/item/model IDs และ marketplace category fields ใน order_items
- queue + enrichment API แยกจาก Order Sync
- Connector มีปุ่มเติมหมวดสินค้าที่ยังขาด
- product endpoint เป็น best-effort; failure ไม่ block Order Sync

## Counter/Analytics consistency

- PAN count ใน Extension scope ตาม account
- Dashboard date priority สอดคล้อง Analytics มากขึ้น
- user-facing “Repair Coverage” เปลี่ยนเป็น “Detail Coverage”

## Known limitations

- Shopee buyer/private web API ไม่มี contract สาธารณะคงที่และอาจเปลี่ยน shape/endpoint ได้
- Product category enrichment ยังต้องยืนยันกับ live Shopee session หลัง deploy
- Product Family auto grouping ยังไม่ merge สินค้าที่ชื่อแตกต่างแต่เป็นสินค้าเดียวกัน
- PAN ปัจจุบันมี Connector จริงเพียง Shopee; multi-marketplace unique identity migration ยังเป็นงานอนาคต
