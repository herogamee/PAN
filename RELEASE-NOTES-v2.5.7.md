# PAN v2.5.7 — Order Visibility Recovery & Thai Buddhist Date Display

Core **2.5.7**; Chrome Shopee Connector **2.4.13** (unchanged). Target baseline **2.5.6**. This is a *source-only deployment candidate*, not a live Shopee acceptance.

## Why latest orders appeared missing

1. **Destructive verification marker**: earlier Full Sync `reconcile_account_scan()` set `validation_state='not_seen_full_scan'` for rows not in the latest scan. Dashboard, Products, Analytics and purchase counters excluded the marker. **Those rows remained in `orders` but became invisible in summaries.**
2. **Too-strict purchase date filtering** introduced for correctly avoiding paid/delivered/Complete timestamps as purchase dates. Older rows whose `date_source` references another event are intentionally undated, and month/year filters exclude them. The UI did not explain how to find these rows.
3. **Dashboard recents** previously omitted unpaid/refund and previously marked not-seen rows. The monthly chart showed completed purchases only.

> No access to the actual production database or Shopee session was used. These are reproduced **source-level and synthetic-database causes**, not proof that a specific user's order was only hidden. If the raw `orders` row is physically absent, check the source collector/import and private backups.

## Fixes

- **Full Sync reconcile is now read-only for unseen history**: it counts mismatches for diagnostics but does not overwrite prior verification states, dates or order rows.
- Previously flagged purchases (`not_seen_full_scan`, with account ID and `purchase_state=purchase`) remain in the database with their original flag and now become **visible as historical records**, marked “ไม่พบใน Full Sync ก่อนหน้า” where relevant. Suspicious/legacy records are *not* silently promoted to verified.
- Orders page explicitly links to **old not-seen rows**, **orders without a trustworthy purchase date**, and **records PAN saw/synced this month**. The latter is **observed/imported month, NOT purchase month**.
- Default sorting uses genuine purchase date when available, otherwise the observation timestamp **for ordering only**. Display remains “ไม่ทราบวันที่สั่งซื้อ”, never a guessed date.
- Dashboard recents show all imported noncancelled statuses (including shipping, pending, refund) rather than completed only. Dashboard's month chart includes placed purchases in `3/7/8` when a true purchase date exists; a missing date is still omitted from a date-based chart with an explanation.
- Preserves raw ISO dates, order statuses and relational IDs. No automatic “backfill” or rewrite of historical dates. No destructive migration or sync triggered on upgrade.

## Date display policy

- UI: **DD/MM/YYYY พ.ศ. HH:mm**, 24-hour clock, Asia/Bangkok. Example: `09/10/2569 21:30`.
- Date-only is shown **without a fabricated time**: `09/10/2569` with “Shopee ไม่ระบุเวลา”.
- A timestamp carrying `Z` or an explicit offset is converted to Asia/Bangkok. Already-normalized no-offset Shopee Connector dates are treated as local Bangkok times.
- Filter/chart year labels are B.E. years; URL/query/data storage remain Gregorian. Machine exports and API dates remain ISO/Gregorian to preserve data compatibility.

## Verification

- PHP lint, date/timeline/display regressions and SQLite visibility fixture are run locally.
- GitHub Actions runs **disposable SQLite and MariaDB PDO integrations**, extension and browser connector regressions.
- Real purchase counts, actual stale state transitions, and source API correctness still need production-like staging verification before acceptance.

## Safe upgrade from 2.5.6

1. Back up the full PAN app, **runtime** `storage/config.php` and database (including SQLite WAL safely). Keep backups private.
2. Use the exact v2.5.6 Complete tree as baseline and apply this patch, **do not replace the live `storage/` directory**. Or stage the Complete separately.
3. Check `VERSION` = 2.5.7 and extension manifest = 2.4.13; Chrome Extension does not need a version upgrade for this Core-only change.
4. Open **Orders** without any filters; inspect new links for “ไม่พบใน Full Sync ก่อนหน้า”, “ไม่ทราบวันสั่งซื้อ”, and “PAN พบ/ซิงก์เดือนนี้”. Compare account/row counts to pre-upgrade backup.
5. **Do not run a new Full Sync as a recovery step until the existing counts and account identity have been checked.** Recent Sync may repair actual missing rows after backup, provided Shopee login/API is authorized and healthy.
6. If the order is absent even in the raw database, the UI-only recovery cannot restore it. Use a privately held backup / verified importer audit.

See [docs/PAN-v2.5.7-ORDER-VISIBILITY-ACCEPTANCE.md](docs/PAN-v2.5.7-ORDER-VISIBILITY-ACCEPTANCE.md).
