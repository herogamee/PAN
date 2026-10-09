# PAN — Roadmap & Release Gates

> อัปเดต 2026-10-09 (Asia/Bangkok) · **PAN Core v2.5.6 + Shopee Connector v2.4.13** · Implementation baseline: [9837673](https://github.com/herogamee/PAN/commit/98376735244058ec1517e04de8eef90ebe7b97f0) before this documentation update.
>
> **สำคัญ:** Source อยู่บน GitHub และ [CI ผ่าน](https://github.com/herogamee/PAN/actions/runs/37746656907) แต่ **ยังไม่มีหลักฐานผ่าน Production Acceptance**. สถานะเฟสต่อไปนี้เป็นแผนงานและการตรวจรับ ไม่ใช่คำยืนยันว่าใช้งานกับ Shopee จริงสำเร็จแล้ว

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
- [PAN 2.5.5 Release Notes](RELEASE-NOTES-v2.5.5.md) — ขอบเขต release ล่าสุดและข้อจำกัด
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
