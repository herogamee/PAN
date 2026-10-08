<?php
require __DIR__.'/_bootstrap.php';require __DIR__.'/../app/db.php';header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST only']);exit;}
try{
 $r=json_decode(file_get_contents('php://input'),true);if(!is_array($r))throw new RuntimeException('Invalid JSON');
 $shop=trim((string)($r['shop_id']??''));$item=trim((string)($r['item_id']??''));if($shop===''||$item==='')throw new RuntimeException('shop_id/item_id required');
 $catId=substr(trim((string)($r['category_id']??'')),0,100);$catName=substr(trim((string)($r['category_name']??'')),0,255);$catPath=substr(trim((string)($r['category_path']??'')),0,2000);$source=substr(trim((string)($r['source']??'shopee_product_detail')),0,64);
 if($catName===''&&$catId==='')throw new RuntimeException('ไม่พบข้อมูล category ที่เชื่อถือได้');
 $st=db()->prepare("UPDATE order_items SET marketplace_category_id=:cid,marketplace_category_name=:cname,marketplace_category_path=:cpath,category_source=:src,category_updated_at=CURRENT_TIMESTAMP WHERE marketplace_shop_id=:shop AND marketplace_item_id=:item");
 $st->execute([':cid'=>$catId,':cname'=>$catName,':cpath'=>$catPath,':src'=>$source,':shop'=>$shop,':item'=>$item]);
 echo json_encode(['ok'=>true,'updated_rows'=>$st->rowCount()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
