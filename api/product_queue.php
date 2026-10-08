<?php
require __DIR__.'/_bootstrap.php';
require __DIR__.'/../app/db.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $aid=trim((string)($_GET['account_id']??''));
    if($aid==='')throw new RuntimeException('account_id required');
    $limit=max(1,min(200,(int)($_GET['limit']??100)));
    $afterOrderId=(int)($_GET['after_order_id']??0);
    $afterShop=trim((string)($_GET['after_shop_id']??''));
    $afterItem=trim((string)($_GET['after_item_id']??''));
    if($afterOrderId<0||($afterOrderId>0&&($afterShop===''||$afterItem==='')))
        throw new RuntimeException('invalid product queue cursor');
    // Keyset paging: successful enrichments REMOVE products from this queue.
    // OFFSET paging would skip items as the result set shrinks, or repeat failed items indefinitely.
    $sql="SELECT p.* FROM (
        SELECT i.marketplace_shop_id shop_id,i.marketplace_item_id item_id,
          MAX(i.product_name) product_name,MAX(i.product_family_key) product_family_key,
          COUNT(*) rows_count,MAX(o.id) last_order_id
        FROM order_items i JOIN orders o ON o.id=i.order_id
        WHERE o.source_account_id=? AND COALESCE(o.list_type,0)<>4
          AND TRIM(COALESCE(i.marketplace_shop_id,''))<>''
          AND TRIM(COALESCE(i.marketplace_item_id,''))<>''
          AND TRIM(COALESCE(i.marketplace_category_name,''))=''
        GROUP BY i.marketplace_shop_id,i.marketplace_item_id
    ) p";
    $params=[$aid];
    if($afterOrderId>0){
        $sql.=" WHERE (p.last_order_id < ? OR
          (p.last_order_id = ? AND
          (p.shop_id > ? OR (p.shop_id = ? AND p.item_id > ?))))";
        array_push($params,$afterOrderId,$afterOrderId,$afterShop,$afterShop,$afterItem);
    }
    $sql.=" ORDER BY p.last_order_id DESC,p.shop_id ASC,p.item_id ASC LIMIT ".($limit+1);
    $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    $more=count($rows)>$limit;
    if($more)array_pop($rows);
    $last=$rows?end($rows):null;
    $cursor=$more&&$last?[
      'after_order_id'=>(int)$last['last_order_id'],
      'after_shop_id'=>(string)$last['shop_id'],
      'after_item_id'=>(string)$last['item_id']
    ]:null;
    echo json_encode(['ok'=>true,'count'=>count($rows),'products'=>$rows,
      'has_more'=>$more,'next_cursor'=>$cursor],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
