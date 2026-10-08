<?php

declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require __DIR__.'/../app/db.php';
require __DIR__.'/../app/analytics.php';
$db=db();ensure_schema_v200($db);

$format=(string)($_GET['format']??'all_json');
function dl_name(string $base,string $ext): string {return $base.'-'.date('Ymd-His').'.'.$ext;}
function send_headers(string $type,string $name): void {
    header('Content-Type: '.$type);
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}
function csv_safe(mixed $v): string|int|float {
    if($v===null)return '';
    if(is_int($v)||is_float($v))return $v;
    $s=(string)$v;
    if($s!==''&&preg_match('/^[=+\-@]/u',$s))$s="'".$s;
    return $s;
}
function csv_query(PDO $db,string $name,string $sql,array $params=[]): never {
    send_headers('text/csv; charset=UTF-8',dl_name($name,'csv'));
    echo "\xEF\xBB\xBF";
    $out=fopen('php://output','wb');
    $st=$db->prepare($sql);$st->execute($params);$first=$st->fetch(PDO::FETCH_ASSOC);
    if($first===false){fputcsv($out,['ไม่มีข้อมูล']);fclose($out);exit;}
    fputcsv($out,array_keys($first));
    fputcsv($out,array_map('csv_safe',$first));
    while($r=$st->fetch(PDO::FETCH_ASSOC))fputcsv($out,array_map('csv_safe',$r));
    fclose($out);exit;
}

if($format==='orders_csv')csv_query($db,'orders','SELECT * FROM orders ORDER BY id');
if($format==='items_csv')csv_query($db,'order-items','SELECT i.*,o.order_no,o.order_date,o.order_created_at,o.shop_name,o.total_paid,o.source_account_id,o.source_account_username FROM order_items i JOIN orders o ON o.id=i.order_id ORDER BY i.id');
if($format==='accounts_csv')csv_query($db,'accounts','SELECT * FROM accounts ORDER BY updated_at DESC');
if($format==='batches_csv')csv_query($db,'collector-batches','SELECT * FROM collector_batches ORDER BY id DESC');
if($format==='shops_csv')csv_query($db,'shops',"SELECT shop_name,COUNT(*) orders,COALESCE(SUM(total_paid),0) total_spent,MIN(order_date) first_order,MAX(order_date) last_order FROM orders WHERE COALESCE(list_type,0)<>4 AND TRIM(COALESCE(shop_name,''))<>'' GROUP BY shop_name ORDER BY total_spent DESC");
if($format==='products_csv')csv_query($db,'products',"SELECT i.product_key,MAX(i.product_name) product_name,MAX(i.variant_name) variant_name,COUNT(DISTINCT i.order_id) orders,COALESCE(SUM(i.quantity),0) quantity,COALESCE(SUM(CASE WHEN i.actual_line_total>0 THEN i.actual_line_total ELSE (CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END)*i.quantity END),0) item_spend FROM order_items i JOIN orders o ON o.id=i.order_id WHERE COALESCE(o.list_type,0)<>4 GROUP BY i.product_key ORDER BY item_spend DESC");

if($format==='analytics_json'){
    send_headers('application/json; charset=UTF-8',dl_name('analytics','json'));
    echo json_encode(analytics_snapshot($db),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);exit;
}
if($format==='analytics_csv'){
    $a=analytics_snapshot($db);$c=$a['core']??[];
    send_headers('text/csv; charset=UTF-8',dl_name('analytics-summary','csv'));echo "\xEF\xBB\xBF";$out=fopen('php://output','wb');fputcsv($out,['metric','value']);
    foreach($c as $k=>$v)fputcsv($out,[csv_safe($k),csv_safe($v)]);
    fclose($out);exit;
}
if($format==='sqlite'){
    if(db_driver($db)!=='sqlite'){http_response_code(400);exit('SQLite direct backup ใช้ได้เฉพาะเมื่อฐานปัจจุบันเป็น SQLite');}
    $ck=$db->query('PRAGMA wal_checkpoint(FULL)')->fetch(PDO::FETCH_NUM);if(!$ck||((int)($ck[0]??1))!==0){http_response_code(503);exit('SQLite WAL checkpoint ยังไม่พร้อม กรุณาลอง backup ใหม่อีกครั้ง');}
    $path=db_sqlite_path_from_config(hub_db_config());
    if(!is_file($path)){http_response_code(404);exit('database not found');}
    send_headers('application/vnd.sqlite3',dl_name('pan','sqlite'));
    header('Content-Length: '.filesize($path));readfile($path);exit;
}
if($format==='all_json'){
    send_headers('application/json; charset=UTF-8',dl_name('pan-all-data','json'));
    echo '{"exported_at":'.json_encode(date('c')).',"version":"2.5.2","analytics":'.json_encode(analytics_snapshot($db),JSON_UNESCAPED_UNICODE).',"tables":{';
    $firstTable=true;foreach(['orders','order_items','accounts','collector_batches'] as $t){if(!$firstTable)echo ',';$firstTable=false;echo json_encode($t).':[';$st=$db->query('SELECT * FROM '.$t);$first=true;while($row=$st->fetch(PDO::FETCH_ASSOC)){if(!$first)echo ',';$first=false;echo json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}echo ']';if(function_exists('ob_flush'))@ob_flush();flush();}echo '}}';exit;
}
http_response_code(400);header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>false,'error'=>'unknown export format'],JSON_UNESCAPED_UNICODE);
