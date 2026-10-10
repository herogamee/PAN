# PAN — Roadmap & Release Gates

> อัปเดต 2026-10-09 (Asia/Bangkok) · **PAN Core v2.5.11 + Shopee Connector v2.4.15** · Implementation baseline: [9837673](https://github.com/herogamee/PAN/commit/98376735244058ec1517e04de8eef90ebe7b97f0) before this documentation update.
>
> **สำคัญ:** Source อยู่บน GitHub และ [CI ผ่าน](https://github.com/herogamee/PAN/actions/runs/37746656907) แต่ **ยังไม่มีหลักฐานผ่าน Production Acceptance**. สถานะเฟสต่อไปนี้เป็นแผนงานและการตรวจรับ ไม่ใช่คำยืนยันว่าใช้งานกับ Shopee จริงสำเร็จแล้ว

## PAN v2.5.11 — Buyer Detail-first auto item reconciliation (Issue #8)

- **Implemented:** Full/Recent Sync reads authenticated Shopee Buyer Order Detail before committing item snapshots; preserves **four separate source rows (1+1+2+1=5)** even with repeated SKU/model/variant. Account validation and explicit quantity checks remain mandatory.
- **Quality control:** Row-level source provenance stored; condensed Order List previews cannot overwrite Buyer Detail-verified rows. Incomplete/missing/contradictory data stops import rather than silently manufacturing quantities. User-attested rows protected until complete matching detail verified.
- **One-order recheck:** Requires order number only, **not manually entered piece counts**. Still requires a valid authorized Shopee session.
- **CI:** [Core/Connector source](https://github.com/herogamee/PAN/commit/b97b965f663235d1a457618a4f907bf0de03fa1f), [5-job SQLite/MariaDB/Connector CI PASS](https://github.com/herogamee/PAN/actions/runs/38034020920), [release notes](RELEASE-NOTES-v2.5.11.md), [acceptance plan](docs/PAN-v2.5.11-BUYER-DETAIL-ACCEPTANCE.md), [Issue #8](https://github.com/herogamee/PAN/issues/8).
- **IMPORTANT — Live acceptance PENDING:** Synthetic 4/5 fixture ≠ actual authenticated Buyer API response. If Buyer Detail lacks complete rows, leave them unresolved; do not claim user's live order has already become five units. The rendered order-page fallback, if needed, requires independent research/testing and no anti-fraud bypass.

## PAN v2.5.10 — ป้องกันข้อมูลจำนวนสินค้าที่ผู้ใช้ยืนยัน

- **ปัญหาที่ตรวจจากฐานส่วนตัว:** เมื่อแก้ตัวอ่าน API ในรุ่นก่อน ฐานเดิมไม่ถูกแก้เอง ข้อมูลใน `order_items` ยังอาจมีจำนวนต่ำกว่าที่เจ้าของบัญชียืนยัน; ต้องแยกการยืนยันโดยผู้ใช้จากหลักฐาน API และไม่เขียนทับ/แต่งราคาขึ้นใหม่
- **Source ทำเสร็จ:** Orders inline panel แสดงป้ายว่ารายการที่ `import_source=pan_user_attested_quantity` เป็นจำนวนที่ผู้ใช้ยืนยัน พร้อมซ่อนราคาต่อชิ้น/มูลค่าบรรทัดซึ่งยังไม่ยืนยัน; Full/Recent Sync ที่อาจเขียนทับรายการนี้จะหยุดก่อนแก้ข้อมูล และอนุญาตเฉพาะ single-order recheck ที่มี SKU / variant / จำนวนตรงกับหลักฐานทั้งหมด
- **Source ปลอดข้อมูลส่วนตัว:** ไม่มีเลขคำสั่งซื้อจริง, ข้อมูลผู้ซื้อ, ZIP/SQLite/credentials หรือสคริปต์ user-specific ใน GitHub; เครื่องมือแก้เฉพาะ Order และสำเนาฐานข้อมูลส่วนบุคคลส่งตรงให้เจ้าของบัญชีแยกต่างหาก
- **ทดสอบ:** [Source commit](https://github.com/herogamee/PAN/commit/9e3017f2c67efcf424d5d314b545c289e9e6c9cc) · [CI 5 งานผ่าน](https://github.com/herogamee/PAN/actions/runs/37971871137) · [Release notes](RELEASE-NOTES-v2.5.10.md)
- **คงค้าง:** ชื่อตัวเลือกที่ขาด และเงินต่อหน่วย/ส่วนลดของสินค้าเพิ่มเติม ต้องยืนยันจาก Shopee Buyer ที่ได้รับอนุญาตก่อนนำไปทำราคา/Analytics ที่ถือเป็นข้อมูลจริง; ฐานจริงไม่ถือว่า Live API verified จากการป้อนจำนวนโดยผู้ใช้

## PAN v2.5.9 — จำนวนชิ้นและตัวเลือกสินค้าไม่ตรง

- **บั๊กที่ยืนยันจาก Source:** ตัวอ่านจำนวน `amount ?? quantity ?? 1` สมมติเป็น 1 เมื่อไม่มีฟิลด์; `product_key` เดิมชนกันได้เมื่อสินค้าเดียวกันมีหลายตัวเลือกที่ไม่มี `model_id` จนฐานข้อมูลเขียนทับกัน
- **แก้แล้ว:** อ่านจำนวนเต็มจากฟิลด์ที่ระบุชัด, แยก variant ด้วย key คงที่, รวมจำนวนจากกลุ่มสินค้าเดียวกันที่ยืนยันเป็น SKU เดียวกัน, หากข้อมูลขาดหรือขัดแย้งให้หยุดโดยไม่แก้ checkpoint หรือฐานข้อมูล
- **เครื่องมือตรวจเฉพาะ Order:** Shopee Connector 2.4.14 ให้ระบุเลขออเดอร์และจำนวนชิ้นที่ผู้ใช้ตรวจจาก Shopee; อัปเดตเฉพาะเมื่อ API ให้ข้อมูลครบตรงกับจำนวนที่ระบุและบัญชี Shopee ถูกต้อง **ไม่มีการแก้ข้อมูลเก่าอัตโนมัติ**
- **หลักฐาน:** [Source commit](https://github.com/herogamee/PAN/commit/e97fe24b864f3b74bf64ef7c9d394b355a6bc0a4) · [CI 5 งานผ่าน](https://github.com/herogamee/PAN/actions/runs/37965956961) · [Acceptance](docs/PAN-v2.5.9-QUANTITY-ACCEPTANCE.md) · [Release Notes](RELEASE-NOTES-v2.5.9.md)
- **รอตรวจจริง:** สถานะการแก้ไขคำสั่งซื้อที่มีจำนวนผิดของผู้ใช้ยัง **PENDING** จนกว่า Buyer API ของบัญชีนั้นยืนยันข้อมูลสินค้าแต่ละตัวเลือกและจำนวนได้จริง; ห้ามกรอกเลขออเดอร์หรือ raw JSON จริงใน Public GitHub

## PAN v2.5.8 — ขยายรายการสินค้าของแต่ละคำสั่งซื้อ

- **Source แล้ว:** หน้า `page=orders` คลิกที่เลข Order หรือแถวเพื่อดูสินค้าใน Order นั้นพร้อมรูป ตัวเลือก จำนวน ราคา แล้วกดซ้ำยุบได้
- **ประสิทธิภาพ:** ใช้ Query แบบอ่านอย่างเดียวครั้งเดียวเฉพาะ Order ID ที่ปรากฏในหน้าตัวกรอง/แบ่งหน้า ไม่เรียก Shopee API เพิ่มและไม่เปลี่ยนข้อมูลเก่า
- **ความปลอดภัย:** ตรวจ HTTP(S) URL, escape HTML, ป้องกันการเปิดลิงก์อันตราย; ไม่มีข้อมูลสินค้าต้องบอกว่าระบบยังไม่เก็บ ไม่สมมติว่าไม่ได้ซื้อ
- **ผลทดสอบ:** [GitHub CI 5 งานผ่าน](https://github.com/herogamee/PAN/actions/runs/37962361472) · [Source commit](https://github.com/herogamee/PAN/commit/cbe7c57d08da9e1f5f2f9e27f64aa98a80caf187) · [Release Notes](RELEASE-NOTES-v2.5.8.md) · [Acceptance](docs/PAN-v2.5.8-ORDER-EXPANSION.md)
- **รอตรวจ Production:** เทียบกับข้อมูล Shopee ของเจ้าของบัญชีจริงบน staging แยกจากฐานข้อมูลใช้งาน

## PAN v2.5.7 — คำสั่งซื้อเดือนล่าสุดไม่แสดง / Thai Buddhist Date

- **ต้นเหตุที่พบใน Source:** Full Sync เดิมเปลี่ยน `validation_state` ของออเดอร์ที่ไม่พบล่าสุดเป็น `not_seen_full_scan`; หน้าสรุปแสดงเฉพาะ `verified_*` จึงซ่อนออเดอร์ที่ยังมีอยู่ใน DB. เงื่อนไขวันที่แบบ strict ทำให้ออเดอร์ที่ไม่มีวันสร้างจริงไม่เข้ากรองเดือน แม้ PAN เคยพบในเดือนนั้น
- **แก้แล้ว:** Reconcile อ่านเพื่อ **นับ** แต่ไม่เปลี่ยน verification state ของออเดอร์เก่า, คืนการมองเห็นออเดอร์ที่ถูกซ่อน (เฉพาะมี source_account_id), เพิ่มลิงก์ **เคยไม่พบใน Full Sync / ไม่ทราบวันที่สั่งซื้อ / PAN พบหรือซิงก์เดือนนี้** (วันที่ PAN พบ ไม่ใช่วันสั่งซื้อ)
- **วันที่:** UI `วว/ดด/ปปปป พ.ศ. HH:mm` เวลาไทย 24 ชั่วโมง (เมื่อมี timestamp จริง), timestamp มี `Z`/offset แปลง Asia/Bangkok, date-only ยังคง date-only; backend SQL/export เป็น Gregorian ตามเดิม
- **Source/CI:** [Commit PAN 2.5.7](https://github.com/herogamee/PAN/commit/ed6c8815a8a6927301253dee4a1bb88fccd7d1b1) · [CI 5 งานผ่าน](https://github.com/herogamee/PAN/actions/runs/37947009304) · [Release Notes](RELEASE-NOTES-v2.5.7.md) · [Order Visibility acceptance](docs/PAN-v2.5.7-ORDER-VISIBILITY-ACCEPTANCE.md)
- **ยังไม่ผ่านตรวจรับระบบจริง:** ต้องเทียบจำนวน order/สถานะ/ข้อมูลสำรองบนเครื่องผู้ใช้และ Shopee ที่ล็อกอินจริง; **ออเดอร์ที่ถูกลบออกจากฐานข้อมูลจริงจะไม่สามารถคืนได้ด้วยการแก้ UI เพียงอย่างเดียว**

## PAN v2.5.6 — ถอดวันรับพัสดุที่ Buyer API ยังยืนยันไม่ได้

- **ทำเสร็จใน Source:** ถอดคอลัมน์ `วันที่ได้รับพัสดุ` ออกจาก Orders และตัดสถิติ `delivered_at` / `completed_at` ออกจากแหล่งวันที่ใน Analytics; ยังคง `วันที่สั่งซื้อ` และสถานะคำสั่งซื้อแยกความหมายกัน
- **Detail/Repair:** ขาดวันที่รับพัสดุไม่ทำให้ Detail กลายเป็น partial, ไม่บังคับ Repair ซ้ำ; legacy `detail_missing_fields=delivered_at` (เดี่ยวหรือรวมกับ payment/carrier) ถูกละเว้นในคิวและ pending counters แต่ข้อผิดพลาดจริงยังเข้าคิว
- **Raw data:** ไม่ลบ `delivered_at`, `delivery_date_source`, `completed_at` หรือข้อมูลประวัติเดิม และไม่อ้างว่าเป็นเวลาขนส่งถึงผู้รับ
- **หลักฐาน:** [PAN v2.5.6 source](https://github.com/herogamee/PAN/commit/1f65bb01e73de2a27c5f8d47f9591c65b9ec0097) · [GitHub CI ผ่านทุกงาน](https://github.com/herogamee/PAN/actions/runs/37942186241) · [Release Notes](RELEASE-NOTES-v2.5.6.md) · [Delivery retirement policy](docs/PAN-v2.5.6-DELIVERY-FIELD-RETIREMENT.md)
- **ยังไม่ใช่ Production Accepted:** การผ่าน CI จาก synthetic fixtures ไม่ใช่หลักฐานว่า Buyer API มี/ไม่มีฟิลด์ทุกกรณี หรือว่าฐานข้อมูลจริงได้ทดสอบ Backup/Restore แล้ว

## อัปเดตด้านความถูกต้องของ UI — PAN 2.5.5

- **เสร็จใน Source:** ถอดคอลัมน์/ตัวกรองและ Analytics ที่อ้างชื่อบริษัทขนส่ง โดยไม่ลบข้อมูลดิบเดิมของผู้ใช้; ช่องทางชำระเงินถูกถอดไว้ตั้งแต่ v2.5.4
- **Repair:** ไม่มีชื่อบริษัทขนส่งไม่ทำให้ Detail กลายเป็น partial โดยตัวมันเอง; แยกหลักฐานวันนำส่งพัสดุจากชื่อบริษัทอย่างชัดเจน; รายการเก่าที่เหลือแต่รหัส/ชื่อที่ Buyer API ไม่ยืนยันไม่เข้า Repair ซ้ำโดยไม่จำเป็น
- **หลักฐานโค้ด:** [commit dfa0b8a](https://github.com/herogamee/PAN/commit/dfa0b8ab9e207a4a54136a855b6b814b12d327b6) · [CI ผ่าน 5 งาน](https://github.com/herogamee/PAN/actions/runs/37938489873) · [Shipping acceptance policy](docs/PAN-v2.5.5-SHIPPING-ACCEPTANCE.md)
- **ยังไม่ผ่าน Production Acceptance:** ต้องตรวจ Buyer API กับบัญชี Shopee จริง รวมถึงข้อมูลเดิม/Restore บน staging ก่อนใช้งานจริง ไม่ใช้ Seller Open Platform เป็นหลักฐานยืนยันสิทธิ์ Buyer API

## ศูนย์กลางติดตามงาน

- [GitHub Issues — เปิดอยู่](https://github.com/herogamee/PAN/issues)
- [Acceptance & Test Matrix](docs/ACCEPTANCE-MATRIX.md) — Test case, เกณฑ์ผ่าน, ผลจริง และหลักฐาน
- [Staging Acceptance Runbook](docs/STAGING-ACCEPTANCE.md) — ขั้นตอนทดสอบ XAMPP3/Ubuntu และเก็บผลอย่างปลอดภัย
- [PAN 2.5.9 Release Notes](RELEASE-NOTES-v2.5.9.md) — ขอบเขต release ล่าสุดและข้อจำกัด
- [GitHub CI](.github/workflows/ci.yml) — PHP lint, Shopee Extension regression, Server Connector regression

## สรุป 7 เฟส

| เฟส / Issue | งาน | ความสำคัญ | สถานะ ณ วันที่อัปเดต | เงื่อนไขก่อนปิด |
|---|---|---|---|---|
| [1 — #1](https://github.com/herogamee/PAN/issues/1) | Production Acceptance & Data Integrity | **P0** | **Code/CI ผ่าน; Live/Staging ยังไม่ผ่านการตรวจรับ** | Shopee session จริง + SQLite/MySQL + safe recovery |
| [2 — #2](https://github.com/herogamee/PAN/issues/2) | Order Detail / Repair 3.0 | **P0** | **ทำบางส่วน** | ซ่อนช่องทางชำระเงินและชื่อบริษัทขนส่งที่ Buyer API ไม่ยืนยัน; ถอดวันรับพัสดุที่ไม่มีข้อมูลยืนยัน; ตรวจ Repair/วันที่สั่งซื้อจริง |
| [3 — #3](https://github.com/herogamee/PAN/issues/3) | Product Explorer / Price Intelligence 3.0 | P1 | **มี Foundation แล้ว** | จับคู่/merge/split ปลอดภัย + filters/sorts ผ่าน |
| [4 — #4](https://github.com/herogamee/PAN/issues/4) | Shopee Server Connector | P1 | **Experimental** | ผ่าน Windows/Ubuntu + persistent session + error isolation |
| [5 — #5](https://github.com/herogamee/PAN/issues/5) | Multi-Marketplace Core | P1 | **ยังไม่เริ่ม Migration หลัก** | Composite identity, SQLite/MySQL migration/rollback |
| [6 — #6](https://github.com/herogamee/PAN/issues/6) | Additional Marketplace Connectors | P2 | **ยังไม่เริ่ม Adapter** | API/access feasibility + pilot หลัง Phase 5 |
| [7 — #7](https://github.com/herogamee/PAN/issues/7) | Security / Monitoring / Backup | **P0** | **ทำบางส่วน** | Login throttle implemented; proxy trust, redaction, monitoring, restore rehearsal pending |

คำว่า **ทำบางส่วน** หมายถึงมี code หรือพื้นฐานใน repository; ไม่ใช่ production accepted. ทุก Issue มีรายการงานย่อยและข้อกำหนดการทดสอบที่ใช้พิจารณาปิดงาน

## ลำดับทำงาน / Dependencies

### ช่วง A — ความถูกต้องก่อนใช้งานจริง (P0)
1. **Phase 1 (#1)**: ตั้ง staging โดยใช้สำเนาฐานข้อมูลที่มีสิทธิ์, ตรวจ PHP PDO, ซิงก์ออเดอร์จริงแบบไม่ปะปนบัญชี, Repair, cancellation, checkpoint, verify/rollback
2. **Phase 2 (#2)**: ยืนยัน field mapping ชำระเงิน/ขนส่ง/เลขติดตาม/เวลาจาก session จริง; หาก Shopee ไม่ส่งค่า ต้องบอกว่าไม่ทราบ ห้ามเดาค่า
3. **Phase 7 (#7)**: แก้จุดเสี่ยงด้าน login rate limit/secret logs, ระบบดู health และซ้อมกู้คืน backup (ทำคู่กับ Phase 1)
4. **Gate A:** ไม่ประกาศ Production Accepted จนรายการ P0 ที่เกี่ยวข้องตรวจรับแล้วและไม่มีข้อมูลออเดอร์สูญหาย/ปะปน

### ช่วง B — เพิ่มคุณภาพและความทนทาน
5. **Phase 3 (#3)**: ตรวจ Product Explorer กับข้อมูลจริง ก่อนพัฒนา manual merge/split ที่ undo ได้; ต้องรักษา order snapshots
6. **Phase 4 (#4)**: ทดสอบ Server Connector บน Windows XAMPP3/Ubuntu; ป้องกัน access denied/anti-fraud และรักษา session/profile โดยไม่หลบระบบยืนยัน

### ช่วง C — เปิดหลาย Marketplace อย่างปลอดภัย
7. **Phase 5 (#5)**: เปลี่ยนการระบุตัวตนของออเดอร์จาก global `order_no` เป็น identity ที่แยก platform/account ก่อน; migration แบบ reversible
8. **Phase 6 (#6)**: ตรวจสิทธิ์/API ของ Lazada, TikTok Shop, LINE Shopping **แยก seller API กับ buyer history**; เลือก pilot เฉพาะที่เข้าถึงอย่างได้รับอนุญาตและหลัง Phase 5 ผ่าน
9. **Gate C:** ห้ามเปิดใช้งาน Marketplace ใหม่ใน production ก่อน identity migration และ regression ของ Shopee ผ่าน

> การศึกษาความเป็นไปได้ของ Phase 6 ทำล่วงหน้าได้ แต่ไม่ควรต่อ Adapter เข้าฐานข้อมูลจริงก่อน Phase 5 ผ่าน

## Release Gate: Definition of Done

ใช้ครบทุกข้อที่เกี่ยวข้องก่อนปิด Issue หรือเลื่อน release เป็น Stable:

- [ ] ระบุ commit SHA, version PAN/Connector, environment และ rollback path
- [ ] PHP syntax ผ่าน และ Node regression ที่เกี่ยวข้องผ่านใน CI บน commit เดียวกัน
- [ ] ข้อมูล import/reconcile ไม่ข้ามบัญชี, ไม่ทิ้ง order เมื่อ schema ไม่รู้จัก และไม่แสดง false Complete
- [ ] ถ้าแตะฐานข้อมูล: test ทั้ง SQLite / MySQL เท่าที่รองรับ พร้อม backup, migration verification, idempotence และ restore rehearsal
- [ ] ถ้าแตะ Shopee endpoint/session: ทดสอบด้วยบัญชีที่ได้รับอนุญาต; บันทึกแยกว่าผ่าน/ติดสิทธิ์/ยังไม่ทดสอบ
- [ ] UI แสดง `unknown / pending / partial / error / complete` ตามสถานะจริงและไม่มีข้อมูลแต่งเติม
- [ ] Evidence (sanitized) อยู่ใน [Acceptance Matrix](docs/ACCEPTANCE-MATRIX.md) และ Issue
- [ ] ห้าม Commit `storage/config.php`, DB/SQLite จริง, cookies, OTP, session profiles, buyer identifiers หรือ credentials
- [ ] ก่อนปล่อย ZIP/patch: `VERSION` ต้องตรง target, เปรียบเทียบกับ Complete ที่ทดสอบแล้ว และตรวจว่าการติดตั้งจาก exact base สำเร็จ

### สถานะที่ใช้ในแผน

| สถานะ | ความหมาย |
|---|---|
| Not started | ยังไม่เริ่ม implementation; อาจมีเพียงแนวคิด |
| Foundation/Partial | มี source ที่ครอบคลุมบางส่วน แต่ยังไม่ครบเกณฑ์ |
| Experimental | ต้องพิสูจน์ด้าน environment, session หรือ platform |
| Code/CI pass, acceptance pending | ผ่าน unit/static/CI แต่ยังไม่ผ่านทดสอบปลายทางจริง |
| Accepted | ทุกเกณฑ์ผ่านจริง มี evidence และ rollback |

## วิธีดำเนินงานร่วมกันผ่าน GitHub

1. ทุกงานใหม่สร้าง Issue ระบุ **Phase, change scope, data safety, checklist, acceptance case ID** จาก [Issue Template](.github/ISSUE_TEMPLATE/pan-task.md)
2. ทุก pull request ระบุ Issue, regression cases, DB/API compatibility และ rollback จาก [PR Template](.github/pull_request_template.md)
3. ลงผลแต่ละรอบใน [Acceptance Matrix](docs/ACCEPTANCE-MATRIX.md) โดยแยก **CI Passed** ออกจาก **Live Passed**
4. Merge เข้าสาขา `main` เฉพาะเมื่อ review/test ผ่านตาม scope; เปลี่ยนสถานะ ROADMAP หลังมีหลักฐาน **ห้ามติ๊กครบจากความคาดเดา**
5. อัปเดต Release Notes และกำหนดเวอร์ชันถ้ามีการเปลี่ยน source/runtime behavior; เอกสารอย่างเดียวไม่เพิ่มเวอร์ชันโปรแกรม

## ขอบเขตที่ยังไม่ยืนยัน

- Shopee buyer/private API อาจเปลี่ยนและมีข้อจำกัดรายบัญชี/เซสชัน จึงไม่รับรองข้อมูล Payment/Shipping/Tracking จนตรวจบน session จริง
- Server Connector ที่ผ่าน fixture ไม่ยืนยัน Shopee ยอมรับ IP/automation ใน production
- ยังไม่มีหลักฐานใน repo ว่าได้ตรวจ data-integrity transaction บน DB ที่มีข้อมูลจริงทั้ง SQLite และ MySQL แล้ว
- ช่วงเวลาเสร็จของแต่ละเฟส **ยังไม่ได้กำหนด**; ให้รายงานตาม gate จริง ไม่เดาร้อยละความคืบหน้า
