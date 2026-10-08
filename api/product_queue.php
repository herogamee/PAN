<?php
require __DIR__.'/_bootstrap.php';
require __DIR__.'/../app/db.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $aid=trim((string)($_GET['account_id']??''));
    if($aid==='')throw new RuntimeException('account_id required');
    $limit=max(1,min(200,(int)($_GET['limit']??100)));
    // A cursor is exclusive. Never use OFFSET here: completed enrichments disappear
    // from the queue and OFFSET would skip products or strand failed ones.
    $afterShop=trim((string)($_GET['after_shop_id']??''));
    $afterItem=trim((string)($_GET['after_item_id']??''));
    if(($afterShop==='') !== ($afterItem===''))throw new RuntimeException('after_shop_id and after_item_id must be provided together');
    $params=[':aid'=>$aid];
    $cursorWhere='';
    if($afterShop!=='' && $afterItem!==''){
        $cursorWhere=' AND (i.marketplace_shop_id > :shop_gt OR (i.marketplace_shop_id = :shop_eq AND i.marketplace_item_id > :item_gt))';
        $params[':shop_gt']=$afterShop;
        $params[':shop_eq']=$afterShop;
        $params[':item_gt']=$afterItem;
    }
    $sql="SELECT i.marketplace_shop_id shop_id,i.marketplace_item_id item_id,MAX(i.product_name) product_name,MAX(i.product_family_key) product_family_key,COUNT(*) rows_count
          FROM order_items i JOIN orders o ON o.id=i.order_id
          WHERE o.source_account_id=:aid AND COALESCE(o.list_type,0)<>4
            AND TRIM(COALESCE(i.marketplace_shop_id,''))<>'' AND TRIM(COALESCE(i.marketplace_item_id,''))<>''
            AND TRIM(COALESCE(i.marketplace_category_name,''))=''
            $cursorWhere
          GROUP BY i.marketplace_shop_id,i.marketplace_item_id
          ORDER BY i.marketplace_shop_id ASC,i.marketplace_item_id ASC LIMIT $limit";
    $st=db()->prepare($sql);
    $st->execute($params);
    $rows=$st->fetchAll();
    $last=count($rows)?$rows[count($rows)-1]:null;
    $next=$last?['shop_id'=>(string)$last['shop_id'],'item_id'=>(string)$last['item_id']]:null;
    echo json_encode(['ok'=>true,'count'=>count($rows),'products'=>$rows,'next_cursor'=>$next,'has_more'=>count($rows)===$limit],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e){
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
