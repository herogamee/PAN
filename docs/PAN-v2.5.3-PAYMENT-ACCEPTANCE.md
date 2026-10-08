# PAN 2.5.3 — Payment Method Verification

This document records the specific PAN UI bug reported on 2026-10-08.

## Proven from current source
- Shopee `payment_method` may contain an opaque code such as `6` or `92`. Those are **NOT** order amounts, payment success statuses, or established provider names.
- The column must be called **ช่องทางการชำระเงิน**, including Orders, Filters and Analytics.
- Human-readable descriptive names from the same Detail payload take precedence over numeric code values.
- If only a numeric code is present, display **ยังไม่ทราบช่องทาง (รหัส Shopee N)** and keep the Detail as unresolved.
- Legacy numeric rows marked complete can be included in the **manual** Repair queue.

## Safe acceptance checklist
- [ ] Capture a consented sample of method-name fields from the real authenticated Shopee Order Detail response, **with personal identifiers and secrets removed before any public issue**.
- [ ] Confirm which human-readable method corresponds to code `6` and `92`, if the API actually provides supporting evidence; leave the codes unmapped otherwise.
- [ ] Validate the visible values in Orders, payment filter, Analytics and Detail status after upgrading both PAN and Connector.
- [ ] Run synthetic PDO SQLite/MariaDB tests for partial/unresolved payment method queue conditions.
- [ ] Test on a staging copy before upgrading production.

## Current evidence
- PHP payment label fixture test PASS locally.
- Shopee extension parser fixtures PASS 33/33 locally.
- PHP syntax PASS locally.
- Local PHP environment has no PDO SQLite/MySQL drivers, so CI and staging tests remain necessary.
- Live Shopee buyer/private API mapping and production DB compatibility are **not verified**.

See [Phase 2](https://github.com/herogamee/PAN/issues/2) and [Release Notes v2.5.3](../RELEASE-NOTES-v2.5.3.md).
