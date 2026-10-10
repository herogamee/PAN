<?php
/**
 * Read-only product lines for the Orders page. Read only the IDs from the
 * already-filtered/paginated order results; never fetch an unrelated account.
 * A single batched query avoids N+1 requests even at 200 orders per page.
 */
declare(strict_types=1);

function pan_order_items_for_page(PDO $db, array $orders): array {
    $ids=[];
    foreach ($orders as $order) {
        $id=(int)($order['id']??0);
        if($id>0)$ids[$id]=true;
    }
    if(!$ids)return [];
    $grouped=array_fill_keys(array_keys($ids),[]);
    foreach(array_chunk(array_keys($ids),250) as $chunk) {
        $placeholders=implode(',',array_fill(0,count($chunk),'?'));
        $sql='SELECT order_id, id, product_name, variant_name, image_url, product_url, '
            .'quantity, purchase_price, net_unit_price, actual_unit_price, '
            .'actual_line_total, needs_review, import_source FROM order_items '
            .'WHERE order_id IN ('.$placeholders.') ORDER BY order_id ASC, id ASC';
        $statement=$db->prepare($sql);
        $statement->execute($chunk);
        while($item=$statement->fetch(PDO::FETCH_ASSOC)) {
            $id=(int)$item['order_id'];
            if(array_key_exists($id,$grouped))$grouped[$id][]=$item;
        }
    }
    return $grouped;
}

/** Restrict external item images/links to real HTTP(S) URLs, not JS/data schemes. */
function pan_order_item_safe_url(string $url): string {
    $url=trim($url);
    if($url===''||preg_match('/[\x00-\x20\x7f]/',$url))return '';
    $parsed=parse_url($url);
    if(!is_array($parsed) || !in_array(strtolower((string)($parsed['scheme']??'')),['https','http'],true)
       || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass']))return '';
    return $url;
}

function pan_order_item_unit_price(array $item): float {
    foreach(['actual_unit_price','net_unit_price','purchase_price'] as $field){
        $n=(float)($item[$field]??0);
        if(is_finite($n)&&$n>0)return $n;
    }
    return 0.0;
}

/** Item line amount is not necessarily the whole order total (shipping/vouchers). */
function pan_order_item_line_total(array $item): float {
    $recorded=(float)($item['actual_line_total']??0);
    if(is_finite($recorded)&&$recorded>0)return $recorded;
    return max(0,(int)($item['quantity']??0))*pan_order_item_unit_price($item);
}

/** An operator-attested quantity is not proof of unit pricing or a Shopee API snapshot. */
function pan_order_item_price_pending(array $item): bool {
    return (string)($item['import_source']??'')==='pan_user_attested_quantity';
}

/** Source-level distinction: a preview is never a verified Buyer Detail. */
function pan_order_item_detail_verified(array $item): bool {
    return (string)($item['import_source']??'')==='shopee_buyer_detail_verified';
}
