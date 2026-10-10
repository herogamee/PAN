# PAN Shopee Connector v2.4.17 — Pending Queue Reliability

Core stays **PAN 2.5.11**. This patch extends the already-tested Connector v2.4.16 partial-import feature.

- Independent Buyer Detail item lists no longer inherit an ambiguous `product_count` from a condensed order-list preview when Detail omits its own count.
- New Full Sync and checkpoint reset retain unresolved per-account item identities in private Chrome extension local storage rather than dropping that ledger. The queue is not server-side SQL; uninstalling/clearing Chrome extension storage still removes it.
- Active Shopee Buyer account is rechecked immediately before importing preview-only records into PAN.
- Pending-item retry does not mark an uncompleted scan done merely because the queue is empty.
- New Node tests cover preview count isolation, queue persistence, and last-moment account switching.

**Install:** Back up PAN, update only `connectors/shopee-extension` from v2.4.16 to v2.4.17 and reload Chrome Extension. Database and Core remain unchanged. Login/session and Shopee Buyer API coverage still require live authorized acceptance; do not assume the two pending orders are recovered if Detail remains metadata-only. See [Issue #8](https://github.com/herogamee/PAN/issues/8).
