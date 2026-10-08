<?php
/**
 * Synthetic PAN PDO integration tests. NEVER accepts a production DSN/database.
 * Run with PAN_CI_TEST=1 and one of `sqlite` / `mysql` as first argument.
 */
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/migrate.php';

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
    $before=count_rows($db,'orders');
    check_throws(fn()=>import_test($db,[fixture('CI-B1','CI-K4','CI-A')],'wrong-owner'),
        'existing order account cannot be reassigned');
    test_check((string)row_for($db,'CI-B1')['source_account_id']==='CI-B' &&
        count_rows($db,'orders')===$before,'existing account ownership and counts preserved');
    check_throws(fn()=>enrich_order_payload($db,['order_no'=>'CI-B1','source_account_id'=>'CI-A','payment_method'=>'bad']),
        'Detail Repair wrong-account mutation rejected');
    $none=delete_cancelled_orders($db,'CI-A',['CI-B1']);
    test_check($none['deleted']===0 && row_for($db,'CI-B1')!==null,'Cancellation cannot delete other account order');

    $partial=enrich_order_payload($db,['order_no'=>'CI-A1','source_account_id'=>'CI-A',
        'payment_method'=>'Card','shipping_carrier'=>'Synthetic Carrier','completed_at'=>'']);
    test_check($partial['detail_state']==='partial' && row_for($db,'CI-A1')['detail_state']==='partial',
        'missing completion timestamp gives partial, not false complete');
    $complete=enrich_order_payload($db,['order_no'=>'CI-A1','source_account_id'=>'CI-A',
        'payment_method'=>'Card','shipping_carrier'=>'Synthetic Carrier','completed_at'=>'2026-10-02 10:00:00']);
    test_check($complete['detail_state']==='complete','complete only when required detail fields exist');
    $rq=repair_queue($db,'CI-B',10,false,false,0);
    test_check($rq['total']===1 && $rq['rows'][0]['order_no']==='CI-B1',
        'Repair queue is scoped to account with pending records');

    $reconcile=reconcile_account_scan($db,'CI-A','different-scan');
    test_check($reconcile['stale']===1 && row_for($db,'CI-A1')['validation_state']==='not_seen_full_scan',
        'Reconciliation marks only stale orders in requested account');
    test_check(row_for($db,'CI-B1')['validation_state']==='verified_v200',
        'Reconciliation does not change another account');
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
