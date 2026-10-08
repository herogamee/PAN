# PAN 2.5.1 — น้องแพน · Marketplace & Commerce Assistant

**PAN** คือชื่อผลิตภัณฑ์ใหม่ของโปรเจกต์เดิม **ITOOM Commerce Hub** โดยใช้ชื่อภาษาไทยว่า **น้องแพน** และอยู่ภายใต้แบรนด์ **itoom.work**

- Product: **PAN**
- Character: **น้องแพน**
- Role: **Marketplace & Commerce Assistant**
- Brand owner: **itoom.work**
- Primary URL: `https://pan.itoom.work`
- Legacy/functional alias: `https://commerce.itoom.work` → redirect ไป `https://pan.itoom.work`
- Theme: Orange / White / Charcoal

Shopee เป็นเพียง Connector ตัวแรกของ PAN ไม่ใช่ชื่อของระบบหลัก เพื่อให้ในอนาคตเพิ่ม Lazada, TikTok Shop, LINE Shopping หรือ Marketplace อื่นได้โดยไม่ต้องเปลี่ยนชื่อโปรเจกต์อีก

## มีอะไรเปลี่ยนจาก ITOOM Commerce Hub 2.3.0

- เปลี่ยนชื่อระบบที่ผู้ใช้เห็นเป็น **PAN — น้องแพน**
- URL เริ่มต้นใหม่เป็น `pan.itoom.work`
- โฟลเดอร์แพ็กเกจหลักเป็น `pan/`
- SQLite สำหรับการติดตั้งใหม่เป็น `storage/pan.sqlite`
- MySQL/MariaDB ที่แนะนำสำหรับการติดตั้งใหม่เป็น `itoom_pan`
- API header ใหม่เป็น `X-PAN-Key`
- Shopee Extension เปลี่ยนชื่อเป็น **PAN — Shopee Connector**
- UI ยังคงโทนส้มสำหรับโลก Commerce/Marketplace
- เพิ่ม namespace environment variables `PAN_DB_*`

### Backward compatibility

PAN 2.5.0 ไม่บังคับให้ทิ้งข้อมูลจากรุ่นเก่า:

- ตรวจ `storage/itoom_commerce_hub.sqlite`
- ตรวจ `storage/purchase_hub.sqlite`
- config เดิมที่ชี้ SQLite ชื่อเก่ายังใช้ต่อได้
- ยังรับ `X-ITOOM-Commerce-Key`
- ยังรับ `X-Purchase-Hub-Key`
- `HUB_DB_*` environment variables เดิมยังทำงาน
- session cookie ภายในยังใช้ชื่อเดิมเพื่อไม่ทำลาย upgrade โดยไม่จำเป็น


## PAN 2.5.0 — Data Integrity, Detail & Product Explorer

PAN 2.5.0 ปรับแกนข้อมูลก่อนเพิ่ม Marketplace อื่น โดยเน้น 4 ส่วน:

1. **Data Integrity** — Full Sync ตรวจบัญชีทุกหน้า, mixed/unknown schema หยุดก่อน import/checkpoint, reconcile failure ไม่รายงาน completed, และ order-item snapshot reconcile
2. **Order Detail Enrichment 2.0** — เติม payment/shipping/tracking/timestamps แบบ auto เฉพาะ Order ที่ต้อง refresh และแยก state `pending/complete/partial/error`
3. **Product Explorer 2.0** — filter ร้าน/หมวด/บัญชี/ปี, sort หลายแบบ, KPI ร้าน/หมวด/จำนวน/ยอดซื้อ และ Product Family foundation
4. **Product/Category Enrichment** — งานแยกจาก Order Sync เพื่อให้ endpoint สินค้าเปลี่ยนแล้วไม่ทำให้ Order Sync พัง

ดูรายละเอียดใน `RELEASE-NOTES-v2.5.0.md` และขั้นตอน upgrade ใน `UPGRADE-2.4.1-TO-2.5.0.md`

## เลือก SQLite หรือ MySQL/MariaDB

### SQLite

เหมาะกับการเริ่มใช้งานเร็ว ผู้ใช้หลักคนเดียว Collector ไม่กี่เครื่อง และงานเขียนข้อมูลพร้อมกันไม่มาก Backup ง่ายเพราะเป็นไฟล์เดียว

ไม่มีเลขตายตัวว่าเกินกี่ Order แล้วต้องย้าย สิ่งที่ควรดูคือ concurrency, เวลา query/Analytics, backup requirement และการเติบโตของระบบ

### MySQL / MariaDB

แนะนำถ้าจะใช้ `pan.itoom.work` ระยะยาว มีหลาย Collector/อุปกรณ์ ข้อมูลโตต่อเนื่อง หรือต้องการ backup/monitoring/replication ฝั่ง server จริงจัง

PAN รองรับการเริ่มด้วย SQLite และย้าย **SQLite → MySQL** จาก Database Manager ภายหลัง โดยระหว่าง Migration จะเข้า Maintenance ชั่วคราว, copy ข้อมูล, ตรวจ row count และสลับ config เฉพาะเมื่อผ่านครบ ไฟล์ SQLite เดิมไม่ถูกลบอัตโนมัติ

## Requirements

- PHP 8.1+
- PDO
- SQLite: `pdo_sqlite`, `sqlite3`
- MySQL/MariaDB: `pdo_mysql`
- Apache 2.4 แนะนำ `AllowOverride All`
- HTTPS เมื่อใช้งานผ่าน Internet

Ubuntu / Apache:

```bash
sudo apt update
sudo apt install php-sqlite3 php-mysql
sudo systemctl restart apache2
```

ตรวจ PHP extensions:

```bash
php -m | grep -Ei 'PDO|sqlite|mysql'
```

## ติดตั้งใหม่บน pan.itoom.work

ตัวอย่าง:

```text
/var/www/pan.itoom.work/
```

ให้ Apache เขียน `storage/` ได้:

```bash
sudo chown -R www-data:www-data /var/www/pan.itoom.work/storage
sudo chmod 750 /var/www/pan.itoom.work/storage
```

จากนั้นเปิด:

```text
https://pan.itoom.work/
```

ระบบจะเข้า First Run อัตโนมัติ ให้เลือก SQLite หรือ MySQL/MariaDB, ตั้ง Admin และสร้าง API Key

ถ้าเปิดติดตั้งใหม่ผ่าน public host ให้ตั้ง `PAN_SETUP_TOKEN` ใน environment ก่อนเปิด First Run เพื่อป้องกันบุคคลอื่นยึดขั้นตอน setup. Localhost (`127.0.0.1`/`::1`) ไม่บังคับ token

### ค่าแนะนำสำหรับ MySQL/MariaDB

```sql
CREATE DATABASE itoom_pan
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'itoom_pan'@'127.0.0.1' IDENTIFIED BY 'CHANGE_TO_A_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON itoom_pan.* TO 'itoom_pan'@'127.0.0.1';
FLUSH PRIVILEGES;
```

## Upgrade จาก ITOOM Commerce Hub 2.3.0

1. Backup `storage/config.php` และฐานข้อมูลเดิมก่อน
2. แตก PAN 2.5.0 เป็นโฟลเดอร์ใหม่
3. ถ้าใช้ SQLite ให้ copy `storage/config.php` และไฟล์ SQLite เดิมเข้า `pan/storage/`
4. ถ้าใช้ MySQL ให้ copy `storage/config.php` เดิมเข้า `pan/storage/` ได้เลย เพราะ DSN เดิมยังรองรับ
5. เปลี่ยน VirtualHost จาก `commerce.itoom.work` ไป `pan.itoom.work`
6. เปิด `https://pan.itoom.work` แล้วตรวจ Dashboard / Orders / Analytics / Export
7. Reload Extension จาก `connectors/shopee-extension/`
8. ใน Database Manager คัดลอก PAN URL + API Key ไปใส่ Extension
9. เมื่อทดสอบผ่านแล้ว ค่อยทำ `commerce.itoom.work` เป็น redirect alias

> ถ้าเป็นการติดตั้งใหม่ ไม่ต้องใช้ชื่อฐานข้อมูลเก่า ให้ใช้ `pan.sqlite` หรือ `itoom_pan` ได้เลย

## commerce.itoom.work → pan.itoom.work

แนะนำให้เก็บ subdomain เดิมไว้เป็น redirect เพื่อ bookmark/link เก่าไม่เสีย ตัวอย่าง Apache ฝั่ง HTTP:

```apache
<VirtualHost *:80>
    ServerName commerce.itoom.work
    Redirect permanent / https://pan.itoom.work/
</VirtualHost>
```

ถ้ามี HTTPS ที่ `commerce.itoom.work` ให้ VirtualHost 443 ของโดเมนเดิม redirect ไป `https://pan.itoom.work/` เช่นกัน และคง certificate ของโดเมนเดิมไว้ตราบใดที่ยังให้บริการ redirect

## Shopee Connector 2.4.9

เพิ่มปุ่ม **อัปเดตเฉพาะช่วงล่าสุด** เพื่อลดการอ่านประวัติทั้งหมดซ้ำ ใช้วันที่ล่าสุดใน PAN ทับซ้อน 7 วันและครอบคลุมรายการค้าง ดู `connectors/shopee-extension/RECENT-SYNC.md` สำหรับการอัปเดตส่วนเสริมและ API

ตั้งแต่ v2.4.6 ถ้า Shopee `get_order_list` ตอบ error `33800002` เฉพาะ `list_type=9/12` ซึ่ง PAN จัดเป็นหมวด non-purchase ระบบจะบันทึก diagnostics แล้วข้ามเฉพาะหมวดนั้น เพื่อให้ purchase/cancellation scan เดินต่อได้ โดย error เดียวกันบน purchase statuses 3/7/8 ยังคงเป็น hard stop และไม่เลื่อน checkpoint.

ตั้งแต่ v2.4.7 ตัวเลขใน Extension แยก `อ่านจาก Shopee`, `Order ไม่ซ้ำที่อ่านเจอ`, `PAN มีจริง`, `เพิ่มใหม่`, `อัปเดต Order เดิม` และ `อ่านซ้ำ/ตรวจสถานะ` ออกจากกัน โดย API import เป็นผู้ตัดสินว่า `order_no` ใด INSERT หรือ UPDATE จริง จึงไม่ใช้ตัวนับ records จาก Connector แทนจำนวน Unique Order ในฐานอีกต่อไป.

1. เปิด `chrome://extensions`
2. เปิด Developer mode
3. Load unpacked `connectors/shopee-extension/`
4. เปิด PAN → Database Manager
5. Copy **PAN URL** และ **API Key**
6. วางลงใน PAN — Shopee Connector
7. กด **ตรวจการเชื่อมต่อ PAN**
8. เปิด `https://shopee.co.th/user/purchase/` และ Login
9. เริ่ม Sync

Default URL:

```text
https://pan.itoom.work
```

Connector ส่ง API key ด้วย:

```text
X-PAN-Key: <API_KEY>
```

Server ยังรับ header เดิมจากรุ่นเก่าเพื่อให้ rollout แบบค่อยเป็นค่อยไปได้

## Database Migration: SQLite → MySQL

เปิด **Database Manager** แล้วกรอก MySQL/MariaDB ปลายทาง ระบบจะ:

1. เข้า Maintenance mode
2. ตรวจการเชื่อมต่อและ schema ปลายทาง
3. ตรวจว่าปลายทางเหมาะกับการ Migration
4. Copy `orders`
5. Copy `order_items`
6. Copy `accounts`
7. Copy `collector_batches`
8. ตรวจจำนวน row ต้นทาง/ปลายทาง
9. สลับ config ไป MySQL เฉพาะเมื่อผ่านครบ
10. ปิด Maintenance mode
11. เก็บ SQLite เดิมไว้เป็น rollback snapshot

นี่ไม่ใช่ zero-downtime migration: Connector จะได้รับ HTTP 503 ชั่วคราวระหว่างย้าย เพื่อรักษาความถูกต้องของข้อมูล

## Security

- Dashboard / Settings / Database Manager ต้อง Admin Login
- Connector/API mutation ใช้ API Key; admin session ใช้กับเส้นทางที่รองรับใน UI ตามสิทธิ์
- คำสั่งอันตรายบนเว็บใช้ CSRF token
- `storage/` ถูก deny ผ่าน `.htaccess`
- Password ฐานข้อมูลอยู่ใน `storage/config.php`; ตั้ง permission ฝั่ง server ให้เหมาะสม

Environment variables ใหม่:

```text
PAN_DB_DRIVER
PAN_DB_HOST
PAN_DB_PORT
PAN_DB_NAME
PAN_DB_USER
PAN_DB_PASS
PAN_DB_CHARSET
PAN_SQLITE_PATH
```

สำหรับ upgrade ยังรองรับ `HUB_DB_*` เดิม

## Backup

SQLite ติดตั้งใหม่:

```text
storage/pan.sqlite
```

ฐานเก่า `itoom_commerce_hub.sqlite` และ `purchase_hub.sqlite` ยังอ่านได้เมื่อ config/First Run เลือกไฟล์นั้น

MySQL/MariaDB ตัวอย่าง:

```bash
mysqldump --single-transaction --routines --triggers itoom_pan > itoom_pan.sql
```

อย่าใส่รหัสผ่านตรง command line ใน production หากมีผู้ใช้อื่นบนเครื่องที่ดู process list ได้

## Architecture

```text
PAN
├── Dashboard / Orders / Products / Shops / Analytics
├── SQLite or MySQL/MariaDB
└── connectors/
    └── shopee-extension/
```

โหมด Extension: Shopee session อยู่ใน Chrome ของผู้ใช้ PAN ไม่เก็บ Cookie, password หรือ OTP ของ Shopee

โหมด Server Connector (ทดลอง): เปิด `shopee.php` เพื่อควบคุมเบราว์เซอร์บน Windows/Ubuntu และเก็บเซสชันแยกจากโฟลเดอร์เว็บ ต้องติดตั้งบริการ Node.js เพิ่ม ดู `connectors/shopee-server/README.md` สำหรับขั้นตอนติดตั้งและ systemd บน Ubuntu

## Permanent data rules

- `list_type=4` Cancelled: ไม่เก็บในระบบ
- list_type 3/7/8 = purchase
- list_type 9/12 = non-purchase
- ห้าม fallback วันที่เป็น “วันนี้” เมื่อ Shopee ไม่มี timestamp
- Full Sync upsert ตาม `order_no`
- Full Sync reconcile แยกตาม Shopee account

---

Version: **PAN 2.5.1**  
Character: **น้องแพน**  
Role: **Marketplace & Commerce Assistant**  
Brand: **itoom.work**


## PAN 2.5.1 maintenance update

See [`docs/PAN-v2.5.1-HANDOFF.md`](docs/PAN-v2.5.1-HANDOFF.md) for 2.5.1 fixes, test/deployment instructions, and explicit production-validation limitations. Historical v2.5.0 release notes are retained unchanged.
