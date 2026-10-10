# PAN — Staging Acceptance Runbook

> สำหรับ **PAN Core v2.5.11 + Shopee Connector v2.4.15**. ห้ามทำการทดสอบที่แก้ไขข้อมูลบน Production โดยไม่สำรอง/อนุมัติ ต้องใช้ staging แยกฐานข้อมูลและ config จากระบบจริง

## 0. ก่อนเริ่ม

- [ ] ยืนยัน commit/release version: `VERSION` เป็น `2.5.11`, Extension manifest เป็น `2.4.15`
- [ ] สร้าง staging directory, config, database, API keys และบัญชีทดสอบที่ **ไม่ใช้ไฟล์ runtime เดียวกับ Production**
- [ ] Backup source, DB, `storage/config.php` และไฟล์ WAL ของ SQLite ด้วยวิธีที่เหมาะกับระบบที่รันอยู่
- [ ] ทดสอบ restore ลง staging ที่แยกจากต้นฉบับก่อนเริ่ม import
- [ ] ตรวจเครือข่าย HTTPS, Apache/PHP, DB driver และสิทธิ์บน `storage/`
- [ ] เช็กสิทธิ์เข้าใช้ Shopee account; ทดสอบเฉพาะข้อมูลที่เจ้าของอนุญาตและอย่าอัปโหลด raw data ไป GitHub

## 1. Windows XAMPP3 (PowerShell)

ใช้ path ตัวอย่าง `C:\xampp3\htdocs\pan-staging` และปรับตามสถานที่ติดตั้งจริง:

```powershell
$pan = 'C:\xampp3\htdocs\pan-staging'
$php = 'C:\xampp3\php\php.exe'
& $php -v
& $php -m | Select-String -Pattern 'PDO|pdo_sqlite|pdo_mysql|sqlite3'
Get-Content (Join-Path $pan 'VERSION')
Get-Content (Join-Path $pan 'connectors\shopee-extension\manifest.json') | ConvertFrom-Json | Select-Object version

# Syntax check ทุก PHP — หยุดเมื่อมี error
Get-ChildItem $pan -Filter '*.php' -File -Recurse | ForEach-Object {
  & $php -l $_.FullName
  if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" }
}

Set-Location $pan
node --test .\connectors\shopee-extension\test\recent.test.mjs .\connectors\shopee-extension\test\product-enrichment.test.mjs

# Server connector regression ใช้ service ที่แยกจาก production เท่านั้น
Set-Location '.\connectors\shopee-server'
npm.cmd ci
npx.cmd playwright install chromium
npm.cmd test
```

**สำคัญ:** `php -m` แสดง `PDO` อย่างเดียวไม่พอ ต้องมี `pdo_sqlite` และ/หรือ `pdo_mysql` ตาม DB ที่จะทดสอบด้วย; หาก driver ไม่มี ให้บันทึก `BLOCKED` ไม่ถือว่าผ่าน

## 2. Ubuntu / Linux staging

```bash
php -v
php -m | grep -Ei 'PDO|sqlite|mysql'
cat VERSION
php -r 'echo json_decode(file_get_contents("connectors/shopee-extension/manifest.json"), true)["version"], PHP_EOL;'
find . -path './connectors/shopee-server/node_modules' -prune -o -name '*.php' -type f -exec php -l {} \;
node --test connectors/shopee-extension/test/*.test.mjs
(cd connectors/shopee-server && npm ci && npx playwright install chromium && npm test)
```

เช็กผลแต่ละคำสั่งให้เป็น exit code 0; `find -exec` อาจไม่สะท้อน error aggregate ที่ต้องการในบาง shell จึงควรอ่านผล `php -l` ทุกไฟล์หรือใช้ CI ที่เหมาะสม

## 3. DB real driver acceptance — staging เท่านั้น

1. สร้างฐานข้อมูลทดสอบเปล่า **ทั้ง** SQLite และ MySQL/MariaDB (ถ้าใช้งานทั้งสอง driver)
2. Restore สำเนา PAN 2.5.0 ที่ไม่ใช่ live DB เข้า staging และตรวจจำนวน `accounts`, `orders`, `order_items`, `collector_batches`
3. ติดตั้ง source v2.5.3 แล้วตรวจ migration schema และ compatibility จากรุ่นเก่า
4. Import ชุด synthetic ที่มี: 2 accounts, 2 orders ที่มี line-item overlap, cancelled record, status update, uncertain order snapshot
5. ตรวจ account-scoped unique counts; snapshot สมบูรณ์จึงจะลบ stale items ได้; uncertain page ไม่ขยับ checkpoint
6. ลอง transaction failure แล้วตรวจว่าไม่ทิ้งครึ่ง batch; restore backup แล้ว verify counts และ foreign references
7. จด `PAN-P1-01 / 02 / 04 / 05` ใน [Acceptance Matrix](ACCEPTANCE-MATRIX.md)

## 4. Shopee real-session acceptance (ได้รับอนุญาตเท่านั้น)

1. ใน staging ตั้ง PAN API URL + key ที่ไม่ใช่ของ production และโหลด Extension v2.4.10
2. Log in Shopee account ของผู้ทดสอบเองบน browser; ตรวจ account ID ก่อน sync
3. ทดสอบ **Recent Sync**: เปรียบ unique purchased orders / cancelled records / upserts อย่างมีเหตุผล ไม่ใช้ record counter แทน PAN unique orders
4. ทดสอบ **Full Sync** ใน staging: record structure unknown/mixed และ account switch ต้องหยุดแบบ fail-closed
5. ทดสอบ **Repair Details** 2+ pages รวม pending/partial/error และ missing fields; บันทึกเฉพาะ normalized/redacted results
6. ทดสอบ **Payment/Shipping/Tracking** เทียบกับ Shopee ที่เห็นในบัญชี: ถ้า source ไม่ให้ค่าต้องแสดงไม่ทราบ ไม่คาดเดา
7. ทดสอบ **Product Category Enrichment** 2+ pages และ failed product 1 รายการ; งาน Order Sync ต้องไม่ถูก block
8. หาก session ถูกบล็อก/HTTP 401/403/anti-fraud ให้หยุด และบันทึก `BLOCKED`, ห้าม bypass; อย่าตีความว่า sync สำเร็จ

## 5. Server Connector staged separately

ดู [Server Connector Setup](../connectors/shopee-server/README.md). ต้องเก็บ Playwright profile นอก document root, service bind localhost และ private ACL; ห้ามเปิด port 3210 สาธารณะ. Test restart session, interactive/scheduler mutex และ session rejection ก่อนเปลี่ยนโหมดจาก Experimental

## 6. เกณฑ์ตัดสินและส่งมอบ

- **GO**: CI ผ่าน, real DB transaction/rollback + restore ผ่าน, account isolation/cancel/repair/field correctness ผ่าน, ไม่มีช่องโหว่ P0 ค้าง
- **NO-GO**: มี false Complete, schema error แล้ว checkpoint ขยับ, ข้อมูลข้ามบัญชี, ต้องเดาข้อมูล payment/shipping, หรือกู้คืน DB ไม่ได้
- เก็บรายงานใน [Issue #1](https://github.com/herogamee/PAN/issues/1) เป็น sanitized summaries พร้อม Case IDs; update [Acceptance Matrix](ACCEPTANCE-MATRIX.md)
- ห้ามอัปโหลด credentials, raw order data, Shopee buyer info หรือ production dumps ลง Public GitHub

## 7. PHP synthetic integration and login lockout regressions (added in v2.5.3)

In a **checkout/test copy** (never live PAN storage), with PHP 8.1+ and pdo_sqlite enabled:

```powershell
# Windows XAMPP3 PowerShell — tests use disposable temp SQLite, never PAN storage/config.php
$env:PAN_CI_TEST = '1'
& 'C:\xampp3\php\php.exe' .\tests\login-throttle.php
& 'C:\xampp3\php\php.exe' .\tests\db-integration.php sqlite
Remove-Item Env:PAN_CI_TEST
```

GitHub Actions also runs MySQL tests against its **dedicated disposable MariaDB service** database `pan_ci_test`. Local MySQL integration refuses any other database name or remote host; never point synthetic tests to your PAN production DB.

Login v2.5.3 requires the login page's new CSRF hidden field; refresh old login tabs. Rate-limit counter files are stored inside `storage/auth-throttle/`; this directory must be writable by PHP, denied from HTTP access, and ignored by Git. Locked login responds HTTP 429 and Retry-After. When behind reverse proxies, validate shared-client-IP behavior before public promotion; forwarded headers are intentionally not trusted blindly.

## 8. การตรวจรับการถอดวันรับพัสดุ (PAN 2.5.6)

- [ ] Orders **ไม่มี** คอลัมน์ `วันที่ได้รับพัสดุ` และไม่มีคอลัมน์ช่องทางชำระเงิน/บริษัทขนส่ง; `วันที่สั่งซื้อ` ยังแสดงข้อมูลตรงจาก Shopee โดยไม่เติมเวลา 00:00 เมื่อไม่มีเวลา
- [ ] สถานะคำสั่งซื้อจาก Shopee ยังแสดง แต่ไม่ใช้ Completed เป็นหลักฐานวันนำส่งถึงผู้รับ
- [ ] Analytics แหล่งวันที่แสดงเฉพาะ created/order date/unknown ไม่ใช้ `delivered_at` และ `completed_at` เป็นตัวจัดประเภท
- [ ] Completed Order ไม่มีวันนำส่ง ไม่ติดคิว Repair เฉพาะเพราะขาดวันที่; Detail state หมายถึงตรวจข้อมูลแล้ว ไม่ใช่ยืนยันว่าผู้รับได้พัสดุ
- [ ] สถานะ legacy partial + `detail_missing_fields=delivered_at` เดี่ยว/ผสมกับ `payment_method`, `shipping_carrier` ต้องไม่นับค้างทั้ง Repair queue และ /api/status.php
- [ ] ออเดอร์ที่ยังไม่เคยตรวจ Detail, API error, account switching และ missing field อื่นยังเข้าคิวได้อย่างปลอดภัย
- [ ] สำรองและ Restore ฐานข้อมูลเก่าบน staging; ยืนยันว่า `delivered_at`, `delivery_date_source`, `completed_at`, `tracking_number` และข้อมูลออเดอร์เดิมยังอยู่ โดยไม่เผยแพร่ข้อมูลเหล่านั้นเป็นหลักฐานที่ยืนยันแล้ว
- [ ] ทดสอบการโหลด Connector 2.4.13, PHP 2.5.6 และตรวจ log เฉพาะที่ปกปิดข้อมูลผู้ใช้แล้ว

**หลักฐานอัตโนมัติ:** [PAN v2.5.6 CI](https://github.com/herogamee/PAN/actions/runs/37942186241) · [Retirement policy](PAN-v2.5.6-DELIVERY-FIELD-RETIREMENT.md). **Live Shopee / historical production restore: ยังไม่ได้ตรวจรับ**.

## 9. แก้รายการสั่งซื้อเดือนล่าสุดไม่แสดง (PAN 2.5.7)

ก่อนเปลี่ยน Production **สำรองฐานข้อมูลพร้อม WAL/config** แล้วทดสอบกับสำเนาบน staging อย่างเดียว:

- [ ] จดจำนวนคำสั่งซื้อใน DB และตาม `validation_state` / `purchase_state` แบบ **counts only** ก่อนอัปเกรด
- [ ] ตรวจ RAW rows ที่ถูกทำเครื่องหมาย `not_seen_full_scan` และแต่ละบัญชีในสำเนาฐาน; อย่าเปลี่ยนสถานะ/ลบข้อมูลตามที่คิดเอง
- [ ] หลังวาง Core 2.5.7 เปิด Orders แบบ **ไม่มีตัวกรอง**; กดปุ่ม “เคยไม่พบใน Full Sync”, “ไม่ทราบวันที่สั่งซื้อ”, “PAN พบ/ซิงก์เดือนนี้” และเทียบจำนวนกับ DB
- [ ] หมวด “PAN พบเดือนนี้” เป็น **เดือนที่ PAN พบออเดอร์ ไม่ใช่เดือนที่ซื้อ**; ห้ามนับรายการที่ไม่มีวันที่ยืนยันเป็นยอดซื้อเดือนนั้น
- [ ] ออเดอร์ที่ยังอยู่ใน DB จากการสแกนเก่าต้องไม่ถูกลด `validation_state` หลัง Full Sync ปัจจุบัน; แต่ **ห้ามเริ่ม Full Sync จนกว่า** สรุปจำนวนก่อน/หลังและยืนยันบัญชีถูกต้อง
- [ ] ตัวอย่างวันที่จาก Shopee จริง: วันที่/เวลา ISO เป็น `09/10/2569 21:30` เมื่อมีเวลาจริง; แค่วันที่เป็น `09/10/2569` ไม่เติมเวลาหลอก; timestamp UTC/Z ต้องแปลงเวลาไทยให้ถูก
- [ ] กราฟรายเดือนแสดงเฉพาะออเดอร์ที่มีวันที่สั่งซื้อจริง; list type 3/7/8 รวมออเดอร์ที่ยังจัดส่ง; รายการวันที่ไม่ทราบอยู่ในหมวดแยก
- [ ] หากไม่พบรายการในฐานข้อมูลจริงเลย ให้หยุดแก้ UI และตรวจระบบ Collector, cancellation และ private backups; UI ไม่สามารถสร้างข้อมูลที่ถูกลบจริงกลับมาได้

อ่าน [Incident / Acceptance Details](PAN-v2.5.7-ORDER-VISIBILITY-ACCEPTANCE.md) · [Five-job CI success](https://github.com/herogamee/PAN/actions/runs/37947009304). Production acceptance remains **PENDING**.

## 10. ตรวจระบบขยายสินค้าใน Order (PAN 2.5.8)

- [ ] หลังสำรองข้อมูล ให้เปิด `?page=orders` บน staging แล้วกดเลข Order/พื้นที่แถว ต้องขยาย/ยุบได้โดยไม่เปลี่ยนหน้า
- [ ] ทดสอบออเดอร์ที่มีสินค้าเดียว หลายสินค้า หลายตัวเลือก และออเดอร์ที่ PAN ยังไม่เก็บสินค้า โดยเทียบข้อมูลกับ Shopee จริงแบบปกปิดตัวตน
- [ ] ตรวจรูป ชื่อ/ตัวเลือก จำนวน ราคาต่อชิ้นและมูลค่ารายการ ข้อมูลต้นทางอาจต่างจากยอดจ่ายจริงเพราะคูปอง/ค่าจัดส่ง
- [ ] คลิกลิงก์ร้านค้าต้องกรองร้านตามเดิม; ลิงก์สินค้าเปิดแท็บใหม่อย่างปลอดภัย
- [ ] ตรวจตัวกรอง/แบ่งหน้า 10/50/200 Order และมือถือ ไม่แสดงสินค้าของ Order อื่น
- [ ] กดขยายไม่ควรสร้างคำขอไป Shopee API เพิ่ม และไม่เปลี่ยนแปลงฐานข้อมูล

[ผล CI v2.5.8](https://github.com/herogamee/PAN/actions/runs/37962361472) · [เกณฑ์ตรวจรับ](PAN-v2.5.8-ORDER-EXPANSION.md). Production acceptance ยังไม่ได้ทดสอบ

## 11. จำนวนชิ้นและตัวเลือกสินค้าต่อคำสั่งซื้อ (PAN 2.5.9)

- [ ] สำรองฐานข้อมูล/ไฟล์ runtime อย่างปลอดภัย แล้วทดสอบอัปเกรดจาก PAN 2.5.8 บน staging ก่อน; ห้าม Full Sync ก่อนตรวจสอบจำนวนและบัญชี
- [ ] ติดตั้ง Core 2.5.9 และ Reload Extension 2.4.14; เปิดหน้า Shopee ของบัญชีที่ได้รับอนุญาต
- [ ] ใน Shopee UI ตรวจด้วยตนเองว่าสั่งสินค้าแต่ละตัวเลือกจำนวนเท่าไร รวมเป็นกี่ชิ้น (เช่น สินค้าต่างตัวเลือก x1+x1 และอีก SKU x3 = 5 ชิ้น)
- [ ] ใน Extension หัวข้อ **ตรวจจำนวนสินค้าเฉพาะ Order** กรอกเลข Order แบบส่วนตัว + จำนวนที่ตรวจเอง แล้วกด **ตรวจและอัปเดตเฉพาะออเดอร์นี้**
- [ ] หาก Buyer API ยืนยัน 3 บรรทัด / 5 ชิ้น และตรงจำนวนที่กรอก ให้ตรวจหน้ารายการว่า 3 บรรทัดและจำนวนรวมถูกต้อง; ไม่มีการแก้ไข Order อื่น
- [ ] หาก API ให้จำนวนไม่ครบ/ขัดแย้ง หรือบัญชีไม่ตรง ต้อง **ไม่เขียนข้อมูลและไม่เลื่อน Full/Recent checkpoint**
- [ ] ทดสอบหลาย variant ไม่มี model_id, การซ้ำ SKU จากหลายกลุ่ม, quantity ไม่มีค่า/ศูนย์/ค่าขัดแย้ง และจำนวนที่ไม่ตรงกับผู้ใช้
- [ ] เก็บหลักฐานเพียงผลรวม/ประเภทฟิลด์ที่ปกปิดแล้ว ห้ามใส่เลข Order จริง รูป buyer ID, cookies, shipping addresses หรือ payload JSON ดิบลง Public GitHub

**Evidence:** [Five-job CI](https://github.com/herogamee/PAN/actions/runs/37965956961) · [Quantity acceptance](PAN-v2.5.9-QUANTITY-ACCEPTANCE.md). Live Buyer API validation is **PENDING**.

## 12. User-attested quantity corrections (Core v2.5.10)

- [ ] ตรวจฐาน SQLite/MySQL จาก backup แบบส่วนตัวว่าออเดอร์เก่าที่ต้องแก้มีจำนวนที่บันทึกอยู่จริงเท่าใด ก่อนแก้ไข
- [ ] เตรียมสำเนาฐานข้อมูลเพื่อตรวจสอบ, ทำ backup ที่สร้างจาก SQLite online backup/WAL อย่างถูกต้อง และอย่านำฐานเก่าทั้งก้อนวางทับฐานสดที่มี Order ใหม่
- [ ] ถ้าผู้ใช้ยืนยันจำนวนด้วยตนเอง แต่ Shopee Buyer API ไม่สามารถให้รายละเอียดครบ ให้ติดป้าย `pan_user_attested_quantity` เฉพาะแถวที่แก้; จำนวนที่ยืนยันต่างจากราคาต่อหน่วย/ส่วนลดซึ่งยังไม่ยืนยัน
- [ ] หน้า Orders ต้องแสดงจำนวนที่แก้และบอกผู้ใช้ชัดว่าราคาของแถวแก้ยังไม่ทราบ ห้ามนำค่าเดิมมาคูณจำนวนใหม่แล้วกล่าวว่าเป็นราคาจริง
- [ ] Full/Recent Sync ที่ส่งข้อมูลจำนวนเก่าขาด หรือหล่นตัวเลือกต้องหยุดและ rollback ทั้ง batch; ไม่เลื่อน checkpoint เพื่อให้หายไปอีก
- [ ] Single-Order Recheck ที่มีหลักฐานจำนวน/รุ่น/SKU ครบ จึงสามารถแทน provisional rows ได้; รีเฟรชแล้วข้อมูลต้องตรงและไม่กระทบ Order อื่น
- [ ] อย่าอัปไฟล์ SQLite, `storage/config.php`, raw JSON หรือเลข Order จริงขึ้น GitHub; เผยแพร่ได้เพียง synthetic fixtures และผลนับที่ปกปิดตัวระบุแล้ว

[Core 2.5.10 release notes](../RELEASE-NOTES-v2.5.10.md) · [GitHub CI five jobs PASS](https://github.com/herogamee/PAN/actions/runs/37971871137). User-authorized historical data correction is not synonymous with Buyer API acceptance.

## 13. Buyer Detail-first auto item rows (v2.5.11)

- [ ] Back up current database and runtime config; **stage a separate copy**, do not replace live storage or run destructive Full Sync first.
- [ ] Verify Core 2.5.11 and Extension 2.4.15; Chrome extension must be reloaded.
- [ ] Use the authorized Shopee buyer account and one known order displayed as four item rows, total five pieces. Start **single-order recheck with order number only**, with no expected-total input.
- [ ] Confirm the private Buyer Order Detail endpoint returns all purchased rows. Record only **redacted field paths/row counts**, never raw personal data in public GitHub.
- [ ] If detail is complete, verify four independently stored rows, summed quantity five, order-level amount unchanged, correct account and no unrelated order mutations.
- [ ] If API returns only two preview rows/metadata or disagrees on quantities, record BLOCKED and preserve the existing verified/attested DB records; **no false success** and no unsafe checkpoint advance.
- [ ] Verify second import is idempotent, Full/Recent preview cannot overwrite detail rows, rollback works in SQLite/MariaDB.
- [ ] If Buyer Detail is metadata-only in real session, research permitted same-session HTML item-list extraction separately. No bypass of Shopee login/CAPTCHA/anti-fraud.

Source [v2.5.11 release notes](../RELEASE-NOTES-v2.5.11.md) · [CI PASS](https://github.com/herogamee/PAN/actions/runs/38034020920) · [Live acceptance Issue #8](https://github.com/herogamee/PAN/issues/8). **Synthetic CI does not prove real Buyer API availability.**
