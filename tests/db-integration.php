<?php
/**
 * Synthetic PAN PDO integration tests. NEVER accepts a production DSN/database.
 * Run with PAN_CI_TEST=1 and one of `sqlite` / `mysql` as first argument.
 */
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/migrate.php';
require_once __DIR__.'/../app/sync_anchor.php';
require_once __DIR__.'/../app/order_items_view.php';

function test_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('FAIL: '.$label);
    echo 'PASS: '.$label."\n";
}
function count_rows(PDO $db, string $table): int {
    return (int)$db->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
}
function row_for(PDO $db, string $no): ?array {
    $st=$db->prepare('SELECT * FROM orders WHERE order_no=?');$st->execute([$no]);return $st->fetch()?:null;
}
function fixture(string $no, string $key, string $account, int $qty=1): array {
    return [
        'platform'=>'shopee_th','order_no'=>$no,'order_date'=>'2026-10-01',
        'date_source'=>'test_fixture','list_type'=>3,'order_status'=>'completed',
        'shop_name'=>'Synthetic Shop','product_key'=>$key,'product_name'=>'Synthetic Item',
        'quantity'=>$qty,'purchase_price'=>15.5,'actual_unit_price'=>15.5,
        'actual_line_total'=>15.5*$qty,'total_paid'=>15.5*$qty,
        'source_account_id'=>$account,'source_account_username'=>'fixture',
        'marketplace_shop_id'=>'100','marketplace_item_id'=>'200'
    ];
}
function import_test(PDO $db,array $rows,string $scan):array {
    return import_collector_payload($db, ['source'=>'synthetic_ci','job_type'=>'sync',
        'source_url'=>'https://example.invalid/synthetic-fixture','scan_id'=>$scan,'items'=>$rows]);
}
function check_throws(callable $run,string $label):void {
    $failed=false;
    try{$run();}catch(RuntimeException|PDOException $e){$failed=true;}
    test_check($failed,$label);
}
function cleanup_sqlite(string $path):void {
    foreach ([$path,$path.'-wal',$path.'-shm'] as $f) if(is_file($f))@unlink($f);
}

if (getenv('PAN_CI_TEST') !== '1') {
    fwrite(STDERR,"Refusing DB tests without PAN_CI_TEST=1\n");exit(2);
}
$mode=$argv[1]??'';
if(!in_array($mode,['sqlite','mysql'],true)){
    fwrite(STDERR,"Usage: PAN_CI_TEST=1 php tests/db-integration.php sqlite|mysql\n");exit(2);
}
$tmp=tempnam(sys_get_temp_dir(),'pan-ci-db-');
if($tmp===false)throw new RuntimeException('Could not create temporary SQLite database');
// Avoid opening any PAN runtime storage/config.php.
$sourceCfg=['driver'=>'sqlite','path'=>$tmp];
try {
    if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('pdo_sqlite required for both SQLite and migration tests');
    if($mode==='sqlite'){
        $cfg=$sourceCfg;
        $db=db_connect($cfg);
    } else {
        $name=(string)getenv('PAN_TEST_MYSQL_DB');
        $host=(string)getenv('PAN_TEST_MYSQL_HOST');
        $port=(int)(getenv('PAN_TEST_MYSQL_PORT')?:3306);
        // Never accept arbitrary MySQL databases or remote hosts.
        if($name!=='pan_ci_test'||!in_array($host,['127.0.0.1','localhost'],true)||$port!==3306){
            throw new RuntimeException('MySQL tests restricted to local disposable pan_ci_test only');
        }
        $cfg=['driver'=>'mysql','host'=>$host,'port'=>$port,'database'=>$name,
            'username'=>(string)getenv('PAN_TEST_MYSQL_USER'),
            'password'=>(string)getenv('PAN_TEST_MYSQL_PASS'),'charset'=>'utf8mb4'];
        $db=db_connect($cfg);
        foreach(['orders','order_items','accounts','collector_batches'] as $t){
            if(db_table_exists($db,$t) && count_rows($db,$t)!==0){
                throw new RuntimeException('Refusing nonempty MySQL test DB');
            }
        }
    }
    ensure_schema_v200($db);
    test_check(count_rows($db,'orders')===0,$mode.' empty staging schema initialized');

    $first=import_test($db,[fixture('CI-A1','CI-K1','CI-A'),fixture('CI-A1','CI-K2','CI-A')],'scan-a');
    test_check($first['inserted_orders']===1 && $first['pan_purchase_orders']===1,'new order counted once');
    test_check(count_rows($db,'order_items')===2,'multi-item order persisted');
    $orderAId=(int)row_for($db,'CI-A1')['id'];
    $pageLines=pan_order_items_for_page($db,[['id'=>$orderAId]]);
    test_check(count($pageLines[$orderAId]??[])===2,'Order details load both product lines for the same Order');
    test_check(array_column($pageLines[$orderAId],'product_name')===['Synthetic Item','Synthetic Item'],
        'Order details preserve distinct product keys without merging lines');
    test_check(pan_order_item_safe_url('javascript:alert(1)')===''&&pan_order_item_safe_url('data:text/html,x')==='' &&
        pan_order_item_safe_url('https://shopee.co.th/item')==='https://shopee.co.th/item',
        'Product link sanitizer accepts HTTPs and blocks script/data URL schemes');
    $again=import_test($db,[fixture('CI-A1','CI-K1','CI-A',2)],'scan-a2');
    test_check($again['updated_orders']===1 && $again['inserted_orders']===0,'idempotent upsert not double-counted');
    test_check(count_rows($db,'order_items')===1,'verified order snapshot reconciles stale item');
    test_check((int)$db->query('SELECT quantity FROM order_items LIMIT 1')->fetchColumn()===2,'item quantity updated');

    $previous=count_rows($db,'collector_batches');
    check_throws(fn()=>import_test($db,[fixture('CI-A2','CI-K3','CI-A'),fixture('CI-B1','CI-K4','CI-B')],'mixed'),
        'mixed-account page rejected before importing any orders');
    test_check(row_for($db,'CI-A2')===null && row_for($db,'CI-B1')===null &&
        count_rows($db,'collector_batches')===$previous,'mixed-account failure leaves no partial writes');
    check_throws(fn()=>import_test($db,[fixture('CI-A2','CI-K3','CI-A'),['bad'=>'shape']],'badshape'),
        'mixed valid/invalid schema rejected as one page');
    test_check(row_for($db,'CI-A2')===null,'invalid page cannot advance stored orders');

    import_test($db,[fixture('CI-B1','CI-K4','CI-B')],'scan-b');
    $orderBId=(int)row_for($db,'CI-B1')['id'];
    $pageA=pan_order_items_for_page($db,[['id'=>$orderAId]]);
    test_check(count($pageA[$orderAId]??[])===1&&!array_key_exists($orderBId,$pageA),
        'Paginated Order view cannot load unrelated order items');
    $allPage=pan_order_items_for_page($db,[['id'=>$orderBId],['id'=>$orderAId],['id'=>$orderAId]]);
    test_check(count($allPage[$orderBId]??[])===1&&count($allPage[$orderAId]??[])===1,
        'Multiple Orders are grouped by order id; repeated IDs are deduplicated');
    test_check(pan_order_items_for_page($db,[])===[]&&
        pan_order_items_for_page($db,[['id'=>999999]])===[999999=>[]],
        'Empty page and an Order without items render safely');
    test_check(pan_order_item_unit_price($allPage[$orderAId][0])===15.5&&
        pan_order_item_line_total($allPage[$orderAId][0])===31.0,
        'Line-item quantity/price totals reflect the verified import, not guessed order totals');
    $db->exec("UPDATE orders SET order_date='2026-12-28',date_source='shipping.tracking_info.ctime',order_created_at='' WHERE order_no='CI-B1'");
    $anchor=recent_sync_anchor($db,'CI-B');
    test_check($anchor['latest_order_date']===''&&$anchor['cutoff_date']==='',
        'recent sync anchor ignores old shipping fallback as purchase date');
    $noDate=fixture('CI-B1','CI-K4','CI-B');$noDate['order_date']='';$noDate['date_source']='unknown';
    import_test($db,[$noDate],'no-created-date');
    test_check(row_for($db,'CI-B1')['date_source']==='shipping.tracking_info.ctime',
        'reimport without creation timestamp preserves suspect legacy date provenance');
    $before=count_rows($db,'orders');
    check_throws(fn()=>import_test($db,[fixture('CI-B1','CI-K4','CI-A')],'wrong-owner'),
        'existing order account cannot be reassigned');
    test_check((string)row_for($db,'CI-B1')['source_account_id']==='CI-B' &&
        count_rows($db,'orders')===$before,'existing account ownership and counts preserved');
    check_throws(fn()=>enrich_order_payload($db,['order_no'=>'CI-B1','source_account_id'=>'CI-A','payment_method'=>'bad']),
        'Detail Repair wrong-account mutation rejected');
    $none=delete_cancelled_orders($db,'CI-A',['CI-B1']);
    test_check($none['deleted']===0 && row_for($db,'CI-B1')!==null,'Cancellation cannot delete other account order');

    // Buyer API has no accepted courier-delivered contract; successful Detail fetch
    // is complete regardless of absence of delivered_at, carrier, or payment labels.
    $detail=enrich_order_payload($db,['order_no'=>'CI-A1','source_account_id'=>'CI-A',
        'payment_method'=>'92','shipping_carrier'=>'',
        'completed_at'=>'2026-10-02 10:00:00','delivered_at'=>'','delivery_date_source'=>'']);
    test_check($detail['detail_state']==='complete' && $detail['missing_fields']===[],
        'missing Buyer delivery date is not a Detail failure');
    test_check(repair_queue($db,'CI-A',10,false,false,0)['total']===0,
        'completed Order without delivery timestamp does not loop Repair');
    $ambiguous=enrich_order_payload($db,['order_no'=>'CI-A1','source_account_id'=>'CI-A',
        'delivered_at'=>'2026-10-01 18:00:00','delivery_date_source'=>'detail.shipping.delivery_time']);
    test_check($ambiguous['detail_state']==='complete' && repair_queue($db,'CI-A',10,false,false,0)['total']===0,
        'unverified raw delivery time does not become an acceptance/retry requirement');
    test_check(row_for($db,'CI-A1')['delivered_at']==='2026-10-01 18:00:00',
        'raw historical timestamp stays available without being displayed');
    // Old variants can have stale delivery-specific missing flags and a partial
    // state; do not require users to fetch a non-contractual field again.
    foreach(['delivered_at','shipping_carrier,delivered_at','delivered_at,payment_method,shipping_carrier'] as $fieldSet){
        $s=$db->prepare("UPDATE orders SET detail_state='partial',detail_missing_fields=? WHERE order_no='CI-A1'");
        $s->execute([$fieldSet]);
        test_check(repair_queue($db,'CI-A',10,false,false,0)['total']===0,
            'obsolete Detail partial flag skipped: '.$fieldSet);
        test_check((int)$db->query("SELECT COUNT(*) FROM orders WHERE source_account_id='CI-A' AND ".pan_repair_required_sql())->fetchColumn()===0,
            'API status pending count matches Repair queue: '.$fieldSet);
    }
    // Unknown genuine problems and unfinished detail fetches remain retryable.
    $db->exec("UPDATE orders SET detail_state='partial',detail_missing_fields='tracking_number' WHERE order_no='CI-A1'");
    test_check(repair_queue($db,'CI-A',10,false,false,0)['total']===1,
        'non-retired missing field still requires Repair');
    $db->exec("UPDATE orders SET detail_state='error',detail_error='fixture' WHERE order_no='CI-A1'");
    test_check(repair_queue($db,'CI-A',10,false,false,0)['total']===1,
        'failed detail fetch still requires Repair');
    $complete=enrich_order_payload($db,['order_no'=>'CI-A1','source_account_id'=>'CI-A',
        'shipping_carrier'=>'','delivered_at'=>'','delivery_date_source'=>'','detail_error'=>'']);
    $saved=row_for($db,'CI-A1');
    test_check($complete['detail_state']==='complete' && $saved['detail_missing_fields']==='' && repair_queue($db,'CI-A',10,false,false,0)['total']===0,
        'successful new Detail fetch clears old legacy flags');
    test_check($saved['delivered_at']==='2026-10-01 18:00:00' && $saved['delivery_date_source']==='detail.shipping.delivery_time',
        'blank optional raw delivery data cannot erase historical source');
    test_check(repair_queue($db,'CI-A',10,false,true,0)['total']>=1,
        'explicit advanced all-detail still supports user-requested recheck');
    test_check(row_for($db,'CI-B1')!==null,'other-account orders are untouched by Repair behavior');
    $placedSql=pan_order_placed_sql();
    $db->exec("UPDATE orders SET order_created_at='',order_date='2026-10-04',date_source='shipping.tracking_info.ctime' WHERE order_no='CI-A1'");
    $when=$db->query("SELECT $placedSql placed FROM orders WHERE order_no='CI-A1'")->fetchColumn();
    test_check($when===null,'paid/shipping fallback cannot be used for purchase-date analytics');
    $db->exec("UPDATE orders SET order_created_at='2026-10-01 09:40:01',date_source='info_card.create_time' WHERE order_no='CI-A1'");
    $when=$db->query("SELECT $placedSql placed FROM orders WHERE order_no='CI-A1'")->fetchColumn();
    test_check($when==='2026-10-01 09:40:01','genuine order creation is used ahead of old date-only fallback');
    $rq=repair_queue($db,'CI-B',10,false,false,0);
    test_check($rq['total']===1 && $rq['rows'][0]['order_no']==='CI-B1',
        'Repair queue is scoped to account with pending records');

    $reconcile=reconcile_account_scan($db,'CI-A','different-scan');
    test_check($reconcile['stale']===1 && row_for($db,'CI-A1')['validation_state']==='verified_v200',
        'Reconciliation counts stale safely without hiding or downgrading a valid purchase');
    test_check(row_for($db,'CI-B1')['validation_state']==='verified_v200',
        'Reconciliation does not change another account');
    // Historical PAN releases downgraded valid records to not_seen_full_scan.
    // The current code must surface them without silently rewriting raw history.
    $db->exec("UPDATE orders SET validation_state='not_seen_full_scan' WHERE order_no='CI-A1'");
    test_check(pan_verified_purchase_count($db)===2 && pan_account_verified_purchase_count($db,'CI-A')===1,
        'previously imported orders marked not_seen_full_scan are visible in account/PAN counts');
    $visible=pan_purchase_visibility_sql('o');
    $cnt=(int)$db->query("SELECT COUNT(*) FROM orders o WHERE $visible AND o.purchase_state='purchase'")->fetchColumn();
    test_check($cnt===2, 'dashboard/analytics can include historically hidden purchases');
    $byMonth=pan_order_placed_sql('o');
    $selected=(int)$db->query("SELECT COUNT(*) FROM orders o WHERE substr($byMonth,1,7)='2026-10'")->fetchColumn();
    test_check($selected===1,'strict purchase-date filtering excludes unknown-date rows but preserves valid ones');
    $nullCount=(int)$db->query("SELECT COUNT(*) FROM orders o WHERE $byMonth IS NULL")->fetchColumn();
    test_check($nullCount===1,'unknown-date orders can be found separately without inventing an October purchase');
    $orderExpr=pan_order_sort_sql('o');
    $found=$db->query("SELECT order_no FROM orders o ORDER BY $orderExpr DESC,o.id DESC LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    test_check(count($found)===2 && in_array('CI-B1',$found,true),'unknown-date order remains sortable via database observation timestamp');

    $del=delete_cancelled_orders($db,'CI-B',['CI-B1']);
    test_check($del['deleted']===1 && row_for($db,'CI-B1')===null,
        'Own-account cancellation deletes order');
    test_check(count_rows($db,'order_items')===1,
        'Cancellation cascades to order items without touching other orders');

    // Trigger an actual mid-batch DB write failure to prove all-or-nothing import.
    if($mode==='sqlite'){
        $db->exec("CREATE TRIGGER pan_ci_fail BEFORE INSERT ON order_items WHEN NEW.product_key='CI-FAIL' BEGIN SELECT RAISE(ABORT,'synthetic write failure'); END");
    }else{
        $db->exec("CREATE TRIGGER pan_ci_fail BEFORE INSERT ON order_items FOR EACH ROW BEGIN IF NEW.product_key='CI-FAIL' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic write failure'; END IF; END");
    }
    $oldBatch=count_rows($db,'collector_batches');
    check_throws(fn()=>import_test($db,[fixture('CI-ROLLBACK','CI-OK','CI-A'),fixture('CI-ROLLBACK','CI-FAIL','CI-A')],'rollback'),
        'injected DB failure interrupts import transaction');
    test_check(row_for($db,'CI-ROLLBACK')===null && count_rows($db,'collector_batches')===$oldBatch,
        'transaction rollback leaves no partial order, items or batch');
    $db->exec('DROP TRIGGER pan_ci_fail');

    if($mode==='mysql'){
        // Genuine SQLite→MySQL migration into the **same disposable** database,
        // after deleting all synthetic test rows (no source production files).
        $db->exec('DELETE FROM order_items');$db->exec('DELETE FROM orders');
        $db->exec('DELETE FROM collector_batches');$db->exec('DELETE FROM accounts');
        test_check(count_rows($db,'orders')===0 && count_rows($db,'order_items')===0,
            'disposable migration target is empty');
        $source=db_connect($sourceCfg);ensure_schema_v200($source);
        import_test($source,[fixture('CI-MIGRATE','CI-M1','CI-M')],'migration-source');
        $migration=migrate_sqlite_to_mysql($sourceCfg,$cfg);
        test_check($migration['source']['orders']===$migration['target']['orders'] &&
            $migration['target']['orders']===1 && $migration['target']['order_items']===1,
            'SQLite→MySQL migration copies orders/items with exact row counts');
        test_check(row_for($db,'CI-MIGRATE')!==null,
            'migrated order remains readable on target MySQL database');
        $source=null;
    }
    echo strtoupper($mode)." DB INTEGRATION PASS\n";
} finally {
    $db=null;
    cleanup_sqlite($tmp);
}
