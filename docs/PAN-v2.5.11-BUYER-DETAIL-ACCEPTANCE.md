# PAN v2.5.11 — Buyer Order Detail item reconciliation acceptance

**Gate: implementation only. Real authenticated Shopee Buyer API response and staging historical DB checks remain pending.** Never publish raw buyer JSON, order IDs, address, recipient details or session tokens.

## Source and data contract

| Test | Must happen | State |
| --- | --- | --- |
| 4 purchased rows x (1,1,2,1) | Four independent `order_items` with total **5 units** | Synthetic Node/PDO tests |
| Two rows have identical SKU/model/variant/price | Do not collapse the rows into one | Synthetic Node tests |
| Condensed Order List has only 2 pieces | Buyer Detail complete 4/5 supersedes **before first upsert** | Synthetic Node tests |
| Detail has only metadata | Do not claim complete; report preview/unknown and preserve higher-quality saved data | Synthetic Node/PDO tests |
| Buyer Detail conflicts with announced item count | Fail closed; no partial import/checkpoint | Synthetic Node tests |
| Account switches mid-collection | Abort before posting unrelated data | Synthetic Node tests |
| Existing buyer-detail-verified rows | Any later condensed/shorter snapshot fails closed | Synthetic PDO SQLite/MariaDB tests |
| User-attested quantity 5 with 3 provisional rows | Complete Buyer Detail with 4 rows and quantity 5 replaces provisional rows automatically | Synthetic PDO SQLite/MariaDB tests |
| Order-level vouchers/coins | Not silently allocated as authoritative item unit prices | Source contract / UI review |
| Full/Recent Sync | Detail-first for each itemized order; account-scoped, pausable, original status/cancelled behavior unchanged | Source + fixture; live pending |
| Targeted refresh | Only order number, no expected item count | Source + fixture; live pending |

## Live acceptance (must not mark passing without evidence)

- [ ] On the original authenticated buyer account, inspect an order page which visibly has four lines and five units. Confirm each line's identity, variant, quantity and per-item source price.
- [ ] Record **only redacted field-name paths and aggregate counts** from the live Buyer Detail JSON; determine if the endpoint actually returns the four full lines or metadata-only.
- [ ] If source is itemized, sync through Connector and verify PAN `order_items` counts and quantities on a private staging DB. If not itemized, mark **BLOCKED BY BUYER API SCHEMA**, not complete.
- [ ] Check repeated same item-model rows remain independent and no unrelated orders change.
- [ ] Check stale preview does not replace the verified rows; duplicate/unknown/mixed account records are rejected.
- [ ] Verify both SQLite and MariaDB and full rollback from backups using a private historical dataset.
- [ ] Confirm no private data (Order ID, recipient or token) went to Public GitHub, CI logs or screenshots.

**Note:** Shopee seller Open Platform fields are not the same API as buyer purchase-history endpoints. Authorization on one does not prove access to the other.
