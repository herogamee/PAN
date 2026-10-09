<?php
require __DIR__.'/app/bootstrap.php';
hub_require_installed();
hub_require_login();
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') hub_verify_csrf();
set_exception_handler(function(Throwable $e){http_response_code(500);echo '<!doctype html><meta charset="utf-8"><style>*{box-sizing:border-box}body{font-family:system-ui,-apple-system,Segoe UI,Tahoma,sans-serif;padding:30px;background:#f7f8fb;color:#151820}.err{max-width:900px;margin:auto;background:#fff;border:1px solid #fecaca;border-radius:14px;padding:22px;box-shadow:0 8px 28px rgba(15,23,42,.06)}.err h2{margin-top:0}code{background:#f3f4f6;padding:2px 5px;border-radius:4px}</style><div class="err"><h2>PAN — น้องแพน เกิดข้อผิดพลาด</h2><p>'.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8').'</p><p><code>'.htmlspecialchars($e->getFile(),ENT_QUOTES,'UTF-8').':'.$e->getLine().'</code></p></div>';exit;});
require_once __DIR__.'/app/db.php';
require_once __DIR__.'/app/analytics.php';
require_once __DIR__.'/app/payment_method.php';
require_once __DIR__.'/app/order_timeline.php';
$db=db(); ensure_hub_v02($db); $currentDbDriver=db_driver($db);
$action=$_GET['action']??'';
if($action==='mark_reviewed'&&$_SERVER['REQUEST_METHOD']==='POST'){$id=(int)($_POST['id']??0);if($id)$db->prepare('UPDATE order_items SET needs_review=0 WHERE id=?')->execute([$id]);header('Location: ./?page=collector');exit;}
if($action==='delete_item'&&$_SERVER['REQUEST_METHOD']==='POST'){$id=(int)($_POST['id']??0);if($id)$db->prepare('DELETE FROM order_items WHERE id=?')->execute([$id]);header('Location: ./?page=collector');exit;}
if($action==='delete_suspicious'&&$_SERVER['REQUEST_METHOD']==='POST'){$n=(int)$db->exec("DELETE FROM orders WHERE validation_state='suspicious_legacy'");$_SESSION['flash']="ลบรายการ legacy ที่ถูกกักไว้แล้ว {$n} Order";header('Location: ./?page=collector');exit;}

if($action==='delete_old_collector'&&$_SERVER['REQUEST_METHOD']==='POST'){
  $db->beginTransaction();try{
    $ids=$db->query("SELECT id FROM orders WHERE validation_state='legacy_visible'")->fetchAll(PDO::FETCH_COLUMN);
    $n=0;if($ids){$ph=implode(',',array_fill(0,count($ids),'?'));$x=$db->prepare("DELETE FROM orders WHERE id IN ($ph)");$x->execute($ids);$n=$x->rowCount();}
    $db->commit();$_SESSION['flash']="ลบข้อมูล Collector เก่าที่ยังอยู่ในฐานแล้ว {$n} Order";
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$_SESSION['flash']="ลบไม่สำเร็จ: ".$e->getMessage();}
  header('Location: ./?page=settings');exit;
}
if($action==='delete_all_data'&&$_SERVER['REQUEST_METHOD']==='POST'){
 if(trim((string)($_POST['confirm_text']??''))!=='DELETE ALL'){$_SESSION['flash']='กรุณาพิมพ์ DELETE ALL ให้ตรงก่อนล้างข้อมูล';header('Location: ./?page=settings');exit;}
 $db->beginTransaction();try{
  $items=(int)$db->query('SELECT COUNT(*) FROM order_items')->fetchColumn();$orders=(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
  $db->exec('DELETE FROM order_items');$db->exec('DELETE FROM orders');$db->exec('DELETE FROM collector_batches');
  $db->commit();$_SESSION['flash']="ล้างข้อมูลทั้งหมดแล้ว {$items} รายการ / {$orders} Order";
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$_SESSION['flash']="ล้างข้อมูลไม่สำเร็จ: ".$e->getMessage();}
 header('Location: ./?page=settings');exit;
}
$page=$_GET['page']??'dashboard';$q=trim($_GET['q']??'');$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');} function money($v){return '฿'.number_format((float)$v,2);} function order_status_th($status,$listType=null){$m=['completed'=>'สำเร็จแล้ว','shipping'=>'กำลังจัดส่ง','delivering'=>'กำลังนำส่ง','unpaid'=>'ยังไม่ชำระ','refund'=>'คืนสินค้า/คืนเงิน','cancelled'=>'ยกเลิก'];$s=(string)$status;if(isset($m[$s]))return $m[$s];$lm=[3=>'สำเร็จแล้ว',4=>'ยกเลิก',7=>'กำลังจัดส่ง',8=>'กำลังนำส่ง',9=>'ยังไม่ชำระ',12=>'คืนสินค้า/คืนเงิน'];return $lm[(int)$listType]??($s?:'ไม่ทราบสถานะ');} function show_order_date($d){return pan_date_text((string)$d)?:'ไม่ทราบวันที่';} function price_actual($r){$a=(float)($r['actual_unit_price']??0);return $a>0?$a:(float)($r['net_unit_price']??$r['purchase_price']??0);} function detail_state_label($o){if(trim((string)($o['detail_error']??''))!=='')return 'ผิดพลาด';$s=(string)($o['detail_state']??'');if($s==='partial' && (int)($o['detail_enriched']??0)===1 && pan_optional_only_missing_fields((string)($o['detail_missing_fields']??'')))return 'ตรวจแล้ว';if($s==='complete')return 'ตรวจแล้ว';if($s==='partial'||((int)($o['detail_enriched']??0)===1))return 'บางส่วน';return 'รอเติม';} 
$productDateExprO=pan_order_placed_sql('o');
$productDateExpr=pan_order_placed_sql();
$productDateExprXO=pan_order_placed_sql('xo');
$verifiedStates="'verified_v045','verified_v049','verified_v049_date_unknown','verified_v200'";
$purchaseWhere="COALESCE(validation_state,'legacy') IN ($verifiedStates) AND COALESCE(purchase_state,'review')='purchase' AND COALESCE(list_type,0)<>4";
$legacyCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE validation_state='legacy_visible'")->fetchColumn();
$purchaseStatusCounts=[3=>0,7=>0,8=>0];
foreach($db->query("SELECT COALESCE(list_type,0) list_type,COUNT(*) c FROM orders WHERE $purchaseWhere GROUP BY COALESCE(list_type,0)")->fetchAll() as $r){$lt=(int)$r['list_type'];if(isset($purchaseStatusCounts[$lt]))$purchaseStatusCounts[$lt]=(int)$r['c'];}
$stats=['orders'=>(int)$db->query("SELECT COUNT(*) FROM orders WHERE $purchaseWhere")->fetchColumn(),
'items'=>(int)$db->query("SELECT COALESCE(SUM(i.quantity),0) FROM order_items i JOIN orders o ON o.id=i.order_id WHERE COALESCE(o.validation_state,'legacy') IN ($verifiedStates) AND COALESCE(o.purchase_state,'review')='purchase'")->fetchColumn(),
'spent'=>(float)$db->query("SELECT COALESCE(SUM(total_paid),0) FROM orders WHERE $purchaseWhere")->fetchColumn(),
'products'=>(int)$db->query("SELECT COUNT(DISTINCT i.product_key) FROM order_items i JOIN orders o ON o.id=i.order_id WHERE COALESCE(o.validation_state,'legacy') IN ($verifiedStates) AND COALESCE(o.purchase_state,'review')='purchase'")->fetchColumn()];
$chartMode=$_GET['chart_mode']??'month';
if(!in_array($chartMode,['month','year'],true))$chartMode='month';
$chartDateExpr="substr(".pan_order_placed_sql().",1,10)";
$chartWhere="purchase_state='purchase' AND validation_state IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND COALESCE(list_type,0)=3";
$latestChartDate=(string)($db->query("SELECT COALESCE(MAX($chartDateExpr),'') FROM orders WHERE $chartWhere")->fetchColumn()?:'');
$defaultChartYear=$latestChartDate!==''?(int)substr($latestChartDate,0,4):(int)date('Y');$defaultChartMonth=$latestChartDate!==''?(int)substr($latestChartDate,5,2):(int)date('n');
$chartYear=(int)($_GET['chart_year']??$defaultChartYear);if($chartYear<2015||$chartYear>2100)$chartYear=$defaultChartYear;
$chartMonth=(int)($_GET['chart_month']??$defaultChartMonth);if($chartMonth<1||$chartMonth>12)$chartMonth=$defaultChartMonth;
$chartLabels=[];$chartValues=[];$chartOrders=[];
if($chartMode==='month'){$days=(int)date('t',strtotime(sprintf('%04d-%02d-01',$chartYear,$chartMonth)));$tmp=[];$st=$db->prepare("SELECT $chartDateExpr chart_date,COUNT(*) order_count,COALESCE(SUM(total_paid),0) spent FROM orders WHERE $chartWhere AND $chartDateExpr LIKE :ym GROUP BY chart_date ORDER BY chart_date");$st->execute([':ym'=>sprintf('%04d-%02d-',$chartYear,$chartMonth).'%']);foreach($st->fetchAll() as $r)$tmp[$r['chart_date']]=$r;for($d=1;$d<=$days;$d++){$date=sprintf('%04d-%02d-%02d',$chartYear,$chartMonth,$d);$chartLabels[]=(string)$d;$chartValues[]=(float)($tmp[$date]['spent']??0);$chartOrders[]=(int)($tmp[$date]['order_count']??0);}}
else{$tmp=[];$st=$db->prepare("SELECT CAST(substr($chartDateExpr,6,2) AS INTEGER) m,COUNT(*) order_count,COALESCE(SUM(total_paid),0) spent FROM orders WHERE $chartWhere AND $chartDateExpr LIKE :yy GROUP BY m ORDER BY m");$st->execute([':yy'=>sprintf('%04d-',$chartYear).'%']);foreach($st->fetchAll() as $r)$tmp[(int)$r['m']]=$r;$thaiMonths=['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];for($m=1;$m<=12;$m++){$chartLabels[]=$thaiMonths[$m-1];$chartValues[]=(float)($tmp[$m]['spent']??0);$chartOrders[]=(int)($tmp[$m]['order_count']??0);}}
$chartTotal=array_sum($chartValues);$chartOrderTotal=array_sum($chartOrders);$chartAvg=$chartOrderTotal>0?$chartTotal/$chartOrderTotal:0;
$ss=$db->prepare("SELECT SUM(CASE WHEN TRIM(COALESCE(order_created_at,''))<>'' THEN 1 ELSE 0 END) created,SUM(CASE WHEN TRIM(COALESCE(order_created_at,''))='' AND $chartDateExpr IS NOT NULL THEN 1 ELSE 0 END) order_date FROM orders WHERE $chartWhere AND $chartDateExpr LIKE :period");$ss->execute([':period'=>$chartMode==='month'?sprintf('%04d-%02d-',$chartYear,$chartMonth).'%':sprintf('%04d-',$chartYear).'%']);$sr=$ss->fetch()?:[];$chartSourceStats=['created'=>(int)($sr['created']??0),'order_date'=>(int)($sr['order_date']??0)];
$recentOrders=$db->query("SELECT order_no,order_date,order_created_at,date_source,detail_enriched,detail_error,shop_name,total_paid,list_type,COALESCE(order_status,'') order_status FROM orders WHERE purchase_state='purchase' AND validation_state IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND COALESCE(list_type,0)<>4 ORDER BY CASE WHEN COALESCE(order_date,'')='' THEN 1 ELSE 0 END,order_date DESC,id DESC LIMIT 8")->fetchAll();
$topProducts=$db->query("SELECT i.product_name,MAX(i.image_url) image_url,SUM(i.quantity) qty,COUNT(DISTINCT i.order_id) purchase_count,MIN(CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price ELSE i.purchase_price END) low_price,MAX(CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price ELSE i.purchase_price END) high_price FROM order_items i JOIN orders o ON o.id=i.order_id WHERE o.purchase_state='purchase' AND o.validation_state IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND COALESCE(o.list_type,0)<>4 GROUP BY i.product_key ORDER BY purchase_count DESC,qty DESC LIMIT 6")->fetchAll();

if($page==='orders'){
  $scope=(string)($_GET['scope']??'all');
  if(!in_array($scope,['all','verified','legacy'],true))$scope='all';

  $orderYear=preg_match('/^\d{4}$/',(string)($_GET['year']??''))?(string)$_GET['year']:'';
  $orderMonth=preg_match('/^(0[1-9]|1[0-2])$/',(string)($_GET['month']??''))?(string)$_GET['month']:'';
  $orderShop=trim((string)($_GET['shop']??''));
  $orderStatus=(string)($_GET['status']??'');
  $orderAccount=trim((string)($_GET['account']??''));
  $orderRepair=(string)($_GET['repair']??'');
  $orderMinRaw=trim((string)($_GET['min_total']??''));
  $orderMaxRaw=trim((string)($_GET['max_total']??''));
  $orderMin=is_numeric($orderMinRaw)?max(0,(float)$orderMinRaw):null;
  $orderMax=is_numeric($orderMaxRaw)?max(0,(float)$orderMaxRaw):null;
  if($orderMin!==null&&$orderMax!==null&&$orderMin>$orderMax){$tmp=$orderMin;$orderMin=$orderMax;$orderMax=$tmp;}

  $ordersPer=max(10,min(200,(int)($_GET['per']??50)));
  if(!in_array($ordersPer,[10,25,50,100,200],true))$ordersPer=50;
  $ordersPage=max(1,(int)($_GET['p']??1));

  $orderSort=(string)($_GET['sort']??'date_desc');
  $orderDateExpr=pan_order_placed_sql('o');
  $sortMap=[
    'date_desc'=>"$orderDateExpr DESC,o.id DESC",
    'date_asc'=>"$orderDateExpr ASC,o.id ASC",
    'paid_desc'=>"o.total_paid DESC,$orderDateExpr DESC,o.id DESC",
    'paid_asc'=>"o.total_paid ASC,$orderDateExpr DESC,o.id DESC",
    'shop_asc'=>"o.shop_name ASC,$orderDateExpr DESC",
    'shop_desc'=>"o.shop_name DESC,$orderDateExpr DESC",
  ];
  if(!isset($sortMap[$orderSort]))$orderSort='date_desc';

  $where=["COALESCE(o.list_type,0)<>4"];
  $params=[];

  if($scope==='verified')$where[]="COALESCE(o.validation_state,'legacy') IN ($verifiedStates) AND COALESCE(o.purchase_state,'review')='purchase'";
  elseif($scope==='legacy')$where[]="COALESCE(o.validation_state,'legacy') NOT IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200')";

  if($q!==''){
    $where[]="(o.order_no LIKE :q OR o.shop_name LIKE :q OR EXISTS(SELECT 1 FROM order_items sx WHERE sx.order_id=o.id AND (sx.product_name LIKE :q OR sx.variant_name LIKE :q)))";
    $params[':q']='%'.$q.'%';
  }
  if($orderYear!==''){$where[]="substr($orderDateExpr,1,4)=:year";$params[':year']=$orderYear;}
  if($orderMonth!==''){$where[]="substr($orderDateExpr,6,2)=:month";$params[':month']=$orderMonth;}
  if($orderShop!==''){$where[]="o.shop_name=:shop";$params[':shop']=$orderShop;}

  if($orderStatus!==''){
    if($orderStatus==='unknown')$where[]="COALESCE(o.list_type,0)=0";
    elseif(in_array($orderStatus,['3','7','8','9','12'],true)){$where[]="o.list_type=:list_type";$params[':list_type']=(int)$orderStatus;}
  }

  $accountExpr="COALESCE(NULLIF(o.source_account_username,''),NULLIF(o.source_account_id,''),'')";
  if($orderAccount!==''){$where[]="$accountExpr=:account";$params[':account']=$orderAccount;}
  $obsoleteOnly=pan_optional_only_missing_sql('o');
  if($orderRepair==='done')$where[]="(COALESCE(o.detail_state,'')='complete' OR (COALESCE(o.detail_enriched,0)=1 AND COALESCE(o.detail_state,'')='partial' AND ($obsoleteOnly)))";
  elseif($orderRepair==='partial')$where[]="(COALESCE(o.detail_state,'')='partial' AND NOT (COALESCE(o.detail_enriched,0)=1 AND ($obsoleteOnly)))";
  elseif($orderRepair==='error')$where[]="COALESCE(o.detail_state,'')='error'";
  elseif($orderRepair==='pending')$where[]="COALESCE(o.detail_state,'pending')='pending'";
  if($orderMin!==null){$where[]="COALESCE(o.total_paid,0)>=:min_total";$params[':min_total']=$orderMin;}
  if($orderMax!==null){$where[]="COALESCE(o.total_paid,0)<=:max_total";$params[':max_total']=$orderMax;}

  $whereSql=' WHERE '.implode(' AND ',$where);

  $baseDateExpr=pan_order_placed_sql();
  $orderYears=$db->query("SELECT DISTINCT substr($baseDateExpr,1,4) y FROM orders WHERE COALESCE(list_type,0)<>4 AND length($baseDateExpr)>=4 AND substr($baseDateExpr,1,4) BETWEEN '2000' AND '2099' ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
  $orderShops=$db->query("SELECT DISTINCT shop_name FROM orders WHERE COALESCE(list_type,0)<>4 AND TRIM(COALESCE(shop_name,''))<>'' ORDER BY shop_name")->fetchAll(PDO::FETCH_COLUMN);
  $orderAccounts=$db->query("SELECT DISTINCT COALESCE(NULLIF(source_account_username,''),NULLIF(source_account_id,''),'') a FROM orders WHERE COALESCE(list_type,0)<>4 AND TRIM(COALESCE(NULLIF(source_account_username,''),NULLIF(source_account_id,''),''))<>'' ORDER BY a")->fetchAll(PDO::FETCH_COLUMN);

  $dbOrderCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE COALESCE(list_type,0)<>4")->fetchColumn();
  $dbItemCount=(int)$db->query('SELECT COUNT(*) FROM order_items')->fetchColumn();
  $verifiedCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE validation_state IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND purchase_state='purchase' AND COALESCE(list_type,0)<>4")->fetchColumn();
  $oldCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE COALESCE(validation_state,'legacy') NOT IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND COALESCE(list_type,0)<>4")->fetchColumn();

  $sumSql="SELECT COUNT(*) order_count,COALESCE(SUM(o.total_paid),0) total_paid,COUNT(DISTINCT NULLIF(o.shop_name,'')) shop_count FROM orders o".$whereSql;
  $sumSt=$db->prepare($sumSql);$sumSt->execute($params);$orderSummary=$sumSt->fetch()?:[];
  $filteredOrderCount=(int)($orderSummary['order_count']??0);
  $filteredPaid=(float)($orderSummary['total_paid']??0);
  $filteredShopCount=(int)($orderSummary['shop_count']??0);

  $qtySql="SELECT COALESCE(SUM(i.quantity),0) FROM order_items i JOIN orders o ON o.id=i.order_id".$whereSql;
  $qtySt=$db->prepare($qtySql);$qtySt->execute($params);$filteredQty=(int)$qtySt->fetchColumn();

  $ordersPages=max(1,(int)ceil($filteredOrderCount/$ordersPer));
  $ordersPage=min($ordersPage,$ordersPages);
  $ordersOffset=($ordersPage-1)*$ordersPer;

  $sql="SELECT o.*,COALESCE(ix.item_lines,0) item_lines,COALESCE(ix.qty,0) qty
    FROM orders o
    LEFT JOIN (
      SELECT order_id,COUNT(*) item_lines,COALESCE(SUM(quantity),0) qty
      FROM order_items GROUP BY order_id
    ) ix ON ix.order_id=o.id".
    $whereSql.
    " ORDER BY ".$sortMap[$orderSort]." LIMIT :lim OFFSET :off";
  $st=$db->prepare($sql);
  foreach($params as $k=>$v){
    if(is_int($v))$st->bindValue($k,$v,PDO::PARAM_INT);
    else $st->bindValue($k,$v);
  }
  $st->bindValue(':lim',$ordersPer,PDO::PARAM_INT);
  $st->bindValue(':off',$ordersOffset,PDO::PARAM_INT);
  $st->execute();
  $orders=$st->fetchAll();
}
if($page==='shops'){
  $per=max(10,min(100,(int)($_GET['per']??25)));
  $pageno=max(1,(int)($_GET['p']??1));
  $params=[];
  $where=" WHERE COALESCE(o.validation_state,'legacy') IN ($verifiedStates) AND COALESCE(o.purchase_state,'review')='purchase' AND COALESCE(o.list_type,0)<>4 AND TRIM(COALESCE(o.shop_name,''))<>''";
  if($q!==''){
    $where.=" AND o.shop_name LIKE :q";
    $params[':q']='%'.$q.'%';
  }

  $cnt=$db->prepare("SELECT COUNT(*) FROM (SELECT o.shop_name FROM orders o".$where." GROUP BY o.shop_name) s");
  $cnt->execute($params);
  $totalShops=(int)$cnt->fetchColumn();
  $pages=max(1,(int)ceil($totalShops/$per));
  $pageno=min($pageno,$pages);
  $offset=($pageno-1)*$per;

  $sql="SELECT
      o.shop_name,
      COUNT(DISTINCT o.id) order_count,
      COALESCE(SUM(oi.qty),0) total_qty,
      COALESCE(SUM(o.total_paid),0) total_spent,
      COALESCE(SUM(oi.product_count),0) product_lines,
      MAX($productDateExprO) latest_date
    FROM orders o
    LEFT JOIN (
      SELECT order_id,SUM(quantity) qty,COUNT(DISTINCT product_key) product_count
      FROM order_items
      GROUP BY order_id
    ) oi ON oi.order_id=o.id".
    $where.
    " GROUP BY o.shop_name
      ORDER BY order_count DESC,total_spent DESC,o.shop_name
      LIMIT :lim OFFSET :off";
  $st=$db->prepare($sql);
  foreach($params as $k=>$v)$st->bindValue($k,$v);
  $st->bindValue(':lim',$per,PDO::PARAM_INT);
  $st->bindValue(':off',$offset,PDO::PARAM_INT);
  $st->execute();
  $shops=$st->fetchAll();
}
if($page==='products'){
  $per=max(12,min(100,(int)($_GET['per']??24)));$pageno=max(1,(int)($_GET['p']??1));
  $productShop=trim((string)($_GET['shop']??''));$productCategory=trim((string)($_GET['category']??''));$productAccount=trim((string)($_GET['account']??''));$productYear=preg_match('/^20\d{2}$/',(string)($_GET['year']??''))?(string)$_GET['year']:'';$productSort=(string)($_GET['sort']??'latest');
  $sortMap=['latest'=>'latest_date DESC','spent'=>'total_spent DESC','orders'=>'purchase_count DESC','qty'=>'total_qty DESC','shops'=>'shop_count DESC','price_low'=>'lowest_price ASC','price_high'=>'latest_price DESC','name'=>'product_name ASC'];if(!isset($sortMap[$productSort]))$productSort='latest';
  $dateExpr=$productDateExprO;
  $familyExpr="COALESCE(NULLIF(i.product_family_key,''),i.product_key)";$catExpr="COALESCE(NULLIF(i.pan_category_name,''),NULLIF(i.marketplace_category_name,''),'ยังไม่จัดหมวด')";
  $where=["COALESCE(o.validation_state,'legacy') IN ($verifiedStates)","COALESCE(o.purchase_state,'review')='purchase'","COALESCE(o.list_type,0)<>4"];$params=[];
  if($q!==''){$where[]='(i.product_name LIKE :q OR i.variant_name LIKE :q OR o.shop_name LIKE :q OR '.$catExpr.' LIKE :q)';$params[':q']='%'.$q.'%';}
  if($productShop!==''){$where[]='o.shop_name=:pshop';$params[':pshop']=$productShop;}if($productCategory!==''){$where[]="$catExpr=:pcat";$params[':pcat']=$productCategory;}if($productAccount!==''){$where[]="COALESCE(NULLIF(o.source_account_username,''),NULLIF(o.source_account_id,''),'')=:pacc";$params[':pacc']=$productAccount;}if($productYear!==''){$where[]="substr($dateExpr,1,4)=:pyear";$params[':pyear']=$productYear;}
  $whereSql=' WHERE '.implode(' AND ',$where);
  $productShops=$db->query("SELECT DISTINCT shop_name FROM orders WHERE $purchaseWhere AND TRIM(COALESCE(shop_name,''))<>'' ORDER BY shop_name")->fetchAll(PDO::FETCH_COLUMN);
  $productCategories=$db->query("SELECT DISTINCT COALESCE(NULLIF(pan_category_name,''),NULLIF(marketplace_category_name,''),'ยังไม่จัดหมวด') c FROM order_items ORDER BY c")->fetchAll(PDO::FETCH_COLUMN);
  $productAccounts=$db->query("SELECT DISTINCT COALESCE(NULLIF(source_account_username,''),NULLIF(source_account_id,''),'') a FROM orders WHERE $purchaseWhere AND TRIM(COALESCE(NULLIF(source_account_username,''),NULLIF(source_account_id,''),''))<>'' ORDER BY a")->fetchAll(PDO::FETCH_COLUMN);
  $productYears=$db->query("SELECT DISTINCT substr($productDateExpr,1,4) y FROM orders WHERE $purchaseWhere AND length($productDateExpr)>=4 ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
  $cnt=$db->prepare("SELECT COUNT(*) FROM (SELECT $familyExpr family_key FROM order_items i JOIN orders o ON o.id=i.order_id $whereSql GROUP BY $familyExpr) z");$cnt->execute($params);$totalProducts=(int)$cnt->fetchColumn();$pages=max(1,(int)ceil($totalProducts/$per));$pageno=min($pageno,$pages);$offset=($pageno-1)*$per;
  $summary=$db->prepare("SELECT COUNT(DISTINCT $familyExpr) families,COUNT(DISTINCT NULLIF(o.shop_name,'')) shops,COUNT(DISTINCT $catExpr) categories,COALESCE(SUM(i.quantity),0) qty,COALESCE(SUM(CASE WHEN i.actual_line_total>0 THEN i.actual_line_total ELSE (CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END)*i.quantity END),0) spent,COUNT(DISTINCT CASE WHEN $dateExpr>=:recent THEN o.id END) recent_orders FROM order_items i JOIN orders o ON o.id=i.order_id $whereSql");$summaryParams=$params;$summaryParams[':recent']=date('Y-m-d',strtotime('-30 days'));$summary->execute($summaryParams);$productSummary=$summary->fetch()?:[];
  $sql="SELECT $familyExpr family_key,MAX(COALESCE(NULLIF(i.product_family_name,''),i.product_name)) product_name,MAX(i.variant_name) variant_name,MAX(i.image_url) image_url,MAX(i.product_url) product_url,MAX($catExpr) category_name,SUM(i.quantity) total_qty,COUNT(DISTINCT i.order_id) purchase_count,COUNT(DISTINCT NULLIF(o.shop_name,'')) shop_count,COALESCE(SUM(CASE WHEN i.actual_line_total>0 THEN i.actual_line_total ELSE (CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END)*i.quantity END),0) total_spent,MAX($dateExpr) latest_date,MIN(CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END) lowest_price,AVG(CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END) average_price,(SELECT CASE WHEN x.actual_unit_price>0 THEN x.actual_unit_price WHEN x.net_unit_price>0 THEN x.net_unit_price ELSE x.purchase_price END FROM order_items x JOIN orders xo ON xo.id=x.order_id WHERE COALESCE(NULLIF(x.product_family_key,''),x.product_key)=$familyExpr AND COALESCE(xo.validation_state,'legacy') IN ($verifiedStates) AND COALESCE(xo.purchase_state,'review')='purchase' AND COALESCE(xo.list_type,0)<>4 ORDER BY $productDateExprXO DESC,xo.id DESC,x.id DESC LIMIT 1) latest_price FROM order_items i JOIN orders o ON o.id=i.order_id $whereSql GROUP BY $familyExpr ORDER BY ".$sortMap[$productSort].",product_name LIMIT :lim OFFSET :off";
  $st=$db->prepare($sql);foreach($params as $k=>$v)$st->bindValue($k,$v);$st->bindValue(':lim',$per,PDO::PARAM_INT);$st->bindValue(':off',$offset,PDO::PARAM_INT);$st->execute();$products=$st->fetchAll();
}
if($page==='product'){
  $family=(string)($_GET['family']??'');$key=(string)($_GET['key']??'');$history=[];$pstats=null;$productHead=null;
  if($family!==''||$key!==''){$cond=$family!==''?"COALESCE(NULLIF(i.product_family_key,''),i.product_key)=:v":"i.product_key=:v";$st=$db->prepare("SELECT i.*,o.order_no,o.order_date,o.order_created_at,o.date_source,o.shop_name,o.total_paid,o.shipping_fee,o.voucher_discount,o.coins_discount,o.platform_discount,o.discount_total,o.merchandise_paid,o.pricing_method,o.list_type,o.purchase_state,o.validation_state FROM order_items i JOIN orders o ON o.id=i.order_id WHERE $cond AND COALESCE(o.validation_state,'legacy') IN ($verifiedStates) AND COALESCE(o.purchase_state,'review')='purchase' AND COALESCE(o.list_type,0)<>4 ORDER BY $productDateExprO DESC,o.id DESC");$st->execute([':v'=>$family!==''?$family:$key]);$history=$st->fetchAll();if($history){$productHead=$history[0];$prices=array_map('price_actual',$history);$qty=array_sum(array_map(fn($r)=>(int)$r['quantity'],$history));$pstats=['latest'=>$prices[0],'low'=>min($prices),'high'=>max($prices),'avg'=>array_sum($prices)/count($prices),'qty'=>$qty,'count'=>count(array_unique(array_column($history,'order_id'))),'sum'=>array_sum(array_map(fn($r)=>price_actual($r)*(int)$r['quantity'],$history)),'shops'=>count(array_unique(array_filter(array_column($history,'shop_name')))),'category'=>(string)($productHead['pan_category_name']?:$productHead['marketplace_category_name']?:'ยังไม่จัดหมวด')];}}
}
if($page==='settings'){
  $allItems=(int)$db->query("SELECT COUNT(*) FROM order_items")->fetchColumn();
  $allOrders=(int)$db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
  $resyncCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE validation_state='legacy_visible'")->fetchColumn();
  $legacyVisibleCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE validation_state='legacy_visible'")->fetchColumn();
}
if($page==='collector'){$review=$db->query('SELECT i.*,o.order_no,o.order_date,o.shop_name,o.validation_state,o.purchase_state,o.list_type FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.import_source IS NOT NULL ORDER BY i.needs_review DESC,o.order_date DESC,i.id DESC LIMIT 200')->fetchAll();$batches=$db->query('SELECT * FROM collector_batches ORDER BY id DESC LIMIT 10')->fetchAll();$suspiciousCount=(int)$db->query("SELECT COUNT(*) FROM orders WHERE validation_state='suspicious_legacy'")->fetchColumn();}
if($page==='accounts'){$accounts=$db->query("SELECT source_account_id account_id,MAX(source_account_username) username,COUNT(*) orders,COALESCE(SUM(total_paid),0) spent,MAX(updated_at) last_seen FROM orders WHERE COALESCE(source_account_id,'')<>'' AND COALESCE(list_type,0)<>4 GROUP BY source_account_id ORDER BY last_seen DESC")->fetchAll();}
if($page==='analytics'){
  $analytics=analytics_snapshot($db);
  $analyticsSummaryText=analytics_copy_summary($analytics);
  $analyticsPromptText=analytics_prompt_context($analytics);
  $analyticsJsonText=json_encode($analytics,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}
if($page==='export'){
  $exportCounts=[
    'orders'=>(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),
    'items'=>(int)$db->query('SELECT COUNT(*) FROM order_items')->fetchColumn(),
    'accounts'=>(int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn(),
    'batches'=>(int)$db->query('SELECT COUNT(*) FROM collector_batches')->fetchColumn(),
  ];
  $dbPath=db_sqlite_path_from_config(hub_db_config());
  $exportDbSize=($currentDbDriver==='sqlite'&&is_file($dbPath))?filesize($dbPath):0;
}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PAN — น้องแพน · Marketplace & Commerce Assistant</title>
<style>
:root{--bg:#f7f8fb;--card:#fff;--text:#151820;--muted:#717784;--line:#e6e8ed;--brand:#ee4d2d;--brand-soft:#fff0ec;--green:#009b68;--red:#df3f32;--shadow:0 1px 2px rgba(20,24,32,.03)}*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:var(--bg);color:var(--text);font-size:14px}a{text-decoration:none;color:inherit}.layout{display:grid;grid-template-columns:220px minmax(0,1fr);min-height:100vh}.sidebar{position:sticky;top:0;height:100vh;background:#fff;border-right:1px solid var(--line);padding:18px 14px;display:flex;flex-direction:column}.brand{display:flex;align-items:center;gap:9px;font-weight:800;padding:5px 8px 22px}.brand .bag{width:28px;height:28px;border-radius:7px;background:var(--brand);display:grid;place-items:center;color:#fff}.version{font-size:10px;background:var(--brand);color:#fff;padding:3px 5px;border-radius:5px;margin-left:auto}.nav a{display:flex;gap:10px;align-items:center;padding:10px 11px;border-radius:9px;color:#525866;margin:2px 0}.nav a:hover,.nav a.active{background:var(--brand-soft);color:var(--brand);font-weight:700}.nav .sep{height:1px;background:var(--line);margin:12px 5px}.collector-status{margin-top:auto;border:1px solid var(--line);border-radius:11px;padding:12px;font-size:12px}.collector-status b{color:var(--green)}.main{min-width:0;padding:26px 30px 50px}.content{width:min(1380px,100%);margin:0 auto}.top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}.title h1{font-size:22px;line-height:1.25;margin:0}.title p{font-size:12px;color:var(--muted);margin:5px 0 0}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--line);background:#fff;color:#252a34;border-radius:9px;padding:9px 13px;font-weight:700;cursor:pointer}.btn:hover{border-color:#c9cdd5}.btn.primary{background:var(--brand);color:#fff;border-color:var(--brand)}.panel{background:#fff;border:1px solid var(--line);border-radius:13px;padding:14px;box-shadow:var(--shadow)}.flash{margin-bottom:14px;background:#ecfdf5;border:1px solid #a7f3d0;color:#087557;border-radius:9px;padding:10px 12px}.toolbar{display:flex;gap:9px;align-items:center}.search{flex:1;min-width:0;border:1px solid var(--line);border-radius:9px;padding:10px 12px;outline:none}.search:focus{border-color:#bfc5ce}.count{margin-left:auto;background:#f5f6f8;padding:7px 10px;border-radius:999px;color:#606675;font-size:12px}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}.stat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px}.stat span{color:var(--muted);font-size:12px}.stat b{display:block;font-size:24px;margin-top:6px}.products-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:10px}.product-card{display:grid;grid-template-columns:66px minmax(0,1fr);gap:11px;min-height:128px;padding:12px;border:1px solid var(--line);border-radius:12px;background:#fff;transition:.15s}.product-card:hover{border-color:#cbd0d9;box-shadow:0 5px 16px rgba(20,24,32,.05);transform:translateY(-1px)}.thumb{width:66px;height:66px;border:1px solid var(--line);border-radius:9px;object-fit:cover;background:#f2f3f5}.product-name{font-size:13px;font-weight:750;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.variant{font-size:11px;color:var(--muted);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pill{display:inline-block;margin-top:7px;padding:4px 7px;background:#fff0eb;color:#d64a2d;border-radius:999px;font-size:11px;font-weight:750}.price-row{grid-column:1/-1;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:1px}.price-box b{display:block;font-size:13px}.price-box small{display:block;color:var(--muted);font-size:10px;margin-top:2px}.green{color:var(--green)}.pagination{display:flex;align-items:center;justify-content:center;gap:5px;margin:14px 0 2px}.pagebtn{min-width:34px;height:32px;padding:0 8px;display:grid;place-items:center;border:1px solid var(--line);background:#fff;border-radius:8px;font-size:12px}.pagebtn.active{border-color:#171b23;font-weight:800}.ellipsis{padding:0 6px;color:var(--muted)}.hero{display:grid;grid-template-columns:84px minmax(0,1fr);gap:15px}.hero .thumb{width:84px;height:84px}.hero-title{font-size:20px;font-weight:800;line-height:1.25}.metrics{grid-column:1/-1;display:grid;grid-template-columns:repeat(6,1fr);gap:9px;margin-top:3px}.metric{border:1px solid var(--line);border-radius:10px;padding:11px}.metric small{color:var(--muted);font-size:11px}.metric b{display:block;font-size:17px;margin-top:4px}.tabs{display:flex;gap:22px;border-bottom:1px solid var(--line);margin:16px 0 0}.tab{padding:10px 0;border-bottom:2px solid var(--brand);color:var(--brand);font-weight:750}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{padding:10px 9px;border-bottom:1px solid var(--line);text-align:left;font-size:12px}th{color:#666d79;font-size:11px;font-weight:700}.neg{color:var(--red);font-weight:700}.net{color:var(--green);font-weight:800}.note{font-size:11px;color:#67707e;margin-top:10px;padding:9px 11px;background:#f7f8fa;border-radius:8px}.orders-table .order{font-weight:800}.muted{color:var(--muted)}.collector-list{display:grid;gap:8px}.collector-item{border:1px solid var(--line);border-radius:10px;padding:11px}.review{color:#a16207;background:#fffbeb;padding:3px 6px;border-radius:6px;font-size:11px}@media(max-width:1050px){.products-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.metrics{grid-template-columns:repeat(3,1fr)}}@media(max-width:760px){.layout{grid-template-columns:1fr}.sidebar{display:none}.main{padding:18px 14px}.products-grid{grid-template-columns:1fr}.cards{grid-template-columns:repeat(2,1fr)}.metrics{grid-template-columns:repeat(2,1fr)}.toolbar{flex-wrap:wrap}.search{flex-basis:100%}}
.timeline-note{display:block;margin-top:3px;color:#6b7280;font-size:11px;line-height:1.3;font-weight:400}.orders-table td{vertical-align:top}
</style><link rel="stylesheet" href="./assets/dashboard.css?v=2.5.6"></head><body><div class="layout"><aside class="sidebar"><div class="brand"><span class="bag">I</span><span>PAN <small style="display:block;font-size:11px;font-weight:600;opacity:.72">น้องแพน · by itoom.work</small></span><span class="version">v2.5.6</span></div><nav class="nav">
<a class="<?=$page==='dashboard'?'active':''?>" href="./">⌂ หน้าแรก</a><a class="<?=$page==='orders'?'active':''?>" href="?page=orders">▤ คำสั่งซื้อ</a><a class="<?=in_array($page,['products','product'])?'active':''?>" href="?page=products">◉ สินค้า / ประวัติราคา</a><a class="<?=$page==='shops'?'active':''?>" href="?page=shops">▦ ร้าน</a><a class="<?=$page==='analytics'?'active':''?>" href="?page=analytics">▥ สรุป / Analytics</a><a class="<?=$page==='export'?'active':''?>" href="?page=export">⇧ ส่งออกข้อมูล</a><a href="?page=collector" class="<?=$page==='collector'?'active':''?>">⇩ รายงาน / Collector</a><a href="?page=accounts" class="<?=($page==='accounts'?'active':'')?>">👥 บัญชีที่เคย Sync</a>
<a class="<?=($page==='settings'?'active':'')?>" href="?page=settings"><span>⚙</span><span>ตั้งค่า / จัดการข้อมูล</span></a><div class="sep"></div><a href="./shopee.php"><span>↻</span><span>เชื่อม Shopee บนเซิร์ฟเวอร์</span></a><a href="./database.php"><span>◫</span><span>Database Manager</span></a><a href="./logout.php"><span>↪</span><span>ออกจากระบบ</span></a></nav><div class="collector-status">ฐานข้อมูล <b><?=strtoupper(h($currentDbDriver))?></b><br><span class="muted">Collector เชื่อมผ่าน API Key</span></div></aside><main class="main"><div class="content">
<?php if($flash):?><div class="flash"><?=h($flash)?></div><?php endif;?>
<?php if($page==='dashboard'):?>
<div class="dashboard-hero">
  <div class="title"><h1>ภาพรวมการซื้อของฉัน</h1><p>สรุปคำสั่งซื้อและแนวโน้มการใช้จ่ายจาก Shopee TH</p></div>
  <div class="actions">
    <a class="btn" href="?page=settings">จัดการ / ลบข้อมูล</a>
    <a class="btn primary" href="?page=collector">เปิด Collector</a>
  </div>
</div>

<?php if(($legacyCount??0)>0):?>
<div class="panel" style="margin-bottom:14px;border-color:#fed7aa;background:#fffaf0">
  <b>มีข้อมูลเก่ารอ Sync <?=number_format($legacyCount)?> Order</b>
  <p class="muted">ข้อมูลยังอยู่ในฐาน แต่ยังไม่ถูกนำมาคำนวณสถิติหลักจนกว่าจะ Sync ยืนยันใหม่</p>
  <a class="btn" href="?page=orders&scope=legacy">เปิดดูข้อมูลเก่า</a>
</div>
<?php endif;?>

<div class="metric-grid">
  <div class="metric-card">
    <div class="label">คำสั่งซื้อจริงใน PAN</div>
    <div class="value"><?=number_format($stats['orders'])?></div>
    <div class="sub">Unique Order · ไม่รวมรายการยกเลิก</div>
    <div class="sub">สำเร็จแล้ว <?=number_format($purchaseStatusCounts[3])?> · กำลังจัดส่ง <?=number_format($purchaseStatusCounts[7])?> · กำลังนำส่ง <?=number_format($purchaseStatusCounts[8])?></div>
  </div>
  <div class="metric-card">
    <div class="label">จำนวนชิ้นที่ซื้อ</div>
    <div class="value"><?=number_format($stats['items'])?></div>
    <div class="sub">รวมจำนวนสินค้าในทุก Order</div>
  </div>
  <div class="metric-card money">
    <div class="label">ยอดจ่ายรวม</div>
    <div class="value"><?=money($stats['spent'])?></div>
    <div class="sub">จากยอดจ่ายจริงระดับ Order</div>
  </div>
  <div class="metric-card warn">
    <div class="label">สินค้าที่ไม่ซ้ำ</div>
    <div class="value"><?=number_format($stats['products'])?></div>
    <div class="sub">ใช้สำหรับติดตามประวัติราคา</div>
  </div>
</div>

<div class="panel chart-panel">
  <div class="chart-toolbar">
    <div class="chart-title">
      <h3>กราฟยอดซื้อ</h3><div class="muted" style="margin-top:3px">ใช้เฉพาะวันที่สร้างคำสั่งซื้อ · ไม่ใช้วันชำระเงิน วันส่งพัสดุ หรือวัน Complete แทน · แสดงเฉพาะ Order สำเร็จแล้ว</div>
      <p><?=$chartMode==='month'?'ยอดซื้อรายวันของเดือนที่เลือก':'ยอดซื้อรายเดือนของปีที่เลือก'?></p>
    </div>
    <form class="chart-controls" method="get">
      <input type="hidden" name="page" value="dashboard">
      <input type="hidden" name="chart_mode" value="<?=h($chartMode)?>">
      <div class="seg">
        <a class="<?=$chartMode==='month'?'active':''?>" href="?page=dashboard&chart_mode=month&chart_year=<?=$chartYear?>&chart_month=<?=$chartMonth?>">เดือน</a>
        <a class="<?=$chartMode==='year'?'active':''?>" href="?page=dashboard&chart_mode=year&chart_year=<?=$chartYear?>">ปี</a>
      </div>
      <?php if($chartMode==='month'):?>
      <select name="chart_month">
        <?php $monthNames=['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];for($m=1;$m<=12;$m++):?>
        <option value="<?=$m?>" <?=$m===$chartMonth?'selected':''?>><?=$monthNames[$m-1]?></option>
        <?php endfor;?>
      </select>
      <?php endif;?>
      <select name="chart_year">
        <?php for($y=(int)date('Y');$y>=2016;$y--):?>
        <option value="<?=$y?>" <?=$y===$chartYear?'selected':''?>><?=$y?></option>
        <?php endfor;?>
      </select>
      <button class="btn" type="submit">ดูข้อมูล</button>
    </form>
  </div>
  <div class="chart-summary">
    <div class="x"><b><?=money($chartTotal)?></b><span>ยอดซื้อช่วงที่เลือก</span></div>
    <div class="x"><b><?=number_format($chartOrderTotal)?></b><span>จำนวน Order</span></div>
    <div class="x"><b><?=money($chartAvg)?></b><span>เฉลี่ยต่อ Order</span></div>
  </div>
  <div class="muted" style="font-size:11px;margin:-4px 0 8px">แหล่งวันที่: สร้าง Order <?=number_format($chartSourceStats['created'])?> · วันที่สั่ง <?=number_format($chartSourceStats['order_date'])?> · ไม่มีหลักฐานวันที่สั่งจะไม่ถูกนับในกราฟ</div>
  <div class="chart-wrap"><canvas id="purchaseChart"></canvas></div>
</div>

<div class="dashboard-grid">
  <div class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div><h3 style="margin:0">คำสั่งซื้อล่าสุด</h3><p class="muted" style="margin:4px 0 0">รายการที่ยืนยันแล้วล่าสุด</p></div>
      <a href="?page=orders&scope=purchase">ดูทั้งหมด</a>
    </div>
    <div class="mini-list">
      <?php foreach($recentOrders as $o):?>
      <div class="mini-row">
        <div class="date"><?=h(pan_order_placed_view($o)['value'])?></div>
        <div><div class="shop"><?=h($o['shop_name'])?></div><div class="muted" style="font-size:12px">#<?=h($o['order_no'])?> · <?=h(order_status_th($o['order_status']??'', $o['list_type']??null))?></div></div>
        <div class="amt"><?=money($o['total_paid'])?></div>
      </div>
      <?php endforeach;?>
      <?php if(!$recentOrders):?><div class="muted" style="padding:18px 0">ยังไม่มีคำสั่งซื้อที่ยืนยันแล้ว</div><?php endif;?>
    </div>
  </div>

  <div class="panel">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div><h3 style="margin:0">สินค้าที่ซื้อบ่อย</h3><p class="muted" style="margin:4px 0 0">เรียงตามจำนวนครั้งที่ซื้อ</p></div>
      <a href="?page=products">ดูทั้งหมด</a>
    </div>
    <?php foreach($topProducts as $p):?>
      <div class="top-product-row">
        <img src="<?=h($p['image_url']?:'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2246%22 height=%2246%22%3E%3Crect width=%2246%22 height=%2246%22 fill=%22%23f1f5f9%22/%3E%3C/svg%3E')?>" alt="">
        <div><div class="pn"><?=h($p['product_name'])?></div><div class="meta"><?=number_format($p['qty'])?> ชิ้น · <?=number_format($p['purchase_count'])?> ครั้ง</div></div>
        <div class="count"><?=number_format($p['purchase_count'])?>×</div>
      </div>
    <?php endforeach;?>
    <?php if(!$topProducts):?><div class="muted" style="padding:18px 0">ยังไม่มีข้อมูลสินค้า</div><?php endif;?>
  </div>
</div>

<script>
(function(){
  const c=document.getElementById('purchaseChart'); if(!c)return;
  const labels=<?=json_encode($chartLabels,JSON_UNESCAPED_UNICODE)?>;
  const values=<?=json_encode($chartValues,JSON_UNESCAPED_UNICODE)?>;
  const orders=<?=json_encode($chartOrders,JSON_UNESCAPED_UNICODE)?>;
  const dpr=window.devicePixelRatio||1;
  function draw(){
    const rect=c.getBoundingClientRect(),w=Math.max(320,rect.width),h=Math.max(220,rect.height);
    c.width=w*dpr;c.height=h*dpr;
    const ctx=c.getContext('2d');ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,w,h);
    const pad={l:54,r:18,t:18,b:38},cw=w-pad.l-pad.r,ch=h-pad.t-pad.b;
    const max=Math.max(1,...values); const steps=4;
    ctx.font='12px system-ui';ctx.textBaseline='middle';
    for(let i=0;i<=steps;i++){
      const y=pad.t+ch*(i/steps),val=max*(1-i/steps);
      ctx.strokeStyle='#e8edf4';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(pad.l,y);ctx.lineTo(w-pad.r,y);ctx.stroke();
      ctx.fillStyle='#94a3b8';ctx.textAlign='right';ctx.fillText('฿'+Math.round(val).toLocaleString(),pad.l-8,y);
    }
    const n=labels.length,stepX=n>1?cw/(n-1):cw;
    const points=[];
    values.forEach((v,i)=>{points.push({x:pad.l+(n>1?i*stepX:cw/2),y:pad.t+ch-(v/max)*ch,v,i});});
    if(points.length){
      ctx.beginPath();points.forEach((p,i)=>i?ctx.lineTo(p.x,p.y):ctx.moveTo(p.x,p.y));ctx.lineTo(points[points.length-1].x,pad.t+ch);ctx.lineTo(points[0].x,pad.t+ch);ctx.closePath();
      const g=ctx.createLinearGradient(0,pad.t,0,pad.t+ch);g.addColorStop(0,'rgba(244,81,44,.22)');g.addColorStop(1,'rgba(244,81,44,.02)');ctx.fillStyle=g;ctx.fill();
      ctx.beginPath();points.forEach((p,i)=>i?ctx.lineTo(p.x,p.y):ctx.moveTo(p.x,p.y));ctx.strokeStyle='#f4512c';ctx.lineWidth=2.5;ctx.stroke();
      points.forEach(p=>{ctx.beginPath();ctx.arc(p.x,p.y,3.2,0,Math.PI*2);ctx.fillStyle='#fff';ctx.fill();ctx.strokeStyle='#f4512c';ctx.lineWidth=2;ctx.stroke();});
    }
    const every=Math.max(1,Math.ceil(n/12));
    ctx.fillStyle='#64748b';ctx.textAlign='center';ctx.textBaseline='top';
    labels.forEach((lab,i)=>{if(i%every===0||i===n-1){const x=pad.l+(n>1?i*stepX:cw/2);ctx.fillText(lab,x,pad.t+ch+10);}});
    c._pts=points;c._labels=labels;c._values=values;c._orders=orders;c._pad=pad;c._dpr=dpr;
  }
  draw();window.addEventListener('resize',draw);
  c.addEventListener('mousemove',function(e){
    const pts=c._pts||[];if(!pts.length)return;
    const r=c.getBoundingClientRect(),mx=e.clientX-r.left;
    let best=pts[0];for(const p of pts)if(Math.abs(p.x-mx)<Math.abs(best.x-mx))best=p;
    c.title=`${c._labels[best.i]} · ฿${Number(c._values[best.i]).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})} · ${c._orders[best.i]} Order`;
  });
})();
</script>

<?php elseif($page==='orders'):?>
<div class="page-hero orders-hero">
  <div>
    <div class="eyebrow">PURCHASE HISTORY</div>
    <h1>คำสั่งซื้อ</h1>
    <p>กรองข้อมูลหลายเงื่อนไขพร้อมกัน ดูยอดรวมทันที และเปิดร้านจากผลลัพธ์ได้</p>
  </div>
  <div class="hero-badge"><b><?=number_format($filteredOrderCount??0)?></b><span>Order ที่พบ</span></div>
</div>

<div class="filter-card">
  <div class="filter-card-head">
    <div><h3>ตัวกรองคำสั่งซื้อ</h3><p>เลือกเฉพาะที่ต้องการ แล้วกด “ใช้ตัวกรอง”</p></div>
    <a class="btn ghost" href="?page=orders">ล้างทั้งหมด</a>
  </div>
  <form method="get" id="ordersFilterForm">
    <input type="hidden" name="page" value="orders">
    <div class="filter-grid filter-grid-primary">
      <label class="field field-search"><span>ค้นหา</span><input name="q" value="<?=h($q)?>" placeholder="เลข Order / ร้าน / สินค้า / ตัวเลือกสินค้า"></label>
      <label class="field"><span>ปี</span><select name="year"><option value="">ทุกปี</option><?php foreach(($orderYears??[]) as $y):?><option value="<?=h($y)?>" <?=$orderYear===$y?'selected':''?>><?=h($y)?></option><?php endforeach;?></select></label>
      <label class="field"><span>เดือน</span><select name="month"><option value="">ทุกเดือน</option><?php $months=['01'=>'มกราคม','02'=>'กุมภาพันธ์','03'=>'มีนาคม','04'=>'เมษายน','05'=>'พฤษภาคม','06'=>'มิถุนายน','07'=>'กรกฎาคม','08'=>'สิงหาคม','09'=>'กันยายน','10'=>'ตุลาคม','11'=>'พฤศจิกายน','12'=>'ธันวาคม'];foreach($months as $mn=>$ml):?><option value="<?=$mn?>" <?=$orderMonth===$mn?'selected':''?>><?=$ml?></option><?php endforeach;?></select></label>
      <label class="field"><span>ร้าน</span><select name="shop"><option value="">ทุกร้าน</option><?php foreach(($orderShops??[]) as $v):?><option value="<?=h($v)?>" <?=$orderShop===$v?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label>
      <label class="field"><span>สถานะ</span><select name="status"><option value="">ทุกสถานะ</option><option value="3" <?=$orderStatus==='3'?'selected':''?>>สำเร็จแล้ว</option><option value="7" <?=$orderStatus==='7'?'selected':''?>>กำลังจัดส่ง</option><option value="8" <?=$orderStatus==='8'?'selected':''?>>กำลังนำส่ง</option><option value="12" <?=$orderStatus==='12'?'selected':''?>>คืนสินค้า/คืนเงิน</option><option value="9" <?=$orderStatus==='9'?'selected':''?>>ยังไม่ชำระ</option><option value="unknown" <?=$orderStatus==='unknown'?'selected':''?>>ไม่ทราบสถานะ</option></select></label>
    </div>
    <details class="advanced-filter" <?=($orderAccount!==''||$orderRepair!==''||$scope!=='all'||$orderMin!==null||$orderMax!==null||$orderSort!=='date_desc'||$ordersPer!==50)?'open':''?>>
      <summary>ตัวกรองเพิ่มเติม <span>บัญชี · Detail · ยอดเงิน · การเรียง</span></summary>
      <div class="filter-grid filter-grid-secondary">
        <label class="field"><span>บัญชี Shopee</span><select name="account"><option value="">ทุกบัญชี</option><?php foreach(($orderAccounts??[]) as $v):?><option value="<?=h($v)?>" <?=$orderAccount===$v?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label>
        <label class="field"><span>Order Detail</span><select name="repair"><option value="">ทั้งหมด</option><option value="done" <?=$orderRepair==='done'?'selected':''?>>ตรวจ Detail แล้ว</option><option value="partial" <?=$orderRepair==='partial'?'selected':''?>>Detail บางส่วน</option><option value="error" <?=$orderRepair==='error'?'selected':''?>>Detail ผิดพลาด</option><option value="pending" <?=$orderRepair==='pending'?'selected':''?>>รอเติม Detail</option></select></label>
        <label class="field"><span>ประเภทข้อมูล</span><select name="scope"><option value="all" <?=$scope==='all'?'selected':''?>>ทั้งหมด (<?=number_format($dbOrderCount??0)?>)</option><option value="verified" <?=$scope==='verified'?'selected':''?>>ยืนยันแล้ว (<?=number_format($verifiedCount??0)?>)</option><option value="legacy" <?=$scope==='legacy'?'selected':''?>>ข้อมูลเก่า (<?=number_format($oldCount??0)?>)</option></select></label>
        <label class="field"><span>ยอดจ่ายขั้นต่ำ</span><input name="min_total" inputmode="decimal" value="<?=h($orderMinRaw)?>" placeholder="0"></label>
        <label class="field"><span>ยอดจ่ายสูงสุด</span><input name="max_total" inputmode="decimal" value="<?=h($orderMaxRaw)?>" placeholder="ไม่จำกัด"></label>
        <label class="field"><span>เรียงตาม</span><select name="sort"><option value="date_desc" <?=$orderSort==='date_desc'?'selected':''?>>วันที่ล่าสุดก่อน</option><option value="date_asc" <?=$orderSort==='date_asc'?'selected':''?>>วันที่เก่าสุดก่อน</option><option value="paid_desc" <?=$orderSort==='paid_desc'?'selected':''?>>ยอดจ่าย สูง → ต่ำ</option><option value="paid_asc" <?=$orderSort==='paid_asc'?'selected':''?>>ยอดจ่าย ต่ำ → สูง</option><option value="shop_asc" <?=$orderSort==='shop_asc'?'selected':''?>>ร้าน A → Z</option><option value="shop_desc" <?=$orderSort==='shop_desc'?'selected':''?>>ร้าน Z → A</option></select></label>
        <label class="field"><span>จำนวนต่อหน้า</span><select name="per"><?php foreach([10,25,50,100,200] as $n):?><option value="<?=$n?>" <?=$ordersPer===$n?'selected':''?>><?=$n?> / หน้า</option><?php endforeach;?></select></label>
      </div>
    </details>
    <div class="filter-actions"><button class="btn primary" type="submit">ใช้ตัวกรอง</button><a class="btn" href="?page=analytics">ดูสรุป Analytics</a><a class="btn" href="?page=export">ส่งออกข้อมูล</a></div>
  </form>
</div>

<div class="summary-strip">
  <div class="summary-card"><span>Order</span><b><?=number_format($filteredOrderCount??0)?></b><small>ตรงตัวกรอง</small></div>
  <div class="summary-card"><span>จำนวนชิ้น</span><b><?=number_format($filteredQty??0)?></b><small>รวมทุกสินค้า</small></div>
  <div class="summary-card"><span>จำนวนร้าน</span><b><?=number_format($filteredShopCount??0)?></b><small>ร้านไม่ซ้ำ</small></div>
  <div class="summary-card accent"><span>ยอดจ่ายรวม</span><b><?=money($filteredPaid??0)?></b><small>ตามตัวกรองนี้</small></div>
</div>

<div class="panel orders-panel">
<?php if(empty($orders)):?>
  <div class="empty-state"><div class="empty-icon">⌕</div><h3>ไม่พบ Order ที่ตรงกับตัวกรอง</h3><p>ลองลดเงื่อนไขบางตัว หรือกด “ล้างทั้งหมด”</p></div>
<?php else:?>
  <div class="table-topline"><span>แสดง <?=number_format($ordersOffset+1)?>–<?=number_format(min($ordersOffset+$ordersPer,$filteredOrderCount))?> จาก <?=number_format($filteredOrderCount)?> Order</span><span>ยอดรวม <?=money($filteredPaid??0)?></span></div>
  <div class="table-wrap"><table class="orders-table modern-table"><thead><tr><th title="วันที่สร้างคำสั่งซื้อ · เวลาแสดงเฉพาะเมื่อ Shopee มีเวลาจริง">วันที่สั่งซื้อ</th><th>Order</th><th>ร้าน</th><th class="num">ชิ้น</th><th class="num">ราคาหน้าร้าน</th><th class="num">ส่วนลด</th><th class="num">จ่ายจริง</th><th title="สถานะคำสั่งซื้อจาก Shopee ไม่ใช่หลักฐานวันที่ขนส่งนำส่งถึงผู้รับ">สถานะ</th><th>บัญชี</th><th>Detail</th></tr></thead><tbody>
  <?php foreach($orders as $o):?><tr>
    <?php $placedView=pan_order_placed_view($o);?>
    <td title="<?=h($placedView['title'])?>"><b><?=h($placedView['value'])?></b><?php if($placedView['note']!==''):?><small class="timeline-note"><?=h($placedView['note'])?></small><?php endif;?></td>
    
    <td class="order"><?=h($o['order_no'])?></td>
    <td><a class="shop-link" href="?page=orders&shop=<?=rawurlencode((string)$o['shop_name'])?>"><?=h($o['shop_name'])?></a></td>
    <td class="num"><?=number_format((int)$o['qty'])?></td><td class="num"><?=money($o['raw_subtotal']?:$o['subtotal'])?></td><td class="num neg"><?=(float)$o['discount_total']>0?'-'.money($o['discount_total']):money(0)?></td><td class="num"><b><?=money($o['total_paid'])?></b></td>
    <td><span class="status-chip status-<?=h((string)($o['list_type']??0))?>"><?=h(order_status_th($o['order_status']??'', $o['list_type']??null))?></span></td>
    <td><?=h($o['source_account_username']?:$o['source_account_id'])?></td>
    <td><?php $ds=detail_state_label($o);if($ds==='ตรวจแล้ว'):?><span class="ok-chip">✓ ตรวจ Detail แล้ว</span><?php elseif($ds==='บางส่วน'):?><span class="pending-chip">Detail บางส่วน</span><?php elseif($ds==='ผิดพลาด'):?><span class="pending-chip">Detail ผิดพลาด</span><?php else:?><span class="pending-chip">รอเติม Detail</span><?php endif;?></td>
  </tr><?php endforeach;?></tbody></table></div>
  <?php if(($ordersPages??1)>1):?><?php $oq=$_GET;$oq['page']='orders';unset($oq['p']);$pageUrl=function(int $n)use($oq){$q2=$oq;$q2['p']=$n;return '?'.http_build_query($q2);};$from=max(1,$ordersPage-2);$to=min($ordersPages,$ordersPage+2);?><div class="pagination"><?php if($ordersPage>1):?><a class="pagebtn" href="<?=h($pageUrl($ordersPage-1))?>">‹</a><?php endif;?><?php if($from>1):?><a class="pagebtn" href="<?=h($pageUrl(1))?>">1</a><?php if($from>2):?><span class="ellipsis">…</span><?php endif;?><?php endif;?><?php for($n=$from;$n<=$to;$n++):?><a class="pagebtn <?=$n===$ordersPage?'active':''?>" href="<?=h($pageUrl($n))?>"><?=$n?></a><?php endfor;?><?php if($to<$ordersPages):?><?php if($to<$ordersPages-1):?><span class="ellipsis">…</span><?php endif;?><a class="pagebtn" href="<?=h($pageUrl($ordersPages))?>"><?=$ordersPages?></a><?php endif;?><?php if($ordersPage<$ordersPages):?><a class="pagebtn" href="<?=h($pageUrl($ordersPage+1))?>">›</a><?php endif;?></div><?php endif;?>
<?php endif;?></div>

<?php elseif($page==='shops'):?>
<div class="top">
  <div class="title">
    <h1>ร้าน</h1>
    <p>รวมร้านค้าที่เคยซื้อจาก Order ที่ยืนยันแล้ว · ไม่รวมรายการยกเลิก</p>
  </div>
  <div class="count"><?=number_format($totalShops??0)?> ร้าน</div>
</div>

<div class="panel">
  <form class="toolbar" method="get">
    <input type="hidden" name="page" value="shops">
    <input class="search" name="q" value="<?=h($q)?>" placeholder="ค้นหาชื่อร้าน...">
    <select name="per" style="padding:9px;border:1px solid var(--line);border-radius:9px">
      <option value="10" <?=$per===10?'selected':''?>>10 / หน้า</option>
      <option value="25" <?=$per===25?'selected':''?>>25 / หน้า</option>
      <option value="50" <?=$per===50?'selected':''?>>50 / หน้า</option>
      <option value="100" <?=$per===100?'selected':''?>>100 / หน้า</option>
    </select>
    <button class="btn">ค้นหา</button>
  </form>

  <?php if(empty($shops)):?>
    <div style="text-align:center;padding:42px 10px">
      <h3 style="margin:0 0 7px">ยังไม่พบร้าน</h3>
      <div class="muted">ร้านจะปรากฏเมื่อมี Order ที่ยืนยันแล้วจาก Collector</div>
    </div>
  <?php else:?>
    <div class="table-wrap" style="margin-top:12px">
      <table>
        <thead>
          <tr>
            <th>ร้าน</th>
            <th>Order</th>
            <th>จำนวนชิ้น</th>
            <th>สินค้า</th>
            <th>ยอดซื้อรวม</th>
            <th>ซื้อล่าสุด</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($shops as $s):?>
          <tr>
            <td><b><?=h($s['shop_name'])?></b></td>
            <td><?=number_format((int)$s['order_count'])?></td>
            <td><?=number_format((int)$s['total_qty'])?></td>
            <td><?=number_format((int)$s['product_lines'])?></td>
            <td><b><?=money((float)$s['total_spent'])?></b></td>
            <td><?=h(show_order_date((string)($s['latest_date']??'')))?></td>
            <td>
              <div style="display:flex;gap:6px;flex-wrap:wrap">
                <a class="btn" href="?page=orders&q=<?=rawurlencode((string)$s['shop_name'])?>">คำสั่งซื้อ</a>
                <a class="btn" href="?page=products&q=<?=rawurlencode((string)$s['shop_name'])?>">สินค้า</a>
              </div>
            </td>
          </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>

    <?php if(($pages??1)>1):?>
      <div class="pagination">
        <?php if($pageno>1):?><a class="pagebtn" href="?page=shops&p=<?=$pageno-1?>&per=<?=$per?>&q=<?=rawurlencode($q)?>">‹</a><?php endif;?>
        <?php
          $from=max(1,$pageno-2);
          $to=min($pages,$pageno+2);
        ?>
        <?php if($from>1):?>
          <a class="pagebtn" href="?page=shops&p=1&per=<?=$per?>&q=<?=rawurlencode($q)?>">1</a>
          <?php if($from>2):?><span class="ellipsis">…</span><?php endif;?>
        <?php endif;?>
        <?php for($n=$from;$n<=$to;$n++):?>
          <a class="pagebtn <?=$n===$pageno?'active':''?>" href="?page=shops&p=<?=$n?>&per=<?=$per?>&q=<?=rawurlencode($q)?>"><?=$n?></a>
        <?php endfor;?>
        <?php if($to<$pages):?>
          <?php if($to<$pages-1):?><span class="ellipsis">…</span><?php endif;?>
          <a class="pagebtn" href="?page=shops&p=<?=$pages?>&per=<?=$per?>&q=<?=rawurlencode($q)?>"><?=$pages?></a>
        <?php endif;?>
        <?php if($pageno<$pages):?><a class="pagebtn" href="?page=shops&p=<?=$pageno+1?>&per=<?=$per?>&q=<?=rawurlencode($q)?>">›</a><?php endif;?>
      </div>
    <?php endif;?>
  <?php endif;?>
</div>

<?php elseif($page==='products'):?>
<div class="page-hero analytics-hero"><div><div class="eyebrow">PRODUCT EXPLORER 2.0</div><h1>สินค้า / ประวัติราคา</h1><p>ค้นหา กรอง เปรียบเทียบร้าน หมวด ราคา และพฤติกรรมการซื้อจากข้อมูลจริงใน PAN</p></div><div class="hero-badge"><b><?=number_format($totalProducts??0)?></b><span>กลุ่มสินค้า</span></div></div>
<div class="summary-strip">
 <div class="summary-card"><span>สินค้า</span><b><?=number_format($productSummary['families']??0)?></b><small>Product Family</small></div><div class="summary-card"><span>ร้าน</span><b><?=number_format($productSummary['shops']??0)?></b><small>ร้านไม่ซ้ำ</small></div><div class="summary-card"><span>หมวด</span><b><?=number_format($productSummary['categories']??0)?></b><small>รวมยังไม่จัดหมวด</small></div><div class="summary-card"><span>จำนวนซื้อ</span><b><?=number_format($productSummary['qty']??0)?></b><small>ชิ้น</small></div><div class="summary-card accent"><span>ยอดซื้อสินค้า</span><b><?=money($productSummary['spent']??0)?></b><small>ตามตัวกรอง</small></div><div class="summary-card"><span>30 วันล่าสุด</span><b><?=number_format($productSummary['recent_orders']??0)?></b><small>Order</small></div>
</div>
<div class="filter-card"><div class="filter-card-head"><div><h3>ค้นหาและจัดมุมมอง</h3><p>ตัวกรองทำงานร่วมกันได้ทั้งหมด</p></div><a class="btn ghost" href="?page=products">ล้างทั้งหมด</a></div><form method="get"><input type="hidden" name="page" value="products"><div class="filter-grid filter-grid-primary"><label class="field field-search"><span>ค้นหา</span><input name="q" value="<?=h($q)?>" placeholder="สินค้า รุ่น ร้าน หรือหมวด"></label><label class="field"><span>ร้าน</span><select name="shop"><option value="">ทุกร้าน</option><?php foreach($productShops as $v):?><option value="<?=h($v)?>" <?=$productShop===$v?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label><label class="field"><span>หมวด</span><select name="category"><option value="">ทุกหมวด</option><?php foreach($productCategories as $v):?><option value="<?=h($v)?>" <?=$productCategory===$v?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label><label class="field"><span>บัญชี</span><select name="account"><option value="">ทุกบัญชี</option><?php foreach($productAccounts as $v):?><option value="<?=h($v)?>" <?=$productAccount===$v?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label><label class="field"><span>ปี</span><select name="year"><option value="">ทุกปี</option><?php foreach($productYears as $v):?><option value="<?=h($v)?>" <?=$productYear===$v?'selected':''?>><?=h($v)?></option><?php endforeach;?></select></label><label class="field"><span>เรียงตาม</span><select name="sort"><option value="latest" <?=$productSort==='latest'?'selected':''?>>สั่งซื้อล่าสุด</option><option value="spent" <?=$productSort==='spent'?'selected':''?>>ยอดซื้อ สูง → ต่ำ</option><option value="orders" <?=$productSort==='orders'?'selected':''?>>ซื้อบ่อยที่สุด</option><option value="qty" <?=$productSort==='qty'?'selected':''?>>จำนวนชิ้นมากที่สุด</option><option value="shops" <?=$productSort==='shops'?'selected':''?>>จำนวนร้านมากที่สุด</option><option value="price_low" <?=$productSort==='price_low'?'selected':''?>>ราคาต่ำสุด</option><option value="price_high" <?=$productSort==='price_high'?'selected':''?>>ราคาล่าสุด สูง → ต่ำ</option><option value="name" <?=$productSort==='name'?'selected':''?>>ชื่อ A → Z</option></select></label><label class="field"><span>ต่อหน้า</span><select name="per"><?php foreach([12,24,36,60,100] as $n):?><option value="<?=$n?>" <?=$per===$n?'selected':''?>><?=$n?></option><?php endforeach;?></select></label></div><div class="filter-actions"><button class="btn primary">ใช้ตัวกรอง</button></div></form></div>
<div class="panel">
<?php if(empty($products)):?><div class="empty-state"><div class="empty-icon">⌕</div><h3>ไม่พบสินค้าที่ตรงเงื่อนไข</h3><p>หมวดที่ยังไม่มีข้อมูลจะแสดงเป็น “ยังไม่จัดหมวด” และจะถูกเติมเมื่อ Shopee ส่ง category มากับข้อมูลสินค้า</p></div><?php else:?><div class="products-grid">
<?php foreach($products as $p):?><a class="product-card" href="?page=product&family=<?=rawurlencode((string)$p['family_key'])?>"><img class="thumb" src="<?=h($p['image_url']?:'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2266%22 height=%2266%22%3E%3Crect width=%2266%22 height=%2266%22 fill=%22%23f1f5f9%22/%3E%3C/svg%3E')?>" alt=""><div><div class="product-name"><?=h($p['product_name'])?></div><div class="variant"><?=h($p['category_name'])?> · ล่าสุด <?=h(substr((string)$p['latest_date'],0,10)?:'-')?></div><span class="pill"><?=number_format($p['purchase_count'])?> ครั้ง · <?=number_format($p['total_qty'])?> ชิ้น · <?=number_format($p['shop_count'])?> ร้าน</span></div><div class="price-row"><div class="price-box"><b><?=money($p['latest_price'])?></b><small>ราคาล่าสุด</small></div><div class="price-box"><b class="green"><?=money($p['lowest_price'])?></b><small>ต่ำสุด</small></div><div class="price-box"><b><?=money($p['total_spent'])?></b><small>ยอดซื้อรวม</small></div></div></a><?php endforeach;?></div>
<?php if(($pages??1)>1):?><?php $pq=$_GET;$pq['page']='products';unset($pq['p']);$pu=function($n)use($pq){$x=$pq;$x['p']=$n;return '?'.http_build_query($x);};?><div class="pagination"><?php if($pageno>1):?><a class="pagebtn" href="<?=h($pu($pageno-1))?>">‹</a><?php endif;?><?php for($n=max(1,$pageno-2);$n<=min($pages,$pageno+2);$n++):?><a class="pagebtn <?=$n===$pageno?'active':''?>" href="<?=h($pu($n))?>"><?=$n?></a><?php endfor;?><?php if($pageno<$pages):?><a class="pagebtn" href="<?=h($pu($pageno+1))?>">›</a><?php endif;?></div><?php endif;?><?php endif;?></div>

<?php elseif($page==='product'):?>
<?php if(empty($history)):?><div class="top"><div class="title"><h1>ไม่พบประวัติสินค้า</h1><p>Product Family นี้ไม่มีข้อมูลที่ยืนยันแล้ว</p></div><a class="btn" href="?page=products">← กลับไปสินค้า</a></div><?php else:?>
<div class="top"><div class="title"><h1>ประวัติราคาสินค้า</h1><p><?=h($pstats['category'])?> · ซื้อจาก <?=number_format($pstats['shops'])?> ร้าน · ยอดซื้อรวม <?=money($pstats['sum'])?></p></div><div class="actions"><a class="btn" href="?page=products">← Product Explorer</a><?php if(!empty($productHead['product_url'])):?><a class="btn primary" target="_blank" rel="noopener" href="<?=h($productHead['product_url'])?>">เปิดรายการล่าสุดใน Shopee</a><?php endif;?></div></div>
<div class="panel hero"><img class="thumb" src="<?=h($productHead['image_url']?:'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2284%22 height=%2284%22%3E%3Crect width=%2284%22 height=%2284%22 fill=%22%23f1f5f9%22/%3E%3C/svg%3E')?>" alt=""><div><div class="hero-title"><?=h($productHead['product_family_name']?:$productHead['product_name'])?></div><div class="variant" style="margin-top:6px"><?=h($pstats['category'])?></div></div><div class="metrics"><div class="metric"><small>ราคาล่าสุด</small><b><?=money($pstats['latest'])?></b></div><div class="metric"><small>ต่ำสุด</small><b class="green"><?=money($pstats['low'])?></b></div><div class="metric"><small>สูงสุด</small><b><?=money($pstats['high'])?></b></div><div class="metric"><small>เฉลี่ย</small><b><?=money($pstats['avg'])?></b></div><div class="metric"><small>ซื้อทั้งหมด</small><b><?=number_format($pstats['count'])?> ครั้ง</b></div><div class="metric"><small>ร้าน</small><b><?=number_format($pstats['shops'])?> ร้าน</b></div></div></div>
<div class="panel" style="margin-top:14px"><h3 style="margin-top:0">แนวโน้มราคา</h3><div class="chart-wrap" style="height:240px;min-height:240px"><canvas id="priceHistoryChart"></canvas></div></div>
<div class="panel" style="margin-top:14px"><h3 style="margin-top:0">ประวัติการซื้อ</h3><div class="table-wrap"><table><thead><tr><th>วันที่สั่ง</th><th>ร้านค้า</th><th>Order</th><th>ตัวเลือก</th><th>หมวด</th><th>จำนวน</th><th>ราคาต่อหน่วย</th><th>ยอด Order</th></tr></thead><tbody><?php foreach($history as $r):?><tr><td><?=h(pan_order_placed_view($r)['value'])?></td><td><?=h($r['shop_name'])?></td><td><?=h($r['order_no'])?></td><td><?=h($r['variant_name']?:'-')?></td><td><?=h($r['pan_category_name']?:$r['marketplace_category_name']?:'ยังไม่จัดหมวด')?></td><td><?=number_format($r['quantity'])?></td><td><b><?=money(price_actual($r))?></b></td><td><?=money($r['total_paid'])?></td></tr><?php endforeach;?></tbody></table></div></div>
<script>(function(){const c=document.getElementById('priceHistoryChart');if(!c)return;const rows=<?=json_encode(array_values(array_reverse(array_map(fn($r)=>['d'=>pan_order_placed_view($r)['value'],'v'=>price_actual($r)],$history))),JSON_UNESCAPED_UNICODE)?>;const dpr=window.devicePixelRatio||1;function draw(){const rect=c.getBoundingClientRect(),w=Math.max(320,rect.width),h=Math.max(200,rect.height);c.width=w*dpr;c.height=h*dpr;const x=c.getContext('2d');x.setTransform(dpr,0,0,dpr,0,0);x.clearRect(0,0,w,h);const p={l:52,r:18,t:16,b:42},cw=w-p.l-p.r,ch=h-p.t-p.b,vals=rows.map(r=>Number(r.v)||0),max=Math.max(1,...vals),min=Math.min(...vals,0),range=Math.max(1,max-min);x.font='11px system-ui';for(let i=0;i<=4;i++){const y=p.t+ch*i/4,val=max-range*i/4;x.strokeStyle='#e8edf4';x.beginPath();x.moveTo(p.l,y);x.lineTo(w-p.r,y);x.stroke();x.fillStyle='#94a3b8';x.textAlign='right';x.textBaseline='middle';x.fillText('฿'+Math.round(val).toLocaleString(),p.l-7,y)}const step=rows.length>1?cw/(rows.length-1):cw,pts=rows.map((r,i)=>({x:p.l+(rows.length>1?i*step:cw/2),y:p.t+ch-((Number(r.v)-min)/range)*ch}));if(pts.length){x.beginPath();pts.forEach((a,i)=>i?x.lineTo(a.x,a.y):x.moveTo(a.x,a.y));x.strokeStyle='#f4512c';x.lineWidth=2.5;x.stroke();pts.forEach(a=>{x.beginPath();x.arc(a.x,a.y,3,0,Math.PI*2);x.fillStyle='#fff';x.fill();x.strokeStyle='#f4512c';x.stroke()})}const every=Math.max(1,Math.ceil(rows.length/8));x.fillStyle='#64748b';x.textAlign='center';x.textBaseline='top';rows.forEach((r,i)=>{if(i%every===0||i===rows.length-1)x.fillText(String(r.d).slice(0,10),pts[i].x,p.t+ch+10)})}draw();window.addEventListener('resize',draw)})();</script><?php endif;?>

<?php elseif($page==='analytics'):?>
<?php $ac=$analytics['core']??[];?>
<div class="page-hero analytics-hero">
  <div><div class="eyebrow">ANALYTICS CENTER</div><h1>สรุปยอดทั้งหมด</h1><p>ภาพรวมการซื้อ ร้าน สินค้า บัญชี และคุณภาพข้อมูลในฐานเดียว</p></div>
  <div class="actions"><a class="btn" href="?page=export">ส่งออกข้อมูล</a><button class="btn primary" onclick="sphCopy('copyPrompt',this)">Copy Prompt Context</button></div>
</div>

<div class="analytics-kpis">
  <div class="kpi-card"><span>ยอดจ่ายรวม</span><b><?=money($ac['total_paid']??0)?></b><small><?=number_format($ac['orders']??0)?> Order</small></div>
  <div class="kpi-card"><span>Order สำเร็จ</span><b><?=number_format($ac['completed_orders']??0)?></b><small><?=money($ac['completed_paid']??0)?></small></div>
  <div class="kpi-card"><span>จำนวนชิ้น</span><b><?=number_format($ac['items_qty']??0)?></b><small><?=number_format($ac['products']??0)?> สินค้าไม่ซ้ำ</small></div>
  <div class="kpi-card"><span>ร้าน</span><b><?=number_format($ac['shops']??0)?></b><small><?=number_format($ac['accounts']??0)?> บัญชี Shopee</small></div>
  <div class="kpi-card"><span>เฉลี่ย / Order</span><b><?=money($ac['avg_order']??0)?></b><small>สูงสุด <?=money($ac['max_order']??0)?></small></div>
  <div class="kpi-card"><span>ส่วนลดที่บันทึกได้</span><b><?=money($ac['discount_total']??0)?></b><small>ค่าส่ง <?=money($ac['shipping_fee']??0)?></small></div>
  <div class="kpi-card"><span>Detail Coverage</span><b><?=number_format((float)($ac['repair_pct']??0),1)?>%</b><small><?=number_format($ac['repaired_orders']??0)?> / <?=number_format($ac['orders']??0)?> Order</small></div>
  <div class="kpi-card"><span>ช่วงข้อมูล</span><b class="date-range"><?=h($ac['first_order_date']??'-')?></b><small>ถึง <?=h($ac['last_order_date']??'-')?></small></div>
</div>

<div class="analytics-grid analytics-grid-2">
  <div class="panel chart-card"><div class="section-head"><div><h3>ยอดซื้อ 24 เดือนล่าสุด</h3><p>ใช้วันที่สั่งซื้อเป็นหลักสำหรับภาพรวมระยะยาว</p></div></div><div class="analytics-canvas"><canvas data-chart="line" data-label-key="month" data-value-key="spent" data-rows='<?=h(json_encode($analytics['monthly']??[],JSON_UNESCAPED_UNICODE))?>'></canvas></div></div>
  <div class="panel chart-card"><div class="section-head"><div><h3>ยอดซื้อตามปี</h3><p>เปรียบเทียบยอดรวมแต่ละปี</p></div></div><div class="analytics-canvas"><canvas data-chart="bars" data-label-key="year" data-value-key="spent" data-rows='<?=h(json_encode($analytics['yearly']??[],JSON_UNESCAPED_UNICODE))?>'></canvas></div></div>
</div>

<div class="analytics-grid analytics-grid-3">
  <div class="panel chart-card"><div class="section-head"><div><h3>Top ร้านตามยอดซื้อ</h3><p>15 อันดับแรก</p></div></div><div class="analytics-canvas tall"><canvas data-chart="bars" data-horizontal="1" data-money="1" data-label-key="shop_name" data-value-key="spent" data-rows='<?=h(json_encode($analytics['top_shops']??[],JSON_UNESCAPED_UNICODE))?>'></canvas></div></div>
  <div class="panel chart-card"><div class="section-head"><div><h3>สถานะ Order</h3><p>ไม่รวมรายการยกเลิก</p></div></div><div class="analytics-canvas"><canvas data-chart="donut" data-label-key="label" data-value-key="orders" data-rows='<?=h(json_encode($analytics['status']??[],JSON_UNESCAPED_UNICODE))?>'></canvas></div></div>
  <div class="panel chart-card"><div class="section-head"><div><h3>วันที่มักซื้อ</h3><p>จำนวน Order แยกตามวันในสัปดาห์</p></div></div><div class="analytics-canvas"><canvas data-chart="bars" data-label-key="label" data-value-key="orders" data-rows='<?=h(json_encode($analytics['weekdays']??[],JSON_UNESCAPED_UNICODE))?>'></canvas></div></div>
</div>

<div class="analytics-grid analytics-grid-2">
  <div class="panel"><div class="section-head"><div><h3>Top สินค้าตามยอดซื้อ</h3><p>ใช้ยอดระดับรายการสินค้าที่มีในฐาน</p></div><a href="?page=products">ดูสินค้าทั้งหมด</a></div><div class="rank-list"><?php foreach(array_slice($analytics['top_products']??[],0,10) as $i=>$r):?><a class="rank-row" href="?page=product&key=<?=rawurlencode((string)$r['product_key'])?>"><span class="rank-no"><?=$i+1?></span><div class="rank-main"><b><?=h($r['product_name'])?></b><small><?=number_format($r['qty'])?> ชิ้น · <?=number_format($r['orders'])?> Order</small></div><strong><?=money($r['spent'])?></strong></a><?php endforeach;?><?php if(empty($analytics['top_products'])):?><div class="muted">ยังไม่มีข้อมูลสินค้า</div><?php endif;?></div></div>
  <div class="panel"><div class="section-head"><div><h3>บัญชี Shopee</h3><p>สรุปตามบัญชีที่ Collector เคย Sync</p></div></div><div class="table-wrap"><table><thead><tr><th>บัญชี</th><th>Order</th><th>ร้าน</th><th>ยอดรวม</th><th>ล่าสุด</th></tr></thead><tbody><?php foreach($analytics['accounts']??[] as $r):?><tr><td><b><?=h($r['label'])?></b></td><td><?=number_format($r['orders'])?></td><td><?=number_format($r['shops'])?></td><td><b><?=money($r['spent'])?></b></td><td><?=h($r['last_order']?:'-')?></td></tr><?php endforeach;?></tbody></table></div></div>
</div>

<div class="breakdown-grid">
  <div class="panel"><h3>ส่วนลดและค่าใช้จ่าย</h3><div class="breakdown-list"><div><span>Subtotal ที่บันทึกได้</span><b><?=money($ac['raw_subtotal']??0)?></b></div><div><span>Discount รวม</span><b><?=money($ac['discount_total']??0)?></b></div><div><span>Voucher</span><b><?=money($ac['voucher_discount']??0)?></b></div><div><span>Coins</span><b><?=money($ac['coins_discount']??0)?></b></div><div><span>Platform discount</span><b><?=money($ac['platform_discount']??0)?></b></div><div><span>Seller discount</span><b><?=money($ac['seller_discount']??0)?></b></div><div><span>Shop voucher</span><b><?=money($ac['shop_voucher_discount']??0)?></b></div><div><span>Shipping discount</span><b><?=money($ac['shipping_discount']??0)?></b></div><div><span>Shipping fee</span><b><?=money($ac['shipping_fee']??0)?></b></div></div></div>
  <div class="panel"><h3>แหล่งวันที่สั่งซื้อ</h3><div class="breakdown-list"><div><span>Order created (วัน/เวลาสร้างคำสั่งซื้อ)</span><b><?=number_format($analytics['date_sources']['created']??0)?></b></div><div><span>มีเฉพาะวันที่สั่งซื้อ</span><b><?=number_format($analytics['date_sources']['order_date']??0)?></b></div><div><span>ไม่ทราบวันที่สั่งซื้อ</span><b><?=number_format($analytics['date_sources']['unknown']??0)?></b></div></div></div>
</div>

<div class="copy-grid">
  <div class="panel copy-card"><div class="copy-head"><div><h3>Copy Summary</h3><p>ข้อความอ่านง่าย เอาไปวางในแชต/เอกสารได้ทันที</p></div><button class="btn" onclick="sphCopy('copySummary',this)">คัดลอก</button></div><textarea id="copySummary" readonly><?=h($analyticsSummaryText??'')?></textarea></div>
  <div class="panel copy-card"><div class="copy-head"><div><h3>Copy Prompt Context</h3><p>เตรียมข้อมูลให้ผมนำไปสร้างภาพ/อินโฟกราฟิกต่อ</p></div><button class="btn primary" onclick="sphCopy('copyPrompt',this)">คัดลอก</button></div><textarea id="copyPrompt" readonly><?=h($analyticsPromptText??'')?></textarea></div>
  <div class="panel copy-card"><div class="copy-head"><div><h3>Copy JSON</h3><p>ข้อมูลสรุปแบบมีโครงสร้างสำหรับนำไปต่อยอด</p></div><button class="btn" onclick="sphCopy('copyJson',this)">คัดลอก</button></div><textarea id="copyJson" readonly><?=h($analyticsJsonText??'')?></textarea></div>
</div>
<script src="./assets/analytics.js?v=2.5.6"></script>

<?php elseif($page==='export'):?>
<div class="page-hero export-hero"><div><div class="eyebrow">DATA PORTABILITY</div><h1>ส่งออกข้อมูล</h1><p>สำรองฐานข้อมูล หรือส่งออกเป็น JSON / CSV เพื่อนำไปใช้ใน Excel, BI, AI และระบบอื่น</p></div><a class="btn primary" href="./api/export.php?format=all_json">Export ทั้งหมด · JSON</a></div>
<div class="export-kpis"><div><b><?=number_format($exportCounts['orders']??0)?></b><span>Orders</span></div><div><b><?=number_format($exportCounts['items']??0)?></b><span>Items</span></div><div><b><?=number_format($exportCounts['accounts']??0)?></b><span>Accounts</span></div><div><b><?=number_format($exportCounts['batches']??0)?></b><span>Collector batches</span></div></div>
<div class="export-grid">
  <a class="export-card featured" href="./api/export.php?format=all_json"><span class="export-icon">{ }</span><div><h3>ข้อมูลทั้งหมด · JSON</h3><p>Orders + Items + Accounts + Collector batches + Analytics ในไฟล์เดียว</p><b>แนะนำสำหรับ Backup / AI / Import ระบบอื่น</b></div></a>
  <?php if($currentDbDriver==='sqlite'):?><a class="export-card" href="./api/export.php?format=sqlite"><span class="export-icon">DB</span><div><h3>SQLite Database</h3><p>สำเนาฐาน SQLite ที่ระบบกำลังใช้งานโดยตรง</p><b><?=number_format(($exportDbSize??0)/1024/1024,2)?> MB</b></div></a><?php else:?><a class="export-card" href="./api/export.php?format=all_json"><span class="export-icon">DB</span><div><h3>MySQL Data Backup</h3><p>ส่งออกข้อมูลทั้งหมดเป็น JSON; แนะนำตั้ง mysqldump/backup ฝั่ง Server เพิ่มด้วย</p><b>MySQL / MariaDB</b></div></a><?php endif;?>
  <a class="export-card" href="./api/export.php?format=orders_csv"><span class="export-icon">CSV</span><div><h3>Orders CSV</h3><p>ข้อมูลระดับคำสั่งซื้อทุกคอลัมน์</p><b><?=number_format($exportCounts['orders']??0)?> rows</b></div></a>
  <a class="export-card" href="./api/export.php?format=items_csv"><span class="export-icon">CSV</span><div><h3>Order Items CSV</h3><p>ข้อมูลระดับสินค้า พร้อม Order / ร้าน / บัญชี</p><b><?=number_format($exportCounts['items']??0)?> rows</b></div></a>
  <a class="export-card" href="./api/export.php?format=shops_csv"><span class="export-icon">CSV</span><div><h3>Shops CSV</h3><p>สรุปร้าน จำนวน Order ยอดซื้อ และช่วงวันที่</p><b>พร้อมใช้ใน Excel</b></div></a>
  <a class="export-card" href="./api/export.php?format=products_csv"><span class="export-icon">CSV</span><div><h3>Products CSV</h3><p>สินค้า จำนวนชิ้น จำนวน Order และยอดระดับรายการ</p><b>พร้อมวิเคราะห์สินค้า</b></div></a>
  <a class="export-card" href="./api/export.php?format=accounts_csv"><span class="export-icon">CSV</span><div><h3>Accounts CSV</h3><p>รายการบัญชีที่เคย Sync ในฐาน</p><b>Metadata เท่านั้น</b></div></a>
  <a class="export-card" href="./api/export.php?format=batches_csv"><span class="export-icon">CSV</span><div><h3>Collector Batches CSV</h3><p>ประวัติการนำเข้า / เติมรายละเอียด</p><b>ใช้ตรวจย้อนหลัง</b></div></a>
  <a class="export-card" href="./api/export.php?format=analytics_json"><span class="export-icon">AI</span><div><h3>Analytics JSON</h3><p>KPI, trend, top shops, top products, status และ breakdown</p><b>เหมาะสำหรับ AI / Dashboard</b></div></a>
  <a class="export-card" href="./api/export.php?format=analytics_csv"><span class="export-icon">Σ</span><div><h3>Analytics Summary CSV</h3><p>เฉพาะ KPI หลักแบบ key/value</p><b>ไฟล์เล็ก อ่านง่าย</b></div></a>
</div>
<div class="panel export-note"><h3>แนะนำการสำรอง</h3><p><?php if($currentDbDriver==='sqlite'):?>เก็บทั้ง <b>SQLite Database</b> และ <b>ข้อมูลทั้งหมด · JSON</b>.<?php else:?>ใช้ <b>ข้อมูลทั้งหมด · JSON</b> สำหรับ portability และตั้ง scheduled <b>MySQL/MariaDB backup</b> ฝั่ง Server สำหรับ disaster recovery.<?php endif;?> CSV เหมาะกับ Excel / Google Sheets.</p></div>

<?php elseif($page==='settings'):?>
<div class="top"><div class="title"><h1>ตั้งค่า / จัดการข้อมูล</h1><p>จัดการข้อมูลจริงของน้องแพน</p></div></div>
<div class="panel" style="margin-bottom:14px"><h3 style="margin-top:0">ฐานข้อมูล · <?=strtoupper(h($currentDbDriver))?></h3><p class="muted">รองรับ SQLite และ MySQL/MariaDB หากเริ่มด้วย SQLite สามารถย้ายข้อมูลภายหลังได้โดยไม่ลบไฟล์เดิม</p><a class="btn primary" href="./database.php">เปิด Database Manager</a> <a class="btn" href="./shopee.php">เชื่อม Shopee บนเซิร์ฟเวอร์</a></div>
<div class="panel" style="border-color:#fed7aa">
  <h3 style="margin-top:0">ข้อมูล Collector เก่าที่ยังอยู่ในฐาน</h3>
  <p class="muted">v0.4.4 และเก่ากว่าเคยใช้วันที่วันนี้เป็น fallback เมื่อหา timestamp ไม่เจอ จึงไม่สามารถเชื่อถือวันที่เดิมได้ ข้อมูลรุ่นเก่าจะยังอยู่ให้ตรวจสอบ และสามารถใช้ Shopee Connector กดเติมรายละเอียดเพื่ออัปเดตข้อมูลได้ พบ <b><?=number_format($resyncCount??0)?></b> Order</p>
  <?php if(($resyncCount??0)>0):?><form method="post" action="?action=delete_old_collector" onsubmit="return confirm('ลบข้อมูล Collector เก่าที่ยังอยู่ในฐานทั้งหมดหรือไม่?')"><?=hub_csrf_field()?><button class="btn" type="submit">ลบข้อมูลเก่านี้จริง ๆ</button></form><?php endif;?>
</div>
<div class="panel" style="margin-top:14px;border-color:#fecaca">
  <h3 style="margin-top:0">ล้างฐานข้อมูลทั้งหมด</h3>
  <p class="muted">ปัจจุบันมี <b><?=number_format($allOrders??0)?></b> Order / <b><?=number_format($allItems??0)?></b> รายการ ปุ่มนี้จะลบ Order, Item และ Collector Batch ทั้งหมด แต่ไม่ลบโครงสร้างโปรแกรม</p>
  <form method="post" action="?action=delete_all_data" onsubmit="return confirm('ยืนยันอีกครั้ง: ต้องการล้างข้อมูลทั้งหมดจริงหรือไม่?')">
    <?=hub_csrf_field()?>
    <label>พิมพ์ <b>DELETE ALL</b> เพื่อยืนยัน</label>
    <input name="confirm_text" autocomplete="off" placeholder="DELETE ALL" style="display:block;margin:8px 0 10px;max-width:320px">
    <button class="btn" type="submit">ล้างข้อมูลทั้งหมด</button>
  </form>
</div>

<?php elseif($page==='accounts'):?>
<div class="top"><div class="title"><h1>บัญชีที่เคย Sync</h1><p>รายการนี้มาจากข้อมูล Order ในฐานข้อมูลปัจจุบันเท่านั้น · ไม่เก็บ Cookie/รหัสผ่าน/Browser Profile</p></div></div>
<div class="panel"><div class="table-wrap"><table><thead><tr><th>บัญชี Shopee</th><th>User ID</th><th>Order</th><th>ยอดรวมในฐาน</th><th>อัปเดตล่าสุด</th></tr></thead><tbody>
<?php foreach($accounts as $a):?><tr><td><b><?=h($a['username']?:$a['account_id'])?></b></td><td><?=h($a['account_id'])?></td><td><?=number_format($a['orders'])?></td><td><?=money($a['spent'])?></td><td><?=h($a['last_seen'])?></td></tr><?php endforeach;?>
<?php if(!$accounts):?><tr><td colspan="5" class="muted">ยังไม่มีบัญชีที่ Sync ด้วย Shopee Connector 2.3</td></tr><?php endif;?></tbody></table></div></div>
<?php elseif($page==='collector'):?><div class="top"><div class="title"><h1>Connectors / รายงานนำเข้า</h1><p>ตรวจ batch และรายการล่าสุดจาก Connector</p></div></div><div class="panel"><b>PAN v2.4 · Shopee Connector</b><p class="muted">เปิดหน้า Shopee ที่ Login อยู่ แล้วใช้ Shopee Connector ดึงประวัติของบัญชีนั้นได้ทันที. Checkpoint แยกตาม Shopee User ID. ระบบอัปเดตช่วงล่าสุดจะเติม Order Detail ของ Order ใหม่/สถานะเปลี่ยนอัตโนมัติ ปุ่ม “เติมรายละเอียดที่ยังขาด” ใช้เฉพาะข้อมูลที่ยัง pending/partial/error.</p></div><div class="panel" style="margin-top:12px"><div class="collector-list"><?php foreach($batches as $b):?><div class="collector-item"><b><?=h($b['source'])?></b> · <?=$b['item_count']?> items · <?=$b['review_count']?> review <span class="muted">· <?=h($b['created_at'])?></span></div><?php endforeach;?></div></div>
<?php endif;?></div></main></div></body></html>
