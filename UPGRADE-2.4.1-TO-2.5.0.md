# Upgrade PAN 2.4.1 → 2.5.0 / Shopee Connector 2.4.7 → 2.4.8

1. Backup `storage/config.php` และฐานข้อมูลก่อน
2. วางไฟล์ PAN 2.5.0 ทับ application files โดย **อย่าทับ/ลบ storage runtime**
3. เปิด PAN หนึ่งครั้งเพื่อให้ schema additive migration เพิ่ม Detail/Product/Category fields และ indexes
4. อัปเดตไฟล์ Extension ในโฟลเดอร์เดิม แล้วกด Reload ที่ `chrome://extensions`
5. ตรวจ version: Core 2.5.0 / Connector 2.4.8
6. ใช้ **อัปเดตเฉพาะช่วงล่าสุด** ตามปกติ; ไม่ต้องล้าง checkpoint
7. Order ใหม่/เปลี่ยน status จะ Auto Detail ตามความจำเป็น
8. ถ้าต้องการเติมของเก่า กด **เติมรายละเอียดที่ยังขาด**
9. ถ้าต้องการ category กด **เติมหมวดสินค้าที่ยังขาด · บัญชีนี้** แยกต่างหาก

## Public First Run

การติดตั้งใหม่บน public host ให้ตั้ง environment variable:

```text
PAN_SETUP_TOKEN=<random-secret>
```

แล้วกรอก token นี้ใน First Run. localhost สามารถ setup ได้โดยไม่ต้อง token

## Rollback

เก็บ backup application + database ก่อน upgrade. Schema 2.5.0 เป็น additive เป็นหลัก แต่การ rollback application ควรใช้ฐาน backup เดิมหากต้องการย้อนสถานะอย่างเข้มงวด
