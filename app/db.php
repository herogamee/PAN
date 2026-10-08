<?php
require_once __DIR__ . '/config.php';

function db_driver(PDO $db): string { return (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME); }

function db_connect(array $cfg): PDO {
    $driver = strtolower((string)($cfg['driver'] ?? 'sqlite'));
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];
    if ($driver === 'sqlite') {
        if (!extension_loaded('pdo_sqlite')) throw new RuntimeException('PHP extension pdo_sqlite ยังไม่เปิดใช้งาน');
        $path = (string)($cfg['path'] ?? (HUB_STORAGE . DIRECTORY_SEPARATOR . HUB_SQLITE_FILENAME));
        if (!str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) $path = HUB_ROOT . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('สร้างโฟลเดอร์ SQLite ไม่สำเร็จ: ' . $dir);
        if (!is_writable($dir)) throw new RuntimeException('Web Server เขียนโฟลเดอร์ SQLite ไม่ได้: ' . $dir);
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA busy_timeout=8000');
        return $pdo;
    }
    if ($driver === 'mysql') {
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PHP extension pdo_mysql ยังไม่เปิดใช้งาน');
        $host = trim((string)($cfg['host'] ?? '127.0.0.1'));
        $port = (int)($cfg['port'] ?? 3306);
        $name = trim((string)($cfg['database'] ?? ''));
        $user = (string)($cfg['username'] ?? '');
        $pass = (string)($cfg['password'] ?? '');
        $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string)($cfg['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
        if ($name === '') throw new RuntimeException('กรุณาระบุชื่อฐานข้อมูล MySQL');
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        $opts[PDO::ATTR_EMULATE_PREPARES] = true;
        $pdo = new PDO($dsn, $user, $pass, $opts);
        $pdo->exec("SET NAMES {$charset}");
        return $pdo;
    }
    throw new RuntimeException('Database driver ไม่รองรับ: ' . $driver);
}

function db_sqlite_path_from_config(array $cfg): string {
    $path = (string)($cfg['path'] ?? (HUB_STORAGE . DIRECTORY_SEPARATOR . HUB_SQLITE_FILENAME));
    if (!str_starts_with($path, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\/]/', $path)) {
        $path = HUB_ROOT . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
    return $path;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!hub_is_installed()) throw new RuntimeException('PAN ยังไม่ได้ติดตั้ง');
    $pdo = db_connect(hub_db_config());
    ensure_schema_v200($pdo);
    return $pdo;
}

function db_table_columns(PDO $db, string $table): array {
    $driver = db_driver($db);
    $out = [];
    if ($driver === 'sqlite') {
        foreach ($db->query('PRAGMA table_info("' . str_replace('"','""',$table) . '")') as $r) $out[(string)$r['name']] = $r;
        return $out;
    }
    $st = $db->prepare('SELECT COLUMN_NAME AS name,DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $st->execute([$table]);
    foreach ($st->fetchAll() as $r) $out[(string)$r['name']] = $r;
    return $out;
}

function db_table_exists(PDO $db, string $table): bool {
    if (db_driver($db) === 'sqlite') {
        $st=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$st->execute([$table]);return (bool)$st->fetchColumn();
    }
    $st=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$st->execute([$table]);return (bool)$st->fetchColumn();
}

function db_index_exists(PDO $db, string $table, string $index): bool {
    if (db_driver($db) === 'sqlite') {
        $st=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND tbl_name=? AND name=?");$st->execute([$table,$index]);return (bool)$st->fetchColumn();
    }
    $st=$db->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? LIMIT 1');$st->execute([$table,$index]);return (bool)$st->fetchColumn();
}

function db_add_index(PDO $db,string $table,string $index,string $columns): void {
    if (!db_index_exists($db,$table,$index)) $db->exec("CREATE INDEX `$index` ON `$table` ($columns)");
}

function db_column_definitions(PDO $db): array {
    if (db_driver($db)==='mysql') {
        return [
          'orders'=>[
            'merchandise_paid'=>'DOUBLE NOT NULL DEFAULT 0','raw_subtotal'=>'DOUBLE NOT NULL DEFAULT 0','discount_total'=>'DOUBLE NOT NULL DEFAULT 0','pricing_method'=>"VARCHAR(64) NOT NULL DEFAULT 'legacy'",
            'list_type'=>'INT NULL','purchase_state'=>"VARCHAR(32) NOT NULL DEFAULT 'review'",'validation_state'=>"VARCHAR(64) NOT NULL DEFAULT 'legacy'",'order_status'=>'VARCHAR(64) NULL',
            'source_account_id'=>'VARCHAR(100) NULL','source_account_username'=>'VARCHAR(255) NULL','date_source'=>'VARCHAR(64) NULL','identity_source'=>'VARCHAR(64) NULL',
            'order_created_at'=>'VARCHAR(32) NULL','paid_at'=>'VARCHAR(32) NULL','delivered_at'=>'VARCHAR(32) NULL','completed_at'=>'VARCHAR(32) NULL','delivery_date_source'=>'VARCHAR(64) NULL',
            'payment_method'=>'VARCHAR(255) NULL','shipping_carrier'=>'VARCHAR(255) NULL','tracking_number'=>'VARCHAR(255) NULL','parcel_count'=>'INT NOT NULL DEFAULT 0',
            'seller_discount'=>'DOUBLE NOT NULL DEFAULT 0','shop_voucher_discount'=>'DOUBLE NOT NULL DEFAULT 0','shipping_discount'=>'DOUBLE NOT NULL DEFAULT 0',
            'detail_enriched'=>'TINYINT NOT NULL DEFAULT 0','detail_state'=>"VARCHAR(24) NOT NULL DEFAULT 'pending'",'detail_attempted_at'=>'VARCHAR(32) NULL','detail_updated_at'=>'VARCHAR(32) NULL','detail_error'=>'TEXT NULL','detail_missing_fields'=>'TEXT NULL','metadata_json'=>'LONGTEXT NULL',
            'last_scan_id'=>'VARCHAR(100) NULL','last_seen_at'=>'DATETIME NULL'
          ],
          'order_items'=>[
            'needs_review'=>'TINYINT NOT NULL DEFAULT 0','raw_text'=>'TEXT NULL','import_source'=>'VARCHAR(100) NULL','original_price'=>'DOUBLE NOT NULL DEFAULT 0','order_status'=>'VARCHAR(64) NULL','raw_json'=>'LONGTEXT NULL','allocated_discount'=>'DOUBLE NOT NULL DEFAULT 0','actual_line_total'=>'DOUBLE NOT NULL DEFAULT 0','actual_unit_price'=>'DOUBLE NOT NULL DEFAULT 0',
            'marketplace_shop_id'=>'VARCHAR(100) NULL','marketplace_item_id'=>'VARCHAR(100) NULL','marketplace_model_id'=>'VARCHAR(100) NULL',
            'marketplace_category_id'=>'VARCHAR(100) NULL','marketplace_category_name'=>'VARCHAR(255) NULL','marketplace_category_path'=>'TEXT NULL','pan_category_name'=>'VARCHAR(255) NULL','category_source'=>'VARCHAR(64) NULL','category_updated_at'=>'VARCHAR(32) NULL',
            'product_family_key'=>'VARCHAR(255) NULL','product_family_name'=>'TEXT NULL'
          ],
          'collector_batches'=>['account_id'=>'VARCHAR(100) NULL','account_username'=>'VARCHAR(255) NULL','job_type'=>"VARCHAR(64) NOT NULL DEFAULT 'sync'"],
          'accounts'=>['account_id'=>'VARCHAR(100) NULL','last_repair_at'=>'VARCHAR(32) NULL']
        ];
    }
    return [
      'orders'=>[
        'merchandise_paid'=>'REAL DEFAULT 0','raw_subtotal'=>'REAL DEFAULT 0','discount_total'=>'REAL DEFAULT 0','pricing_method'=>"TEXT DEFAULT 'legacy'",
        'list_type'=>'INTEGER','purchase_state'=>"TEXT DEFAULT 'review'",'validation_state'=>"TEXT DEFAULT 'legacy'",'order_status'=>'TEXT',
        'source_account_id'=>'TEXT','source_account_username'=>'TEXT','date_source'=>'TEXT','identity_source'=>'TEXT',
        'order_created_at'=>'TEXT','paid_at'=>'TEXT','delivered_at'=>'TEXT','completed_at'=>'TEXT','delivery_date_source'=>'TEXT',
        'payment_method'=>'TEXT','shipping_carrier'=>'TEXT','tracking_number'=>'TEXT','parcel_count'=>'INTEGER DEFAULT 0',
        'seller_discount'=>'REAL DEFAULT 0','shop_voucher_discount'=>'REAL DEFAULT 0','shipping_discount'=>'REAL DEFAULT 0',
        'detail_enriched'=>'INTEGER DEFAULT 0','detail_state'=>"TEXT DEFAULT 'pending'",'detail_attempted_at'=>'TEXT','detail_updated_at'=>'TEXT','detail_error'=>'TEXT','detail_missing_fields'=>'TEXT','metadata_json'=>'TEXT',
        'last_scan_id'=>'TEXT','last_seen_at'=>'TEXT'
      ],
      'order_items'=>['needs_review'=>'INTEGER NOT NULL DEFAULT 0','raw_text'=>'TEXT','import_source'=>'TEXT','original_price'=>'REAL DEFAULT 0','order_status'=>'TEXT','raw_json'=>'TEXT','allocated_discount'=>'REAL DEFAULT 0','actual_line_total'=>'REAL DEFAULT 0','actual_unit_price'=>'REAL DEFAULT 0','marketplace_shop_id'=>'TEXT','marketplace_item_id'=>'TEXT','marketplace_model_id'=>'TEXT','marketplace_category_id'=>'TEXT','marketplace_category_name'=>'TEXT','marketplace_category_path'=>'TEXT','pan_category_name'=>'TEXT','category_source'=>'TEXT','category_updated_at'=>'TEXT','product_family_key'=>'TEXT','product_family_name'=>'TEXT'],
      'collector_batches'=>['account_id'=>'TEXT','account_username'=>'TEXT','job_type'=>"TEXT DEFAULT 'sync'"],
      'accounts'=>['account_id'=>'TEXT','last_repair_at'=>'TEXT']
    ];
}

function db_create_base_schema(PDO $db): void {
    if (db_driver($db) === 'mysql') {
        $db->exec("CREATE TABLE IF NOT EXISTS orders (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          platform VARCHAR(64) NOT NULL DEFAULT 'shopee_th', order_no VARCHAR(100) NOT NULL UNIQUE,
          order_date VARCHAR(10) NOT NULL DEFAULT '', shop_name VARCHAR(255) NULL,
          subtotal DOUBLE NOT NULL DEFAULT 0, shipping_fee DOUBLE NOT NULL DEFAULT 0,
          voucher_discount DOUBLE NOT NULL DEFAULT 0, coins_discount DOUBLE NOT NULL DEFAULT 0, platform_discount DOUBLE NOT NULL DEFAULT 0,
          total_paid DOUBLE NOT NULL DEFAULT 0, merchandise_paid DOUBLE NOT NULL DEFAULT 0, raw_subtotal DOUBLE NOT NULL DEFAULT 0,
          discount_total DOUBLE NOT NULL DEFAULT 0, pricing_method VARCHAR(64) NOT NULL DEFAULT 'legacy',
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS order_items (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, order_id BIGINT UNSIGNED NOT NULL,
          product_key VARCHAR(255) NOT NULL, product_name TEXT NOT NULL, variant_name TEXT NULL,
          image_url TEXT NULL, product_url TEXT NULL, quantity INT NOT NULL DEFAULT 1,
          purchase_price DOUBLE NOT NULL DEFAULT 0, net_unit_price DOUBLE NOT NULL DEFAULT 0, original_price DOUBLE NOT NULL DEFAULT 0,
          allocated_discount DOUBLE NOT NULL DEFAULT 0, actual_line_total DOUBLE NOT NULL DEFAULT 0, actual_unit_price DOUBLE NOT NULL DEFAULT 0,
          needs_review TINYINT NOT NULL DEFAULT 0, raw_text TEXT NULL, import_source VARCHAR(100) NULL, order_status VARCHAR(64) NULL, raw_json LONGTEXT NULL,
          UNIQUE KEY uq_order_product (order_id,product_key),
          CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS collector_batches (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, source VARCHAR(100) NULL, source_url TEXT NULL,
          account_id VARCHAR(100) NULL, account_username VARCHAR(255) NULL, job_type VARCHAR(64) NOT NULL DEFAULT 'sync',
          item_count INT NOT NULL DEFAULT 0, review_count INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("CREATE TABLE IF NOT EXISTS accounts (
          account_id VARCHAR(100) NOT NULL PRIMARY KEY, username VARCHAR(255) NULL, nickname VARCHAR(255) NULL, country VARCHAR(64) NULL,
          last_sync_at VARCHAR(32) NULL, last_repair_at VARCHAR(32) NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return;
    }
    $db->exec("CREATE TABLE IF NOT EXISTS orders (
      id INTEGER PRIMARY KEY AUTOINCREMENT, platform TEXT NOT NULL DEFAULT 'shopee_th', order_no TEXT NOT NULL UNIQUE,
      order_date TEXT NOT NULL DEFAULT '', shop_name TEXT, subtotal REAL DEFAULT 0, shipping_fee REAL DEFAULT 0,
      voucher_discount REAL DEFAULT 0, coins_discount REAL DEFAULT 0, platform_discount REAL DEFAULT 0,
      total_paid REAL DEFAULT 0, merchandise_paid REAL DEFAULT 0, raw_subtotal REAL DEFAULT 0,
      discount_total REAL DEFAULT 0, pricing_method TEXT DEFAULT 'legacy', created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS order_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, product_key TEXT NOT NULL, product_name TEXT NOT NULL,
      variant_name TEXT, image_url TEXT, product_url TEXT, quantity INTEGER DEFAULT 1, purchase_price REAL DEFAULT 0,
      net_unit_price REAL DEFAULT 0, original_price REAL DEFAULT 0, allocated_discount REAL DEFAULT 0,
      actual_line_total REAL DEFAULT 0, actual_unit_price REAL DEFAULT 0, needs_review INTEGER NOT NULL DEFAULT 0,
      raw_text TEXT, import_source TEXT, order_status TEXT, raw_json TEXT,
      UNIQUE(order_id, product_key), FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS collector_batches (
      id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT, source_url TEXT, account_id TEXT, account_username TEXT,
      job_type TEXT DEFAULT 'sync', item_count INTEGER DEFAULT 0, review_count INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS accounts (
      account_id TEXT PRIMARY KEY, username TEXT, nickname TEXT, country TEXT,
      last_sync_at TEXT, last_repair_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

function add_col(PDO $db,string $table,string $col,string $ddl): void {
    $cols=db_table_columns($db,$table);
    if (!isset($cols[$col])) $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
}

function ensure_schema_v200(PDO $db): void {
    db_create_base_schema($db);
    $defs=db_column_definitions($db);
    foreach($defs as $table=>$cols) foreach($cols as $c=>$ddl) add_col($db,$table,$c,$ddl);
    db_add_index($db,'orders','idx_orders_date','order_date');
    db_add_index($db,'orders','idx_orders_delivered','delivered_at');
    db_add_index($db,'orders','idx_orders_completed','completed_at');
    db_add_index($db,'orders','idx_orders_account_id','source_account_id');
    db_add_index($db,'orders','idx_orders_validation','validation_state,purchase_state');
    db_add_index($db,'order_items','idx_items_product_key','product_key');
    db_add_index($db,'order_items','idx_items_order_product','order_id,product_key');
    db_add_index($db,'order_items','idx_items_category','marketplace_category_name');
    db_add_index($db,'order_items','idx_items_pan_category','pan_category_name');
    db_add_index($db,'order_items','idx_items_family','product_family_key');
    $db->exec("UPDATE orders SET detail_state=CASE WHEN COALESCE(detail_error,'')<>'' THEN 'error' WHEN COALESCE(detail_enriched,0)=1 THEN 'complete' ELSE 'pending' END WHERE COALESCE(detail_state,'')='' OR detail_state='pending'");
    $db->exec('DELETE FROM orders WHERE list_type=4');
    $db->exec("UPDATE orders SET order_status=CASE list_type WHEN 3 THEN 'completed' WHEN 7 THEN 'shipping' WHEN 8 THEN 'delivering' WHEN 9 THEN 'unpaid' WHEN 12 THEN 'refund' ELSE COALESCE(order_status,'') END WHERE COALESCE(order_status,'')=''");
    $db->exec("UPDATE orders SET purchase_state='purchase' WHERE list_type IN (3,7,8) AND COALESCE(purchase_state,'') NOT IN ('purchase','non_purchase')");
    $db->exec("UPDATE orders SET purchase_state='non_purchase' WHERE list_type IN (9,12) AND COALESCE(purchase_state,'') NOT IN ('purchase','non_purchase')");
    // One-time incremental backfill for data imported before PAN 2.5.0.
    $missing=$db->query("SELECT id,product_name,raw_json,product_family_key,marketplace_item_id FROM order_items WHERE COALESCE(product_family_key,'')='' OR COALESCE(marketplace_item_id,'')='' LIMIT 5000")->fetchAll();
    if($missing){$u=$db->prepare('UPDATE order_items SET product_family_key=?,product_family_name=?,marketplace_shop_id=CASE WHEN marketplace_shop_id IS NULL OR marketplace_shop_id=\'\' THEN ? ELSE marketplace_shop_id END,marketplace_item_id=CASE WHEN marketplace_item_id IS NULL OR marketplace_item_id=\'\' THEN ? ELSE marketplace_item_id END,marketplace_model_id=CASE WHEN marketplace_model_id IS NULL OR marketplace_model_id=\'\' THEN ? ELSE marketplace_model_id END,marketplace_category_id=CASE WHEN marketplace_category_id IS NULL OR marketplace_category_id=\'\' THEN ? ELSE marketplace_category_id END,marketplace_category_name=CASE WHEN marketplace_category_name IS NULL OR marketplace_category_name=\'\' THEN ? ELSE marketplace_category_name END WHERE id=?');foreach($missing as $r){$j=json_decode((string)($r['raw_json']??''),true);if(!is_array($j))$j=[];$u->execute([pan_family_key((string)$r['product_name']),(string)$r['product_name'],(string)($j['shop_id']??''),(string)($j['item_id']??''),(string)($j['model_id']??''),(string)($j['category_id']??''),(string)($j['category_name']??''),(int)$r['id']]);}}
}
function ensure_hub_v02(PDO $db): void { ensure_schema_v200($db); }

function db_account_upsert_sql(PDO $db, bool $legacy): string {
    if (db_driver($db)==='mysql') {
        if ($legacy) return "INSERT INTO accounts(account_key,shopee_user_id,username,nickname,label,status,session_mode,last_sync_at,last_repair_at,last_error) VALUES(:k,:id,:u,:n,:l,'ready','extension',:sync,:repair,'') ON DUPLICATE KEY UPDATE shopee_user_id=VALUES(shopee_user_id),username=IF(VALUES(username)<>'',VALUES(username),username),nickname=IF(VALUES(nickname)<>'',VALUES(nickname),nickname),status='ready',session_mode='extension',last_sync_at=IF(VALUES(last_sync_at)<>'',VALUES(last_sync_at),last_sync_at),last_repair_at=IF(VALUES(last_repair_at)<>'',VALUES(last_repair_at),last_repair_at),last_error='',updated_at=CURRENT_TIMESTAMP";
        return "INSERT INTO accounts(account_id,username,nickname,country,last_sync_at,last_repair_at) VALUES(:id,:u,:n,:c,:sync,:repair) ON DUPLICATE KEY UPDATE username=IF(VALUES(username)<>'',VALUES(username),username),nickname=IF(VALUES(nickname)<>'',VALUES(nickname),nickname),country=IF(VALUES(country)<>'',VALUES(country),country),last_sync_at=IF(VALUES(last_sync_at)<>'',VALUES(last_sync_at),last_sync_at),last_repair_at=IF(VALUES(last_repair_at)<>'',VALUES(last_repair_at),last_repair_at),updated_at=CURRENT_TIMESTAMP";
    }
    if ($legacy) return "INSERT INTO accounts(account_key,shopee_user_id,username,nickname,label,status,session_mode,last_sync_at,last_repair_at,last_error) VALUES(:k,:id,:u,:n,:l,'ready','extension',:sync,:repair,'') ON CONFLICT(account_key) DO UPDATE SET shopee_user_id=excluded.shopee_user_id,username=CASE WHEN excluded.username<>'' THEN excluded.username ELSE accounts.username END,nickname=CASE WHEN excluded.nickname<>'' THEN excluded.nickname ELSE accounts.nickname END,status='ready',session_mode='extension',last_sync_at=CASE WHEN excluded.last_sync_at<>'' THEN excluded.last_sync_at ELSE accounts.last_sync_at END,last_repair_at=CASE WHEN excluded.last_repair_at<>'' THEN excluded.last_repair_at ELSE accounts.last_repair_at END,last_error='',updated_at=CURRENT_TIMESTAMP";
    return "INSERT INTO accounts(account_id,username,nickname,country,last_sync_at,last_repair_at) VALUES(:id,:u,:n,:c,:sync,:repair) ON CONFLICT(account_id) DO UPDATE SET username=CASE WHEN excluded.username<>'' THEN excluded.username ELSE accounts.username END,nickname=CASE WHEN excluded.nickname<>'' THEN excluded.nickname ELSE accounts.nickname END,country=CASE WHEN excluded.country<>'' THEN excluded.country ELSE accounts.country END,last_sync_at=CASE WHEN excluded.last_sync_at<>'' THEN excluded.last_sync_at ELSE accounts.last_sync_at END,last_repair_at=CASE WHEN excluded.last_repair_at<>'' THEN excluded.last_repair_at ELSE accounts.last_repair_at END,updated_at=CURRENT_TIMESTAMP";
}

function upsert_account_seen(PDO $db,string $id,string $username='',string $nickname='',string $country='',string $kind='sync'):void {
    $id=trim($id);if($id==='')return;
    $cols=db_table_columns($db,'accounts');$legacy=isset($cols['account_key']);
    if($legacy){$key='shopee-'.$id;$st=$db->prepare(db_account_upsert_sql($db,true));$st->execute([':k'=>$key,':id'=>$id,':u'=>$username,':n'=>$nickname,':l'=>$username?:$id,':sync'=>$kind==='sync'?date('Y-m-d H:i:s'):'',':repair'=>$kind==='repair'?date('Y-m-d H:i:s'):'']);}
    else{$st=$db->prepare(db_account_upsert_sql($db,false));$st->execute([':id'=>$id,':u'=>$username,':n'=>$nickname,':c'=>$country,':sync'=>$kind==='sync'?date('Y-m-d H:i:s'):'',':repair'=>$kind==='repair'?date('Y-m-d H:i:s'):'']);}
}

function db_order_upsert_sql(PDO $db): string {
    $cols='platform,order_no,order_date,order_created_at,paid_at,delivered_at,completed_at,delivery_date_source,shop_name,subtotal,shipping_fee,voucher_discount,coins_discount,platform_discount,total_paid,merchandise_paid,raw_subtotal,discount_total,pricing_method,list_type,order_status,purchase_state,validation_state,source_account_id,source_account_username,date_source,identity_source,payment_method,shipping_carrier,tracking_number,parcel_count,detail_enriched,detail_updated_at,detail_error,metadata_json,last_scan_id,last_seen_at';
    $values="'shopee_th',:no,:date,:created,:paid,:delivered,:completed,:dsource,:shop,:sub,:ship,:voucher,:coins,:platform,:total,:merch,:raw,:disc,:method,:lt,:status,:pstate,:validation,:aid,:user,:datesource,:identity,:payment,:carrier,:tracking,:parcels,:enriched,:detailupdated,:detailerror,:meta,:scan,CURRENT_TIMESTAMP";
    if(db_driver($db)==='mysql') return "INSERT INTO orders($cols) VALUES($values) ON DUPLICATE KEY UPDATE order_date=IF(VALUES(order_date)<>'',VALUES(order_date),order_date),order_created_at=IF(VALUES(order_created_at)<>'',VALUES(order_created_at),order_created_at),paid_at=IF(VALUES(paid_at)<>'',VALUES(paid_at),paid_at),delivered_at=IF(VALUES(delivered_at)<>'',VALUES(delivered_at),delivered_at),completed_at=IF(VALUES(completed_at)<>'',VALUES(completed_at),completed_at),delivery_date_source=IF(VALUES(delivery_date_source)<>'',VALUES(delivery_date_source),delivery_date_source),shop_name=IF(VALUES(shop_name)<>'',VALUES(shop_name),shop_name),subtotal=VALUES(subtotal),shipping_fee=IF(VALUES(shipping_fee)<>0,VALUES(shipping_fee),shipping_fee),voucher_discount=IF(VALUES(voucher_discount)<>0,VALUES(voucher_discount),voucher_discount),coins_discount=IF(VALUES(coins_discount)<>0,VALUES(coins_discount),coins_discount),platform_discount=IF(VALUES(platform_discount)<>0,VALUES(platform_discount),platform_discount),total_paid=VALUES(total_paid),merchandise_paid=VALUES(merchandise_paid),raw_subtotal=VALUES(raw_subtotal),discount_total=VALUES(discount_total),pricing_method=VALUES(pricing_method),list_type=VALUES(list_type),order_status=VALUES(order_status),purchase_state=VALUES(purchase_state),validation_state=VALUES(validation_state),source_account_id=VALUES(source_account_id),source_account_username=VALUES(source_account_username),date_source=VALUES(date_source),identity_source=VALUES(identity_source),payment_method=IF(VALUES(payment_method)<>'',VALUES(payment_method),payment_method),shipping_carrier=IF(VALUES(shipping_carrier)<>'',VALUES(shipping_carrier),shipping_carrier),tracking_number=IF(VALUES(tracking_number)<>'',VALUES(tracking_number),tracking_number),parcel_count=IF(VALUES(parcel_count)>0,VALUES(parcel_count),parcel_count),detail_enriched=GREATEST(detail_enriched,VALUES(detail_enriched)),detail_updated_at=IF(VALUES(detail_updated_at)<>'',VALUES(detail_updated_at),detail_updated_at),detail_error=VALUES(detail_error),metadata_json=IF(VALUES(metadata_json)<>'',VALUES(metadata_json),metadata_json),last_scan_id=IF(VALUES(last_scan_id)<>'',VALUES(last_scan_id),last_scan_id),last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP";
    return "INSERT INTO orders($cols) VALUES($values) ON CONFLICT(order_no) DO UPDATE SET order_date=CASE WHEN excluded.order_date<>'' THEN excluded.order_date ELSE orders.order_date END,order_created_at=CASE WHEN excluded.order_created_at<>'' THEN excluded.order_created_at ELSE orders.order_created_at END,paid_at=CASE WHEN excluded.paid_at<>'' THEN excluded.paid_at ELSE orders.paid_at END,delivered_at=CASE WHEN excluded.delivered_at<>'' THEN excluded.delivered_at ELSE orders.delivered_at END,completed_at=CASE WHEN excluded.completed_at<>'' THEN excluded.completed_at ELSE orders.completed_at END,delivery_date_source=CASE WHEN excluded.delivery_date_source<>'' THEN excluded.delivery_date_source ELSE orders.delivery_date_source END,shop_name=CASE WHEN excluded.shop_name<>'' THEN excluded.shop_name ELSE orders.shop_name END,subtotal=excluded.subtotal,shipping_fee=CASE WHEN excluded.shipping_fee<>0 THEN excluded.shipping_fee ELSE orders.shipping_fee END,voucher_discount=CASE WHEN excluded.voucher_discount<>0 THEN excluded.voucher_discount ELSE orders.voucher_discount END,coins_discount=CASE WHEN excluded.coins_discount<>0 THEN excluded.coins_discount ELSE orders.coins_discount END,platform_discount=CASE WHEN excluded.platform_discount<>0 THEN excluded.platform_discount ELSE orders.platform_discount END,total_paid=excluded.total_paid,merchandise_paid=excluded.merchandise_paid,raw_subtotal=excluded.raw_subtotal,discount_total=excluded.discount_total,pricing_method=excluded.pricing_method,list_type=excluded.list_type,order_status=excluded.order_status,purchase_state=excluded.purchase_state,validation_state=excluded.validation_state,source_account_id=excluded.source_account_id,source_account_username=excluded.source_account_username,date_source=excluded.date_source,identity_source=excluded.identity_source,payment_method=CASE WHEN excluded.payment_method<>'' THEN excluded.payment_method ELSE orders.payment_method END,shipping_carrier=CASE WHEN excluded.shipping_carrier<>'' THEN excluded.shipping_carrier ELSE orders.shipping_carrier END,tracking_number=CASE WHEN excluded.tracking_number<>'' THEN excluded.tracking_number ELSE orders.tracking_number END,parcel_count=CASE WHEN excluded.parcel_count>0 THEN excluded.parcel_count ELSE orders.parcel_count END,detail_enriched=MAX(orders.detail_enriched,excluded.detail_enriched),detail_updated_at=CASE WHEN excluded.detail_updated_at<>'' THEN excluded.detail_updated_at ELSE orders.detail_updated_at END,detail_error=excluded.detail_error,metadata_json=CASE WHEN excluded.metadata_json<>'' THEN excluded.metadata_json ELSE orders.metadata_json END,last_scan_id=CASE WHEN excluded.last_scan_id<>'' THEN excluded.last_scan_id ELSE orders.last_scan_id END,last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP";
}

function pan_family_key(string $name): string {
    $x=trim(strtolower($name));
    $x=preg_replace('/[\s\x{00A0}]+/u',' ',$x) ?? $x;
    $x=preg_replace('/[\|•·]+/u',' ',$x) ?? $x;
    $x=trim($x);
    return 'name:'.substr($x,0,245);
}

function db_item_upsert_sql(PDO $db): string {
    $base="INSERT INTO order_items(order_id,product_key,product_name,variant_name,image_url,product_url,quantity,purchase_price,net_unit_price,needs_review,raw_text,import_source,original_price,order_status,raw_json,allocated_discount,actual_line_total,actual_unit_price,marketplace_shop_id,marketplace_item_id,marketplace_model_id,marketplace_category_id,marketplace_category_name,marketplace_category_path,pan_category_name,category_source,category_updated_at,product_family_key,product_family_name) VALUES(:oid,:key,:name,:var,:img,:url,:qty,:price,:net,:review,:raw,:source,:orig,:status,:json,:alloc,:line,:actual,:shopid,:itemid,:modelid,:catid,:catname,:catpath,:pancat,:catsource,:catupdated,:familykey,:familyname)";
    if(db_driver($db)==='mysql') return $base." ON DUPLICATE KEY UPDATE product_name=VALUES(product_name),variant_name=VALUES(variant_name),image_url=IF(VALUES(image_url)<>'',VALUES(image_url),image_url),product_url=IF(VALUES(product_url)<>'',VALUES(product_url),product_url),quantity=VALUES(quantity),purchase_price=VALUES(purchase_price),net_unit_price=VALUES(net_unit_price),needs_review=VALUES(needs_review),raw_text=VALUES(raw_text),import_source=VALUES(import_source),original_price=VALUES(original_price),order_status=VALUES(order_status),raw_json=VALUES(raw_json),allocated_discount=VALUES(allocated_discount),actual_line_total=VALUES(actual_line_total),actual_unit_price=VALUES(actual_unit_price),marketplace_shop_id=IF(VALUES(marketplace_shop_id)<>'',VALUES(marketplace_shop_id),marketplace_shop_id),marketplace_item_id=IF(VALUES(marketplace_item_id)<>'',VALUES(marketplace_item_id),marketplace_item_id),marketplace_model_id=IF(VALUES(marketplace_model_id)<>'',VALUES(marketplace_model_id),marketplace_model_id),marketplace_category_id=IF(VALUES(marketplace_category_id)<>'',VALUES(marketplace_category_id),marketplace_category_id),marketplace_category_name=IF(VALUES(marketplace_category_name)<>'',VALUES(marketplace_category_name),marketplace_category_name),marketplace_category_path=IF(VALUES(marketplace_category_path)<>'',VALUES(marketplace_category_path),marketplace_category_path),pan_category_name=IF(VALUES(pan_category_name)<>'',VALUES(pan_category_name),pan_category_name),category_source=IF(VALUES(category_source)<>'',VALUES(category_source),category_source),category_updated_at=IF(VALUES(category_updated_at)<>'',VALUES(category_updated_at),category_updated_at),product_family_key=IF(VALUES(product_family_key)<>'',VALUES(product_family_key),product_family_key),product_family_name=IF(VALUES(product_family_name)<>'',VALUES(product_family_name),product_family_name)";
    return $base." ON CONFLICT(order_id,product_key) DO UPDATE SET product_name=excluded.product_name,variant_name=excluded.variant_name,image_url=CASE WHEN excluded.image_url<>'' THEN excluded.image_url ELSE order_items.image_url END,product_url=CASE WHEN excluded.product_url<>'' THEN excluded.product_url ELSE order_items.product_url END,quantity=excluded.quantity,purchase_price=excluded.purchase_price,net_unit_price=excluded.net_unit_price,needs_review=excluded.needs_review,raw_text=excluded.raw_text,import_source=excluded.import_source,original_price=excluded.original_price,order_status=excluded.order_status,raw_json=excluded.raw_json,allocated_discount=excluded.allocated_discount,actual_line_total=excluded.actual_line_total,actual_unit_price=excluded.actual_unit_price,marketplace_shop_id=CASE WHEN excluded.marketplace_shop_id<>'' THEN excluded.marketplace_shop_id ELSE order_items.marketplace_shop_id END,marketplace_item_id=CASE WHEN excluded.marketplace_item_id<>'' THEN excluded.marketplace_item_id ELSE order_items.marketplace_item_id END,marketplace_model_id=CASE WHEN excluded.marketplace_model_id<>'' THEN excluded.marketplace_model_id ELSE order_items.marketplace_model_id END,marketplace_category_id=CASE WHEN excluded.marketplace_category_id<>'' THEN excluded.marketplace_category_id ELSE order_items.marketplace_category_id END,marketplace_category_name=CASE WHEN excluded.marketplace_category_name<>'' THEN excluded.marketplace_category_name ELSE order_items.marketplace_category_name END,marketplace_category_path=CASE WHEN excluded.marketplace_category_path<>'' THEN excluded.marketplace_category_path ELSE order_items.marketplace_category_path END,pan_category_name=CASE WHEN excluded.pan_category_name<>'' THEN excluded.pan_category_name ELSE order_items.pan_category_name END,category_source=CASE WHEN excluded.category_source<>'' THEN excluded.category_source ELSE order_items.category_source END,category_updated_at=CASE WHEN excluded.category_updated_at<>'' THEN excluded.category_updated_at ELSE order_items.category_updated_at END,product_family_key=CASE WHEN excluded.product_family_key<>'' THEN excluded.product_family_key ELSE order_items.product_family_key END,product_family_name=CASE WHEN excluded.product_family_name<>'' THEN excluded.product_family_name ELSE order_items.product_family_name END";
}

function pan_verified_purchase_count(PDO $db): int {
    return (int)$db->query("SELECT COUNT(*) FROM orders WHERE COALESCE(validation_state,'legacy') IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND COALESCE(purchase_state,'review')='purchase' AND COALESCE(list_type,0)<>4")->fetchColumn();
}

function pan_total_order_count(PDO $db): int {
    return (int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
}

function pan_account_verified_purchase_count(PDO $db,string $accountId): int {
    $st=$db->prepare("SELECT COUNT(*) FROM orders WHERE source_account_id=:aid AND validation_state IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND purchase_state='purchase' AND COALESCE(list_type,0)<>4");$st->execute([':aid'=>$accountId]);return (int)$st->fetchColumn();
}

function import_collector_payload(PDO $db,array $payload):array {
    ensure_schema_v200($db);$items=$payload['items']??null;if(!is_array($items)||!$items)throw new RuntimeException('ไม่พบรายการจาก Collector');if(count($items)>2000)throw new RuntimeException('หนึ่งครั้งนำเข้าได้สูงสุด 2,000 รายการ');
    $source=substr((string)($payload['source']??'collector'),0,60);$sourceUrl=substr((string)($payload['source_url']??''),0,1000);$jobType=substr((string)($payload['job_type']??'sync'),0,30);$scanId=substr((string)($payload['scan_id']??''),0,80);
    $orders=[];$existingOrders=[];$previous=[];$orderItemKeys=[];$itemCount=0;$reviewCount=0;$batchAccountId='';$batchUsername='';$db->beginTransaction();
    try{foreach($items as $r){if(!is_array($r))continue;$name=trim((string)($r['product_name']??''));if($name==='')continue;$key=trim((string)($r['product_key']??''));if($key==='')continue;
      $orderNo=trim((string)($r['order_no']??''));if($orderNo==='')continue;
      $date=(string)($r['order_date']??'');$dateSource=trim((string)($r['date_source']??''));if($date!==''&&!preg_match('/^20\\d{2}-\\d{2}-\\d{2}$/',$date))continue;if($date===''&&$dateSource!=='unknown')continue;
      $accountId=trim((string)($r['source_account_id']??''));if($accountId==='')continue;$username=trim((string)($r['source_account_username']??''));$batchAccountId=$accountId;$batchUsername=$username;
      $listType=array_key_exists('list_type',$r)?(int)$r['list_type']:0;if($listType===4)continue;if(!in_array($listType,[3,7,8,9,12],true))continue;
      $shop=trim((string)($r['shop_name']??''));$qty=max(1,(int)($r['quantity']??1));$price=max(0,(float)($r['purchase_price']??0));$actual=max(0,(float)($r['actual_unit_price']??$r['net_unit_price']??$price));$line=max(0,(float)($r['actual_line_total']??($actual*$qty)));$total=max(0,(float)($r['total_paid']??$line));$rawSub=max(0,(float)($r['raw_subtotal']??($price*$qty)));$merch=max(0,(float)($r['merchandise_paid']??0));$disc=max(0,(float)($r['discount_total']??0));
      $purchaseState=in_array($listType,[3,7,8],true)?'purchase':'non_purchase';$orderStatus=(string)($r['order_status']??'');$validation='verified_v200';
      if(!array_key_exists($orderNo,$orders)){
        $pre=$db->prepare('SELECT id,list_type,detail_enriched,detail_state FROM orders WHERE order_no=?');$pre->execute([$orderNo]);$preRow=$pre->fetch();
        $existingOrders[$orderNo]=(bool)$preRow;$previous[$orderNo]=$preRow?:null;
      }
      $st=$db->prepare(db_order_upsert_sql($db));
      $st->execute([':no'=>$orderNo,':date'=>$date,':created'=>(string)($r['order_created_at']??''),':paid'=>(string)($r['paid_at']??''),':delivered'=>(string)($r['delivered_at']??''),':completed'=>(string)($r['completed_at']??''),':dsource'=>(string)($r['delivery_date_source']??''),':shop'=>$shop,':sub'=>(float)($r['subtotal']??$rawSub),':ship'=>(float)($r['shipping_fee']??0),':voucher'=>(float)($r['voucher_discount']??0),':coins'=>(float)($r['coins_discount']??0),':platform'=>(float)($r['platform_discount']??0),':total'=>$total,':merch'=>$merch,':raw'=>$rawSub,':disc'=>$disc,':method'=>(string)($r['pricing_method']??'extension_v2'),':lt'=>$listType,':status'=>$orderStatus,':pstate'=>$purchaseState,':validation'=>$validation,':aid'=>$accountId,':user'=>$username,':datesource'=>$dateSource,':identity'=>(string)($r['identity_source']??''),':payment'=>(string)($r['payment_method']??''),':carrier'=>(string)($r['shipping_carrier']??''),':tracking'=>(string)($r['tracking_number']??''),':parcels'=>(int)($r['parcel_count']??0),':enriched'=>(int)($r['detail_enriched']??0),':detailupdated'=>(string)($r['detail_updated_at']??''),':detailerror'=>(string)($r['detail_error']??''),':meta'=>substr((string)($r['metadata_json']??''),0,60000),':scan'=>$scanId]);
      $prevForStatus=$previous[$orderNo]??null;if($prevForStatus && (int)($prevForStatus['list_type']??0)!==$listType){$db->prepare("UPDATE orders SET detail_enriched=0,detail_state='pending',detail_error='',detail_missing_fields='' WHERE order_no=?")->execute([$orderNo]);}
      $oid=$db->prepare('SELECT id FROM orders WHERE order_no=?');$oid->execute([$orderNo]);$orderId=(int)$oid->fetchColumn();
      $needs=($shop===''||$price<=0)?1:0;$rawText=substr((string)($r['raw_text']??''),0,8000);
      $familyKey=trim((string)($r['product_family_key']??''));if($familyKey==='')$familyKey=pan_family_key($name);
      $it=$db->prepare(db_item_upsert_sql($db));
      $it->execute([':oid'=>$orderId,':key'=>$key,':name'=>$name,':var'=>(string)($r['variant_name']??''),':img'=>(string)($r['image_url']??''),':url'=>(string)($r['product_url']??''),':qty'=>$qty,':price'=>$price,':net'=>$actual,':review'=>$needs,':raw'=>$rawText,':source'=>'shopee_extension_v2',':orig'=>(float)($r['original_price']??0),':status'=>$orderStatus,':json'=>substr((string)($r['raw_json']??''),0,24000),':alloc'=>(float)($r['allocated_discount']??0),':line'=>$line,':actual'=>$actual,':shopid'=>(string)($r['marketplace_shop_id']??''),':itemid'=>(string)($r['marketplace_item_id']??''),':modelid'=>(string)($r['marketplace_model_id']??''),':catid'=>(string)($r['marketplace_category_id']??''),':catname'=>(string)($r['marketplace_category_name']??''),':catpath'=>(string)($r['marketplace_category_path']??''),':pancat'=>(string)($r['pan_category_name']??''),':catsource'=>(string)($r['category_source']??''),':catupdated'=>(string)($r['category_updated_at']??''),':familykey'=>$familyKey,':familyname'=>(string)($r['product_family_name']??$name)]);
      $orders[$orderNo]=$orderId;$orderItemKeys[$orderNo][$key]=1;$itemCount++;$reviewCount+=$needs;
    }
    // Snapshot reconciliation: each normalized order record contains the complete visible item set for that order.
    foreach($orders as $orderNo=>$orderId){$keys=array_keys($orderItemKeys[$orderNo]??[]);if(!$keys)continue;$ph=implode(',',array_fill(0,count($keys),'?'));$del=$db->prepare("DELETE FROM order_items WHERE order_id=? AND product_key NOT IN ($ph)");$del->execute([$orderId,...$keys]);}
    if($batchAccountId!=='')upsert_account_seen($db,$batchAccountId,$batchUsername,'','','sync');
    $b=$db->prepare('INSERT INTO collector_batches(source,source_url,account_id,account_username,job_type,item_count,review_count) VALUES(?,?,?,?,?,?,?)');$b->execute([$source,$sourceUrl,$batchAccountId,$batchUsername,$jobType,$itemCount,$reviewCount]);
    $db->commit();
    $inserted=0;$updated=0;$insertedNos=[];foreach(array_keys($orders) as $no){if($previous[$no]??null)$updated++;else{$inserted++;$insertedNos[]=$no;}}
    $refresh=[];foreach(array_keys($orders) as $no){$cur=$db->prepare('SELECT list_type,detail_enriched,detail_state FROM orders WHERE order_no=?');$cur->execute([$no]);$c=$cur->fetch();$prev=$previous[$no]??null;if(!$prev || (int)($prev['list_type']??0)!==(int)($c['list_type']??0) || (int)($c['detail_enriched']??0)!==1 || in_array((string)($c['detail_state']??'pending'),['pending','error'],true))$refresh[]=$no;}
    return ['orders'=>count($orders),'items'=>$itemCount,'review'=>$reviewCount,'inserted_orders'=>$inserted,'updated_orders'=>$updated,'inserted_order_nos'=>$insertedNos,'detail_refresh_order_nos'=>array_values(array_unique($refresh)),'pan_purchase_orders'=>pan_account_verified_purchase_count($db,$batchAccountId),'pan_total_orders'=>pan_total_order_count($db)];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function enrich_order_payload(PDO $db,array $r):array {
    ensure_schema_v200($db);$orderNo=trim((string)($r['order_no']??''));if($orderNo==='')throw new RuntimeException('order_no required');
    $st=$db->prepare('SELECT id,source_account_id FROM orders WHERE order_no=?');$st->execute([$orderNo]);$row=$st->fetch();if(!$row)throw new RuntimeException('ไม่พบ Order '.$orderNo);
    // Never let detail enrichment from a different Shopee account overwrite this order.
    $storedAccount=trim((string)($row['source_account_id']??''));
    $incomingAccount=trim((string)($r['source_account_id']??''));
    if($storedAccount!==''&&($incomingAccount===''||!hash_equals($storedAccount,$incomingAccount)))throw new RuntimeException('Order Detail ไม่ตรงกับบัญชี Shopee ที่บันทึกไว้');
    if((int)($r['list_type']??0)===4){$db->prepare('DELETE FROM orders WHERE id=?')->execute([(int)$row['id']]);return ['deleted_cancelled'=>1];}
    $sets=[];$params=[':id'=>(int)$row['id']];
    foreach(['source_account_id','source_account_username','order_created_at','paid_at','delivered_at','completed_at','delivery_date_source','payment_method','shipping_carrier','tracking_number','date_source','identity_source','detail_error','detail_missing_fields'] as $f){if(array_key_exists($f,$r)){$sets[]="$f=:$f";$params[":$f"]=(string)$r[$f];}}
    foreach(['shipping_fee','voucher_discount','coins_discount','platform_discount','seller_discount','shop_voucher_discount','shipping_discount'] as $f){if(array_key_exists($f,$r)){$sets[]="$f=:$f";$params[":$f"]=(float)$r[$f];}}
    foreach(['parcel_count','detail_enriched'] as $f){if(array_key_exists($f,$r)){$sets[]="$f=:$f";$params[":$f"]=(int)$r[$f];}}
    if(array_key_exists('metadata_json',$r)){$sets[]='metadata_json=:metadata_json';$params[':metadata_json']=substr((string)$r['metadata_json'],0,60000);}
    if(array_key_exists('order_status',$r)){$sets[]='order_status=:order_status';$params[':order_status']=(string)$r['order_status'];}
    if(array_key_exists('list_type',$r)){$sets[]='list_type=:list_type';$params[':list_type']=(int)$r['list_type'];}
    $created=(string)($r['order_created_at']??'');if($created!==''&&preg_match('/^(20\\d{2}-\\d{2}-\\d{2})/',$created,$m)){$sets[]='order_date=:order_date';$params[':order_date']=$m[1];}
    $missing=[];foreach(['payment_method','shipping_carrier','completed_at'] as $f)if(trim((string)($r[$f]??''))==='')$missing[]=$f;
    $detailError=trim((string)($r['detail_error']??''));$state=$detailError!==''?'error':($missing?'partial':'complete');
    $sets[]='detail_state=:detail_state';$params[':detail_state']=$state;$sets[]='detail_missing_fields=:detail_missing_fields';$params[':detail_missing_fields']=implode(',',$missing);
    $sets[]='detail_attempted_at=CURRENT_TIMESTAMP';$sets[]='detail_updated_at=CURRENT_TIMESTAMP';$sets[]='updated_at=CURRENT_TIMESTAMP';$sets[]='validation_state="verified_v200"';$sets[]='detail_enriched=1';
    $db->prepare('UPDATE orders SET '.implode(',',$sets).' WHERE id=:id')->execute($params);
    $aid=trim((string)($r['source_account_id']??$row['source_account_id']??''));if($aid!=='')upsert_account_seen($db,$aid,(string)($r['source_account_username']??''),'','','repair');
    return ['updated'=>1,'detail_state'=>$state,'missing_fields'=>$missing];
}
function repair_queue(PDO $db,string $accountId,int $limit=5000,bool $includeLegacy=true,bool $all=false,int $offset=0):array {
    ensure_schema_v200($db);$accountId=trim($accountId);if($accountId==='')throw new RuntimeException('account_id required');$limit=max(1,min(1000,$limit));$offset=max(0,$offset);
    $where='COALESCE(list_type,0)<>4 AND (source_account_id=:aid';$params=[':aid'=>$accountId];
    if($includeLegacy)$where.=' OR COALESCE(source_account_id,"")=""';$where.=')';
    if(!$all)$where.=" AND (COALESCE(detail_enriched,0)=0 OR COALESCE(detail_state,'pending') IN ('pending','error','partial'))";
    $count=$db->prepare("SELECT COUNT(*) FROM orders WHERE $where");$count->execute($params);$total=(int)$count->fetchColumn();
    $st=$db->prepare("SELECT order_no,list_type,source_account_id,source_account_username,detail_enriched,detail_state,detail_error FROM orders WHERE $where ORDER BY CASE WHEN COALESCE(detail_enriched,0)=0 THEN 0 WHEN detail_state='error' THEN 1 ELSE 2 END,id DESC LIMIT $limit OFFSET $offset");$st->execute($params);
    return ['rows'=>$st->fetchAll(),'total'=>$total,'offset'=>$offset,'limit'=>$limit,'has_more'=>$offset+$limit<$total];
}

function delete_cancelled_orders(PDO $db,string $accountId,array $orderNos):array {
    ensure_schema_v200($db);$accountId=trim($accountId);if($accountId==='')throw new RuntimeException('account_id required');
    $clean=[];foreach($orderNos as $v){$v=trim((string)$v);if($v!==''&&strlen($v)<=100)$clean[$v]=1;}
    $nos=array_keys($clean);if(!$nos)return ['deleted'=>0];if(count($nos)>500)throw new RuntimeException('cancelled batch too large');
    $ph=implode(',',array_fill(0,count($nos),'?'));
    $sql="DELETE FROM orders WHERE order_no IN ($ph) AND (source_account_id=? OR COALESCE(source_account_id,'')='')";
    $st=$db->prepare($sql);$st->execute([...$nos,$accountId]);return ['deleted'=>$st->rowCount(),'pan_purchase_orders'=>pan_account_verified_purchase_count($db,$accountId),'pan_total_orders'=>pan_total_order_count($db)];
}
function reconcile_account_scan(PDO $db,string $accountId,string $scanId):array {
    ensure_schema_v200($db);if($accountId===''||$scanId==='')throw new RuntimeException('account_id/scan_id required');
    $st=$db->prepare("UPDATE orders SET validation_state='not_seen_full_scan',updated_at=CURRENT_TIMESTAMP WHERE source_account_id=:aid AND COALESCE(list_type,0)<>4 AND COALESCE(last_scan_id,'')<>:sid");$st->execute([':aid'=>$accountId,':sid'=>$scanId]);$stale=$st->rowCount();
    $st=$db->prepare('SELECT COUNT(*) FROM orders WHERE source_account_id=:aid AND last_scan_id=:sid');$st->execute([':aid'=>$accountId,':sid'=>$scanId]);return ['seen'=>(int)$st->fetchColumn(),'stale'=>$stale];
}
