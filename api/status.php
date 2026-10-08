<?php
require __DIR__.'/_bootstrap.php';require __DIR__.'/../app/db.php';header('Content-Type: application/json; charset=utf-8');
try{
 $db=db();$accounts=(int)$db->query("SELECT COUNT(DISTINCT source_account_id) FROM orders WHERE COALESCE(source_account_id,'')<>''")->fetchColumn();
 $aid=trim((string)($_GET['account_id']??''));$accountPurchase=null;$pendingDetail=null;
 if($aid!==''){$st=$db->prepare("SELECT COUNT(*) FROM orders WHERE source_account_id=:aid AND validation_state IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') AND purchase_state='purchase' AND COALESCE(list_type,0)<>4");$st->execute([':aid'=>$aid]);$accountPurchase=(int)$st->fetchColumn();$st=$db->prepare("SELECT COUNT(*) FROM orders WHERE source_account_id=:aid AND COALESCE(list_type,0)<>4 AND (COALESCE(detail_enriched,0)=0 OR COALESCE(detail_state,'pending') IN ('pending','partial','error'))");$st->execute([':aid'=>$aid]);$pendingDetail=(int)$st->fetchColumn();}
 echo json_encode(['ok'=>true,'app'=>'PAN','character'=>'น้องแพน','brand'=>'itoom.work','version'=>'2.5.3','connector_version'=>'2.4.10','mode'=>'connector','connector'=>'shopee_th','database'=>db_driver($db),'orders'=>pan_total_order_count($db),'purchase_orders'=>pan_verified_purchase_count($db),'account_purchase_orders'=>$accountPurchase,'pending_detail_orders'=>$pendingDetail,'accounts'=>$accounts],JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
