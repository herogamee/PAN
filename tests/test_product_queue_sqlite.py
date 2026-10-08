"""Python stdlib SQL regression for PAN category-queue pagination.

This exercises the SQL text embedded in api/product_queue.php against in-memory
SQLite. Production PHP PDO integration is still required before release acceptance.
"""
from __future__ import annotations

import re
import sqlite3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = (ROOT / 'api' / 'product_queue.php').read_text(encoding='utf-8')


def embedded_sql() -> tuple[str, str, str]:
    base = re.search(r'\$sql="(SELECT p\.\* FROM \(.*?\) p)";', PHP, re.S)
    cursor = re.search(r'\$sql\.="( WHERE \(p\.last_order_id < \?.*?\)\))";', PHP, re.S)
    order = re.search(r'\$sql\.="( ORDER BY p\.last_order_id DESC.*? LIMIT )"\.\(\$limit\+1\);', PHP, re.S)
    assert base and cursor and order, 'Cannot extract production SQL from api/product_queue.php'
    return base.group(1), cursor.group(1), order.group(1)


class CategoryQueueTest(unittest.TestCase):
    def setUp(self) -> None:
        self.db = sqlite3.connect(':memory:')
        self.db.row_factory = sqlite3.Row
        self.db.executescript('''
            CREATE TABLE orders(id INTEGER PRIMARY KEY,source_account_id TEXT,list_type INTEGER);
            CREATE TABLE order_items(order_id INTEGER,marketplace_shop_id TEXT,
                marketplace_item_id TEXT,product_name TEXT,product_family_key TEXT,
                marketplace_category_name TEXT);
            INSERT INTO orders VALUES(100,'A',3),(99,'A',3),(98,'A',3),(97,'A',3),(150,'B',3);
            INSERT INTO order_items VALUES
                (100,'shop','1','Fail first','fail',''),
                (99,'shop','2','Success','ok',''),
                (98,'shop','3','Later','later',''),
                (97,'shop','4','Last','last',''),
                (150,'other','100','Other account','other','');
        ''')

    def tearDown(self) -> None:
        self.db.close()

    def page(self, aid='A', limit=2, cursor=None) -> tuple[list[sqlite3.Row], dict | None]:
        base, after_sql, order = embedded_sql()
        params = [aid]
        if cursor is not None:
            base += after_sql
            params.extend([cursor['after_order_id'], cursor['after_order_id'],
                           cursor['after_shop_id'], cursor['after_shop_id'], cursor['after_item_id']])
        rows = self.db.execute(base + order + str(limit + 1), params).fetchall()
        has_more = len(rows) > limit
        rows = rows[:limit]
        last = rows[-1] if rows else None
        next_cursor = ({'after_order_id': last['last_order_id'], 'after_shop_id': last['shop_id'],
                        'after_item_id': last['item_id']} if has_more and last else None)
        return rows, next_cursor

    def test_failed_item_is_not_repeated_and_success_does_not_skip_later_items(self) -> None:
        first, cursor = self.page()
        self.assertEqual([r['item_id'] for r in first], ['1', '2'])
        self.assertIsNotNone(cursor)
        self.db.execute("UPDATE order_items SET marketplace_category_name='Updated' WHERE marketplace_item_id='2'")
        second, cursor2 = self.page(cursor=cursor)
        self.assertEqual([r['item_id'] for r in second], ['3', '4'])
        self.assertIsNone(cursor2)

    def test_account_boundaries(self) -> None:
        a, _ = self.page('A', limit=100)
        b, _ = self.page('B', limit=100)
        self.assertEqual({r['item_id'] for r in a}, {'1', '2', '3', '4'})
        self.assertEqual([r['item_id'] for r in b], ['100'])

    def test_cursor_tie_break_on_shop_and_item(self) -> None:
        self.db.execute("INSERT INTO order_items VALUES(100,'shop','5','Same order','same','')")
        rows, cursor = self.page(limit=1)
        self.assertEqual(rows[0]['item_id'], '1')
        after, _ = self.page(limit=1, cursor=cursor)
        self.assertEqual(after[0]['item_id'], '5')

    def test_cancelled_account_count_does_not_use_undefined_batch_variable(self) -> None:
        db_text = (ROOT / 'app' / 'db.php').read_text(encoding='utf-8')
        function = db_text.split('function delete_cancelled_orders(', 1)[1].split('function reconcile_account_scan(', 1)[0]
        self.assertNotIn('$batchAccountId', function)
        self.assertIn('pan_account_verified_purchase_count($db,$accountId)', function)

    def test_version_contract(self) -> None:
        import json
        self.assertEqual((ROOT / 'VERSION').read_text().strip(), '2.5.1')
        self.assertEqual(json.loads((ROOT / 'connectors/shopee-extension/manifest.json').read_text())['version'], '2.4.9')
        self.assertIn("define('HUB_VERSION', '2.5.1')", (ROOT / 'app/config.php').read_text())
        self.assertIn("const VERSION='2.4.9'", (ROOT / 'connectors/shopee-extension/background.js').read_text())


if __name__ == '__main__':
    unittest.main()
