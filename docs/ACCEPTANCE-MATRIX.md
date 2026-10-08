# PAN — Acceptance & Test Matrix

> อัปเดต 2026-10-08 · baseline: **PAN 2.5.3 / Shopee Connector 2.4.10** · [Roadmap](../ROADMAP.md).
>
> **อย่าสับสน**: automated CI ผ่าน หมายถึง source/tests ที่รันได้ผ่านเท่านั้น **ไม่ได้** หมายถึงผ่าน Shopee live API, browser login, ข้อมูลร้านจริง หรือ production DB.

## หลักฐานที่ยืนยันแล้ว

| ID | รายการ | สถานะ | หลักฐาน |
|---|---|---|---|
| AUTO-01 | PHP syntax — project files | **PASS (CI)** | [Successful GitHub Actions run](https://github.com/herogamee/PAN/actions/runs/37746656907) |
| AUTO-02 | Shopee Extension regression | **PASS (CI)** | [Same CI run](https://github.com/herogamee/PAN/actions/runs/37746656907) |
| AUTO-03 | Server Connector regression + installed Playwright Chromium fixture | **PASS (CI)** | [Same CI run](https://github.com/herogamee/PAN/actions/runs/37746656907) |
| AUTO-06 | Login throttle / CSRF security tests | **PASS (synthetic CI)** | [Passed workflow](https://github.com/herogamee/PAN/actions/runs/37754666248) · [Login tests](../tests/login-throttle.php) |
| AUTO-07 | PDO SQLite + disposable MariaDB integration / rollback / migration | **PASS (synthetic CI)** | [Passed workflow](https://github.com/herogamee/PAN/actions/runs/37754666248) · [DB integration tests](../tests/db-integration.php) |
| AUTO-04 | Local PHP PDO SQLite/MySQL integration on production-like database | **NOT VERIFIED** | [Local build limitations](LOCAL-VALIDATION-v2.5.1.txt) |
| AUTO-05 | Live Shopee buyer API / payment/logistics/category via a real user session | **NOT VERIFIED** | [Handoff limitations](PAN-v2.5.1-HANDOFF.md) |

## Pending manual / integration acceptance

| ID | Issue | Scenario | วิธีพิสูจน์ / เงื่อนไขผ่าน | ผลจริง |
|---|---|---|---|---|
| PAN-P1-01 | [#1](https://github.com/herogamee/PAN/issues/1) | Staging backup/restore/upgrade 2.5.0→2.5.1 | Snapshot ก่อนอัปเกรด, rollback แล้วนับ rows/relationships ตรง | Pending |
| PAN-P1-02 | [#1](https://github.com/herogamee/PAN/issues/1) | SQLite/MySQL real PHP PDO transaction | Import/reconcile rollback and repeat on both drivers; FK/count correct | Pending |
| PAN-P1-03 | [#1](https://github.com/herogamee/PAN/issues/1) | Recent/Full Sync with authorized logged-in account | Count unique vs PAN, no duplicate, correct account, resume checkpoint intact | Pending |
| PAN-P1-04 | [#1](https://github.com/herogamee/PAN/issues/1) | Account switch, unknown/mixed schema, anti-fraud | No cross-account write or unsafe checkpoint on failure | Pending |
| PAN-P1-05 | [#1](https://github.com/herogamee/PAN/issues/1) | Cancelled order + changed item snapshot | Cancelled purchase excluded, stale items reconciled only on verified complete snapshot | Pending |
| PAN-P1-06 | [#1](https://github.com/herogamee/PAN/issues/1) | Category queue across pages, fail one item | Later items processed, no infinite repeats, Order Sync unaffected | Pending |
| PAN-P2-00 | [#2](https://github.com/herogamee/PAN/issues/2) | Numeric Shopee payment codes 6/92 | Code now treats numeric methods as unknown, favors names in Detail and leaves manual Repair available; meaning of codes remains unverified | Pending live evidence |
| PAN-P2-01 | [#2](https://github.com/herogamee/PAN/issues/2) | Live payment / shipping / tracking / timestamps | UI values agree with sanitized real API examples; no invented data | Pending |
| PAN-P2-02 | [#2](https://github.com/herogamee/PAN/issues/2) | Detail state and repair retry | `pending/partial/error/complete` distinguish missing vs failed vs done | Pending |
| PAN-P2-03 | [#2](https://github.com/herogamee/PAN/issues/2) | Multi-page Repair/resume | No skipped pages, no cross-account mutation, failed queue recoverable | Pending |
| PAN-P3-01 | [#3](https://github.com/herogamee/PAN/issues/3) | Product/Shop/Category filters, sorts, KPIs | Count/shop/category/spend/price history match order fixture/staging DB | Pending |
| PAN-P3-02 | [#3](https://github.com/herogamee/PAN/issues/3) | Manual merge/split + variant/unit correctness | No false auto-merge, reversible change and immutable source order history | Not implemented |
| PAN-P4-01 | [#4](https://github.com/herogamee/PAN/issues/4) | Server Connector Windows XAMPP3 + Ubuntu | Profile persists across restart; authorized session sync/repair succeed | Pending |
| PAN-P4-02 | [#4](https://github.com/herogamee/PAN/issues/4) | Scheduled/interactive lock, access rejection | No concurrent profile corruption; 401/403 stop without false success | Pending |
| PAN-P5-01 | [#5](https://github.com/herogamee/PAN/issues/5) | Multi-platform identity migration | Same external order number can exist on different platforms with distinct PAN IDs | Not implemented |
| PAN-P5-02 | [#5](https://github.com/herogamee/PAN/issues/5) | Legacy migration and rollback | SQLite/MySQL transactional, idempotent, no loss of legacy order IDs/refs | Not implemented |
| PAN-P6-01 | [#6](https://github.com/herogamee/PAN/issues/6) | Platform rights/access feasibility | Seller vs buyer capabilities documented with legitimate access evidence | Not started |
| PAN-P6-02 | [#6](https://github.com/herogamee/PAN/issues/6) | One new connector pilot | Separate platform/account scope; no regression to Shopee; Phase 5 passed | Blocked by Phase 5 |
| PAN-P7-01 | [#7](https://github.com/herogamee/PAN/issues/7) | Public login security & redaction | Login rate limit, CSRF/auth denial, no session token/buyer data leaks | Pending |
| PAN-P7-02 | [#7](https://github.com/herogamee/PAN/issues/7) | Production-like backup / restore rehearsal | Restore staging DB/config and compare counts/references; documented rollback | Pending |
| PAN-P7-03 | [#7](https://github.com/herogamee/PAN/issues/7) | Monitoring / sync failure diagnostics | Last successful sync, queue backlog, fatal rejection visible, no silent failure | Not implemented |

## บันทึกการตรวจรับ (เติมต่อหนึ่ง test case)

ใช้รูปแบบนี้ **เฉพาะข้อมูลที่ปกปิดข้อมูลส่วนบุคคลแล้ว** ใน Issue หรือเอกสาร:
```text
Case ID: PAN-P1-03
Release SHA: <commit SHA>
Environment: staging Windows XAMPP3 / PHP version / PDO driver / Node version
Data: synthetic or redacted staging dataset
Observed result: PASS | FAIL | BLOCKED | NOT RUN
Evidence: CI link, synthetic fixture or anonymized summary
Unexpected behavior and recovery: ...
Who/when reviewed: ...
```

### ห้ามแนบ Public Evidence ที่เสี่ยง
- ไม่ใส่ raw Shopee responses ที่มีชื่อผู้ซื้อ เบอร์ โทร ที่อยู่ หมายเลขสั่งซื้อไม่ปิดบัง, tracking ที่ระบุคน, cookies, Authorization, API keys, OTP, profile directories
- ถ้ามีข้อมูลจริงให้เก็บหลักฐานฉบับเต็มในระบบส่วนตัวที่ควบคุมสิทธิ์ และเพิ่มเฉพาะ summary ที่ตัดตัวระบุออกลง GitHub
- ห้ามกรอก `PASS` เพื่อแทน `Not Run`; ถ้าแวดล้อมทดสอบไม่มี DB driver หรือ Shopee บล็อก ให้ใช้ `BLOCKED` พร้อมเหตุผล

## ใช้งานอย่างไร

1. ทำตาม [Staging Acceptance Runbook](STAGING-ACCEPTANCE.md) ก่อนเปิดทดสอบกับบัญชีจริง
2. เพิ่มผลลัพธ์ที่ตรวจได้ใน Issue ของ Phase นั้น และอัปเดตตารางนี้
3. อัปเดต [Roadmap](../ROADMAP.md) เฉพาะเมื่อ gate ผ่านจริง
