# PAN Shopee Connector 2.4.16 — per-order schema recovery / safe partial sync

PAN Core stays **2.5.11**. This is a Chrome Extension-only hotfix for **v2.4.15**.

## Why a clean database stalled

The buyer order-list parser can return two unreadable Orders in a 20-record page. `product_count_exceeds_snapshot` means the Shopee-reported count exceeds the number of explicitly parsed purchased units; it is **not** proof that Shopee omitted a specific variant. `missing_valid_items` means no lines passed the parser's purchase-item rules; this could have several meanings (different data shape, returned-only items, etc.). The actual Buyer JSON from an authorized account is needed before assigning the underlying cause.

Previously the Extension discarded the **whole** import batch and left the checkpoint unchanged even when the other 18 orders were structurally valid. Clearing PAN's database could therefore make the Collector loop on the same page.

## Safe behavior in 2.4.16

1. Validate each order independently and request its authenticated Buyer Order Detail before deciding an item list is unusable.
2. A valid order imports normally. An order with a known identity but unresolved products goes to a locally persisted **per-account pending item ledger**, without inserting fabricated item rows. Every checkpoint update stores the pending references alongside pagination state.
3. The scan continues past pages with item-level errors. **No page is considered fully accepted merely because some orders were accepted**: final state is explicitly `partial` with the pending count, never a false green `done` state. Full Sync does not call account reconciliation when any pending items remain.
4. The button **ตรวจสินค้าออเดอร์ที่ค้างใหม่** retries those order identities only, against the authenticated Buyer Detail. A complete validated detail may import automatically; metadata-only, conflicting quantity, or unavailable data remains pending, without writing to PAN or resetting the scan cursor.
5. If the account identity changes, a source Order ID is missing, type unknown, or PAN URL is different from the pending ledger, fail closed and do not advance unsafe checkpoints.
6. The copied debug report includes error reason counts and **field names/counts only**, never order numbers, names, product titles, buyer addresses, raw JSON, cookies or tokens.

## Limits

- This does not establish the live Buyer API contract or guarantee that the two remaining orders can be recovered; that requires sanitized, authorized Buyer JSON schema evidence.
- Preview-only imports remain lower-confidence than verified detail snapshots. PAN Core 2.5.11 already prevents a preview from replacing higher-quality item data.
- The temporary pending reference ledger is in private Chrome Extension local storage, not your public GitHub repo. Do not click **ล้าง checkpoint** while pending items remain, unless you intend to rescan.
- No change to PAN database schema; no automatic destructive migration or guessing of order quantities.

## Install / operations

1. Back up PAN's current database and runtime `storage/config.php` (never put these into the ZIP or GitHub).
2. Replace `connectors/shopee-extension` from the patch on the existing installation (exact previous Extension **2.4.15**), or install from the complete package; Core remains **2.5.11**.
3. Go to `chrome://extensions` and press **Reload** for PAN Shopee Connector; verify popup shows v2.4.16.
4. Keep logged into your authorized Shopee TH account, verify PAN URL and API key. If your DB was just emptied, start **ดึงประวัติทั้งหมด** once; do **not** delete the database again.
5. If pending items appear, the valid Orders were imported; use **ตรวจสินค้าออเดอร์ที่ค้างใหม่**. Do not label your history fully synchronized until pending count is zero.
6. If pending remains, use Copy Debug and share **only the already-redacted schema and counts** for further parser investigation.

## Acceptance tests

- [x] Synthetic 20-order page, two invalid reasons (`product_count_exceeds_snapshot`, `missing_valid_items`): 18 valid Orders committed, two private pending references retained, no pending Orders written.
- [x] Full and Recent Sync terminate as `partial`, not complete; Full Sync never calls reconcile with missing Orders.
- [x] Retry with a newly complete Buyer Detail fixes only the two pending entries; no checkpoint reset.
- [x] Retry with metadata-only detail retains pending data and shows incomplete state.
- [x] Reject wrong Shopee account/PAN URL, missing identities, unknown statuses; no unsafe writes.
- [x] Extension Node regression suite and PHP syntax checks on synthetic source.
- [ ] **Production/staging live acceptance:** reconcile actual order lines, purchased units and source paths from the authorized Shopee account. No access to user's live browser session is available here.

Tracking: [GitHub PAN Issue #8](https://github.com/herogamee/PAN/issues/8).
