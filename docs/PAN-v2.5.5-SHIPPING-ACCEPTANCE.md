# PAN v2.5.5 — Shipping carrier access & display acceptance

**Status: Buyer API carrier-name availability NOT VERIFIED.** Phase 2 remains OPEN.

## API distinction (do not infer buyer access from seller docs)

| Provider/API | What is known | PAN access | Policy |
| --- | --- | --- | --- |
| Shopee Seller Open Platform v2 `order.get_order_detail` | `shipping_carrier` / `logistics_channel_name` are documented in seller logistics flows; use seller credentials and permissions | Not the buyer-side source | Do not treat as buyer carrier proof |
| Shopee TH logged-in Buyer Web API `/api/v4/order/get_order_detail` | PAN calls this and tries several nested carrier-name candidates; private fields vary by account/session/order | PAN's current connector | No verified consistent readable carrier name |

References to seller Open Platform announcements and open-source client definitions
are only useful as *seller API* examples, **not** permission or schema assurance
for buyer purchase history. No private, unrelated account details should be
uploaded to the repo.

## Display rules

- Remove carrier labels, filter, and carrier-only Analytics from the user-facing
  Orders dashboard. Never show `Shopee ไม่มีข้อมูล` as a meaningless column.
- Retain the raw `shipping_carrier` database column and known stored values;
  do not guess missing provider names or replace existing names with blanks.
- Preserve **tracking number** and **courier-delivered timestamp** acquisition
  separately. Order `Complete` does not prove courier delivery.
- Carrier absence by itself is not a Repair or `detail_state=partial` reason.
  Completed orders missing *verified delivery evidence* remain partial/retryable.
- Old partial rows where `detail_missing_fields` consists solely of carrier
  and/or payment name are excluded from automatic/default Repair and pending
  status counts, but remain unchanged in raw database until a legitimate update.

## Regression gates

- [ ] PHP lint all changed PHP files (CI)
- [ ] PHP `tests/order-ui.php`, `tests/order-timeline.php` (CI)
- [ ] Node extension tests incl carrier-missing + tracking/delivered fixtures (CI)
- [ ] Real PDO SQLite/MySQL tests (CI disposable database): carrier-only absent,
      no historic carrier loss, legacy obsolete partial queue excluded (CI)
- [ ] Live authorized Buyer session: verify what fields are present and their
      consistency without publishing order/buyer identities or raw API responses
- [ ] Staged upgrade/rollback on a copy of the user's PAN historical database
- [ ] Only if live evidence is sufficient: consider reinstating a provider name
      in the UI in a subsequent reviewed release

## Audit

Previous baseline: Core v2.5.4 + Connector v2.4.11. Acceptance status on
release candidate should cite the exact GitHub Actions run on the commit.
Never mark production accepted solely on passing synthetic tests.
