#!/usr/bin/env python3
"""Read-only-style SQLite semantics regression, using synthetic rows only.

No user/prod DB opened. PDO integration on CI separately exercises the actual PHP app.
"""
import json
import sqlite3
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parent.parent
php = subprocess.run(
    ["php", "-r", "require 'app/order_timeline.php'; echo json_encode([pan_purchase_visibility_sql('o'),pan_order_placed_sql('o'),pan_order_sort_sql('o')]);"],
    cwd=root, check=True, capture_output=True, text=True,
)
visible_sql, placed_sql, sort_sql = json.loads(php.stdout)
con = sqlite3.connect(":memory:")
con.execute("""CREATE TABLE orders (
 id INTEGER PRIMARY KEY, order_no TEXT, validation_state TEXT, purchase_state TEXT,
 source_account_id TEXT, list_type INTEGER, order_date TEXT, order_created_at TEXT,
 date_source TEXT, created_at TEXT, last_seen_at TEXT)
""")
rows = [
    (1, "O-CURRENT", "verified_v200", "purchase", "A", 3, "2026-10-07", "2026-10-07 21:31:00", "info_card.create_time", "2026-10-07 12:00:00", "2026-10-08 12:00:00"),
    (2, "O-STALE", "not_seen_full_scan", "purchase", "A", 7, "2026-10-08", "2026-10-08 14:20:00", "info_card.order_create_time", "2026-10-08 13:00:00", "2026-10-08 14:00:00"),
    (3, "O-UNKNOWN", "not_seen_full_scan", "purchase", "A", 8, "2026-10-09", "", "shipping.tracking_info.ctime", "2026-10-09 09:00:00", "2026-10-09 10:00:00"),
    (4, "O-SUSPICIOUS", "suspicious_legacy", "purchase", "A", 3, "2026-10-09", "", "info_card.create_time", "2026-10-09 10:00:00", "2026-10-09 12:00:00"),
    (5, "O-LEGACY", "legacy_visible", "review", "A", 3, "2026-10-09", "", "test", "2026-10-09 10:00:00", "2026-10-09 12:00:00"),
    (6, "O-ORPHAN", "not_seen_full_scan", "purchase", "", 3, "2026-10-09", "", "unknown", "2026-10-09 10:00:00", "2026-10-09 12:00:00"),
    (7, "O-UNPAID", "not_seen_full_scan", "non_purchase", "A", 9, "2026-10-09", "2026-10-09 15:00:00", "info_card.create_time", "2026-10-09 10:00:00", "2026-10-09 16:00:00"),
]
con.executemany("INSERT INTO orders VALUES (?,?,?,?,?,?,?,?,?,?,?)", rows)
def nos(sql):
    return [row[0] for row in con.execute(sql)]

def expect(actual, expected, name):
    assert actual == expected, f"FAIL {name}: {actual!r} != {expected!r}"
    print("PASS", name)

expect(nos(f"SELECT order_no FROM orders o WHERE {visible_sql} ORDER BY o.id"),
       ["O-CURRENT", "O-STALE", "O-UNKNOWN", "O-UNPAID"],
       "previously verified stale purchase/unpaid orders visible; suspicious/orphan excluded")
expect(nos(f"SELECT order_no FROM orders o WHERE {visible_sql} AND substr({placed_sql},1,7)='2026-10' ORDER BY o.id"),
       ["O-CURRENT", "O-STALE", "O-UNPAID"], "October purchase-date filter uses verified creation dates even on unpaid rows")
expect(nos(f"SELECT order_no FROM orders o WHERE {visible_sql} AND {placed_sql} IS NULL"),
       ["O-UNKNOWN"], "unknown-date rows remain independently accessible")
expect(nos(f"SELECT order_no FROM orders o WHERE {visible_sql} ORDER BY {sort_sql} DESC,o.id DESC LIMIT 3"),
       ["O-UNPAID", "O-UNKNOWN", "O-STALE"],
       "recent observation-time fallback sorts but does not change purchase dates")
expect(nos("SELECT order_no FROM orders o WHERE substr(o.last_seen_at,1,7)='2026-10' ORDER BY o.id"),
       [x[1] for x in rows], "current-month seen records are discoverable independent of dated filters")
print("PURCHASE VISIBILITY SQLITE PASS")
