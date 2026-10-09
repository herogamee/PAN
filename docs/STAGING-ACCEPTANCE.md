# PAN — Staging Acceptance Runbook

> สำหรับ **PAN Core v2.5.5 + Shopee Connector v2.4.12**. ห้ามทำการทดสอบที่แก้ไขข้อมูลบน Production โดยไม่สำรอง/อนุมัติ ต้องใช้ staging แยกฐานข้อมูลและ config จากระบบจริง

## 0. ก่อนเริ่ม

- [ ] ยืนยัน commit/release version: `VERSION` เป็น `2.5.5`, Extension manifest เป็น `2.4.12`
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

## 8. การตรวจรับข้อมูลขนส่งและวันที่ได้รับพัสดุ (PAN 2.5.5)

- [ ] Orders ต้องไม่มีคอลัมน์ **ขนส่ง**, ตัวกรองบริษัทขนส่ง และสรุป/กราฟบริษัทขนส่งใน Analytics; ช่องทางชำระเงินยังคงไม่แสดง
- [ ] วันที่สั่งซื้อยังมาจากเวลาสร้างคำสั่งซื้อจริง (date-only ต้องไม่เพิ่มเวลา 00:00 ขึ้นมาเอง)
- [ ] วันที่ได้รับพัสดุยังใช้เฉพาะหลักฐานนำส่งถึงผู้รับ ไม่ใช่วันที่ร้านส่งหรือวันที่ Order Complete
- [ ] หาก Buyer API ตอบแต่เลขติดตามและวันนำส่ง โดยไม่มีชื่อบริษัทขนส่ง ต้องเก็บเลขติดตาม/วันที่จริงไว้ และไม่แจ้งว่า Detail ขาดเพียงเพราะไม่มีบริษัทขนส่ง
- [ ] ตรวจว่าข้อมูลออเดอร์เก่าที่ `detail_missing_fields=shipping_carrier` อย่างเดียวไม่ถูกนับ/Repair ซ้ำ; แต่ไม่มีวันนำส่งจริงต้องยังเป็นงานค้างสำหรับ Completed orders
- [ ] ตรวจ DB SQLite/MySQL, backup/rollback กับ staging copy ของผู้ใช้ และ manual sync/repair กับบัญชีที่ได้รับอนุญาตก่อน Production Acceptance
- [ ] ห้ามแปล `shipping_carrier` จาก Seller API มาเป็นค่า Buyer API โดยไม่มีหลักฐานว่าผู้ซื้อได้รับข้อมูลนั้นจริง

หลักฐาน Source และ Tests: [PAN v2.5.5 shipping policy](PAN-v2.5.5-SHIPPING-ACCEPTANCE.md) · [CI ผ่าน](https://github.com/herogamee/PAN/actions/runs/37938489873). Live Shopee session ยังไม่ผ่านการตรวจรับ
