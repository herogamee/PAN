# PAN Core v2.5.3 + Shopee Connector v2.4.10 — Payment Method Clarity

## Problem
The Orders table header **ชำระเงิน** suggested payment status rather than the **ช่องทางการชำระเงิน** field. Shopee buyer order details may return numeric `6` / `92` in `payment_method` and PAN rendered them verbatim. This does not provide a reliable mapping to ShopeePay, SPayLater, COD or any other method.

## Changes
1. Rename Orders table heading, filter, advanced filter summary and Analytics chart to **ช่องทางการชำระเงิน**.
2. Numeric-only stored values show **ยังไม่ทราบช่องทาง (รหัส Shopee 6)** or **(รหัส Shopee 92)**, never a fabricated name or a payment-success claim; raw codes remain unchanged in DB for provenance/filtering.
3. Display readable `payment_method_name` / `payment_channel_name` / nested `display_name` from detail before generic numeric `payment_method`. Numeric raw code is retained in `metadata_json.codes.payment_method`; no raw customer/payment session data added.
4. Treat numeric-only payment code as *unresolved* for Detail State and manual Repair queue. A previously complete row with code-only can be retried. No automatic bulk forced fetch.
5. Add JS fixtures for numeric plus readable fields / numeric-only fields; PHP display tests; SQLite and MariaDB integration cases for partial states and queue semantics.

## Compatibility & limits
- No database migration or automatic rewrite of historical orders.
- No guess about the meaning of Shopee private numeric methods 6 and 92. Those still require verification with consent and redacted Shopee order evidence.
- Legacy HTML/UI values are corrected immediately after upgrading PAN; new descriptive methods need Connector v2.4.10 + manual Repair or applicable detail fetch.
- Production Shopee acceptance remains pending. Back up runtime `storage/config.php` and DB before installing; never overwrite live storage.
