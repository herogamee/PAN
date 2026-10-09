<?php

declare(strict_types=1);

require_once __DIR__.'/order_timeline.php';

function analytics_scalar(PDO $db,string $sql,array $params=[]): float|int|string {
    $st=$db->prepare($sql);$st->execute($params);$v=$st->fetchColumn();return $v===false?0:$v;
}
function analytics_rows(PDO $db,string $sql,array $params=[]): array {
    $st=$db->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
}
function analytics_status_th(string $status,int|string|null $listType=null): string {
    $m=['completed'=>'สำเร็จแล้ว','shipping'=>'กำลังจัดส่ง','delivering'=>'กำลังนำส่ง','unpaid'=>'ยังไม่ชำระ','refund'=>'คืนสินค้า/คืนเงิน','cancelled'=>'ยกเลิก'];
    if(isset($m[$status]))return $m[$status];
    $lm=[3=>'สำเร็จแล้ว',4=>'ยกเลิก',7=>'กำลังจัดส่ง',8=>'กำลังนำส่ง',9=>'ยังไม่ชำระ',12=>'คืนสินค้า/คืนเงิน'];
    return $lm[(int)$listType]??($status?:'ไม่ทราบสถานะ');
}
function analytics_snapshot(PDO $db): array {
    ensure_schema_v200($db);
    $verified="('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200')";
    $purchase="COALESCE(validation_state,'legacy') IN $verified AND COALESCE(purchase_state,'review')='purchase' AND COALESCE(list_type,0)<>4";
    $purchaseO="COALESCE(o.validation_state,'legacy') IN $verified AND COALESCE(o.purchase_state,'review')='purchase' AND COALESCE(o.list_type,0)<>4";
    $dateExpr='substr('.pan_order_placed_sql().',1,10)';
    $dateExprO='substr('.pan_order_placed_sql('o').',1,10)';

    $core=analytics_rows($db,"SELECT
      COUNT(*) orders,
      SUM(CASE WHEN list_type=3 THEN 1 ELSE 0 END) completed_orders,
      COUNT(DISTINCT NULLIF(shop_name,'')) shops,
      COUNT(DISTINCT NULLIF(source_account_id,'')) accounts,
      COALESCE(SUM(total_paid),0) total_paid,
      COALESCE(SUM(CASE WHEN list_type=3 THEN total_paid ELSE 0 END),0) completed_paid,
      COALESCE(AVG(total_paid),0) avg_order,
      COALESCE(MAX(total_paid),0) max_order,
      COALESCE(MIN(CASE WHEN total_paid>0 THEN total_paid END),0) min_order,
      COALESCE(SUM(raw_subtotal),0) raw_subtotal,
      COALESCE(SUM(discount_total),0) discount_total,
      COALESCE(SUM(shipping_fee),0) shipping_fee,
      COALESCE(SUM(voucher_discount),0) voucher_discount,
      COALESCE(SUM(coins_discount),0) coins_discount,
      COALESCE(SUM(platform_discount),0) platform_discount,
      COALESCE(SUM(seller_discount),0) seller_discount,
      COALESCE(SUM(shop_voucher_discount),0) shop_voucher_discount,
      COALESCE(SUM(shipping_discount),0) shipping_discount,
      SUM(CASE WHEN COALESCE(detail_enriched,0)=1 THEN 1 ELSE 0 END) repaired_orders,
      MIN($dateExpr) first_order_date,
      MAX($dateExpr) last_order_date
      FROM orders WHERE $purchase")[0]??[];

    $items=analytics_rows($db,"SELECT
      COALESCE(SUM(i.quantity),0) qty,
      COUNT(DISTINCT i.product_key) products,
      COALESCE(SUM(CASE WHEN i.actual_line_total>0 THEN i.actual_line_total ELSE (CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END)*i.quantity END),0) item_spend
      FROM order_items i JOIN orders o ON o.id=i.order_id WHERE $purchaseO")[0]??[];

    $core['items_qty']=(int)($items['qty']??0);
    $core['products']=(int)($items['products']??0);
    $core['item_spend']=(float)($items['item_spend']??0);
    $core['avg_item']=$core['items_qty']>0?(float)$core['total_paid']/$core['items_qty']:0;
    $core['repair_pct']=$core['orders']>0?round(((int)$core['repaired_orders']/(int)$core['orders'])*100,1):0;

    $status=analytics_rows($db,"SELECT COALESCE(list_type,0) list_type,COALESCE(order_status,'') order_status,COUNT(*) orders,COALESCE(SUM(total_paid),0) spent FROM orders WHERE COALESCE(list_type,0)<>4 GROUP BY list_type,order_status ORDER BY orders DESC");
    foreach($status as &$r){$r['label']=analytics_status_th((string)$r['order_status'],$r['list_type']);$r['orders']=(int)$r['orders'];$r['spent']=(float)$r['spent'];}unset($r);

    $topShops=analytics_rows($db,"SELECT shop_name,COUNT(*) orders,COALESCE(SUM(total_paid),0) spent,MAX($dateExpr) last_order FROM orders WHERE $purchase AND TRIM(COALESCE(shop_name,''))<>'' GROUP BY shop_name ORDER BY spent DESC,orders DESC LIMIT 15");
    foreach($topShops as &$r){$r['orders']=(int)$r['orders'];$r['spent']=(float)$r['spent'];}unset($r);

    $productSpendExpr="CASE WHEN i.actual_line_total>0 THEN i.actual_line_total ELSE (CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END)*i.quantity END";
    $topProducts=analytics_rows($db,"SELECT i.product_key,MAX(i.product_name) product_name,MAX(i.variant_name) variant_name,MAX(i.image_url) image_url,COUNT(DISTINCT i.order_id) orders,COALESCE(SUM(i.quantity),0) qty,COALESCE(SUM($productSpendExpr),0) spent,COALESCE(AVG(CASE WHEN i.actual_unit_price>0 THEN i.actual_unit_price WHEN i.net_unit_price>0 THEN i.net_unit_price ELSE i.purchase_price END),0) avg_price FROM order_items i JOIN orders o ON o.id=i.order_id WHERE $purchaseO GROUP BY i.product_key ORDER BY spent DESC,qty DESC LIMIT 15");
    foreach($topProducts as &$r){$r['orders']=(int)$r['orders'];$r['qty']=(int)$r['qty'];$r['spent']=(float)$r['spent'];$r['avg_price']=(float)$r['avg_price'];}unset($r);


    $accounts=analytics_rows($db,"SELECT COALESCE(NULLIF(source_account_username,''),NULLIF(source_account_id,''),'ไม่ทราบ') label,COUNT(*) orders,COUNT(DISTINCT NULLIF(shop_name,'')) shops,COALESCE(SUM(total_paid),0) spent,MAX($dateExpr) last_order FROM orders WHERE $purchase GROUP BY COALESCE(NULLIF(source_account_username,''),NULLIF(source_account_id,''),'ไม่ทราบ') ORDER BY spent DESC");
    foreach($accounts as &$r){$r['orders']=(int)$r['orders'];$r['shops']=(int)$r['shops'];$r['spent']=(float)$r['spent'];}unset($r);

    $years=analytics_rows($db,"SELECT substr($dateExpr,1,4) year,COUNT(*) orders,COALESCE(SUM(total_paid),0) spent FROM orders WHERE $purchase AND length($dateExpr)>=10 GROUP BY year HAVING year<>'' ORDER BY year");
    foreach($years as &$r){$r['orders']=(int)$r['orders'];$r['spent']=(float)$r['spent'];}unset($r);

    $monthlyRows=analytics_rows($db,"SELECT substr($dateExpr,1,7) month,COUNT(*) orders,COALESCE(SUM(total_paid),0) spent FROM orders WHERE $purchase AND length($dateExpr)>=10 GROUP BY month HAVING month<>'' ORDER BY month");
    $monthlyMap=[];foreach($monthlyRows as $r)$monthlyMap[$r['month']]=['month'=>$r['month'],'orders'=>(int)$r['orders'],'spent'=>(float)$r['spent']];
    $monthly=[];
    $last=(string)($core['last_order_date']??'');
    if($last!==''&&preg_match('/^(\d{4})-(\d{2})/',$last,$m)){$dt=new DateTimeImmutable($m[1].'-'.$m[2].'-01');for($i=23;$i>=0;$i--){$k=$dt->modify("-$i months")->format('Y-m');$monthly[]=$monthlyMap[$k]??['month'=>$k,'orders'=>0,'spent'=>0.0];}}

    $weekdayExpr=db_driver($db)==='mysql'?"(DAYOFWEEK($dateExpr)-1)":"CAST(strftime('%w',$dateExpr) AS INTEGER)";
    $weekdays=analytics_rows($db,"SELECT $weekdayExpr dow,COUNT(*) orders,COALESCE(SUM(total_paid),0) spent FROM orders WHERE $purchase AND length($dateExpr)>=10 GROUP BY dow ORDER BY dow");
    $dowLabels=['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
    foreach($weekdays as &$r){$r['label']=$dowLabels[(int)$r['dow']]??'ไม่ทราบ';$r['orders']=(int)$r['orders'];$r['spent']=(float)$r['spent'];}unset($r);

    $dateSources=analytics_rows($db,"SELECT
      SUM(CASE WHEN COALESCE(delivered_at,'')<>'' THEN 1 ELSE 0 END) delivered,
      SUM(CASE WHEN COALESCE(delivered_at,'')='' AND COALESCE(completed_at,'')<>'' THEN 1 ELSE 0 END) completed,
      SUM(CASE WHEN COALESCE(delivered_at,'')='' AND COALESCE(completed_at,'')='' AND COALESCE(order_created_at,'')<>'' THEN 1 ELSE 0 END) created,
      SUM(CASE WHEN COALESCE(delivered_at,'')='' AND COALESCE(completed_at,'')='' AND COALESCE(order_created_at,'')='' AND COALESCE(order_date,'')<>'' THEN 1 ELSE 0 END) order_date,
      SUM(CASE WHEN COALESCE(delivered_at,'')='' AND COALESCE(completed_at,'')='' AND COALESCE(order_created_at,'')='' AND COALESCE(order_date,'')='' THEN 1 ELSE 0 END) unknown
      FROM orders WHERE $purchase")[0]??[];
    foreach($dateSources as $k=>$v)$dateSources[$k]=(int)$v;

    return [
      'generated_at'=>date('c'),
      'scope'=>'verified purchase orders, cancelled excluded',
      'core'=>$core,
      'status'=>$status,
      'monthly'=>$monthly,
      'yearly'=>$years,
      'top_shops'=>$topShops,
      'top_products'=>$topProducts,
      'accounts'=>$accounts,
      'weekdays'=>$weekdays,
      'date_sources'=>$dateSources,
    ];
}

function analytics_copy_summary(array $a): string {
    $c=$a['core']??[];
    $lines=[
      'PAN — น้องแพน · สรุปข้อมูล',
      'ช่วงข้อมูล: '.(($c['first_order_date']??'')?:'ไม่ทราบ').' ถึง '.(($c['last_order_date']??'')?:'ไม่ทราบ'),
      'Order ทั้งหมด: '.number_format((int)($c['orders']??0)),
      'Order สำเร็จ: '.number_format((int)($c['completed_orders']??0)),
      'จำนวนชิ้น: '.number_format((int)($c['items_qty']??0)),
      'สินค้าไม่ซ้ำ: '.number_format((int)($c['products']??0)),
      'ร้านไม่ซ้ำ: '.number_format((int)($c['shops']??0)),
      'บัญชี Shopee: '.number_format((int)($c['accounts']??0)),
      'ยอดจ่ายรวม: ฿'.number_format((float)($c['total_paid']??0),2),
      'ยอด Order สำเร็จ: ฿'.number_format((float)($c['completed_paid']??0),2),
      'เฉลี่ยต่อ Order: ฿'.number_format((float)($c['avg_order']??0),2),
      'เฉลี่ยต่อชิ้น: ฿'.number_format((float)($c['avg_item']??0),2),
      'ส่วนลดที่บันทึกได้: ฿'.number_format((float)($c['discount_total']??0),2),
      'ค่าส่งที่บันทึกได้: ฿'.number_format((float)($c['shipping_fee']??0),2),
      'Detail coverage: '.number_format((float)($c['repair_pct']??0),1).'%',
      '',
      'Top ร้านตามยอดซื้อ:'
    ];
    foreach(array_slice($a['top_shops']??[],0,10) as $i=>$r)$lines[]=($i+1).'. '.$r['shop_name'].' — ฿'.number_format((float)$r['spent'],2).' / '.number_format((int)$r['orders']).' Order';
    $lines[]='';$lines[]='Top สินค้าตามยอดซื้อ:';
    foreach(array_slice($a['top_products']??[],0,10) as $i=>$r)$lines[]=($i+1).'. '.$r['product_name'].' — ฿'.number_format((float)$r['spent'],2).' / '.number_format((int)$r['qty']).' ชิ้น';
    return implode("\n",$lines);
}

function analytics_prompt_context(array $a): string {
    $c=$a['core']??[];
    $topShops=array_slice($a['top_shops']??[],0,8);
    $topProducts=array_slice($a['top_products']??[],0,8);
    $months=array_slice($a['monthly']??[],-12);
    $payload=[
      'period'=>['from'=>$c['first_order_date']??'','to'=>$c['last_order_date']??''],
      'kpi'=>[
        'orders'=>(int)($c['orders']??0),'completed_orders'=>(int)($c['completed_orders']??0),'items'=>(int)($c['items_qty']??0),'products'=>(int)($c['products']??0),'shops'=>(int)($c['shops']??0),'accounts'=>(int)($c['accounts']??0),
        'total_paid'=>(float)($c['total_paid']??0),'avg_order'=>(float)($c['avg_order']??0),'discount_total'=>(float)($c['discount_total']??0),'shipping_fee'=>(float)($c['shipping_fee']??0),'repair_pct'=>(float)($c['repair_pct']??0)
      ],
      'top_shops'=>$topShops,'top_products'=>$topProducts,'last_12_months'=>$months,'status'=>$a['status']??[]
    ];
    return "ใช้ข้อมูล PAN — น้องแพน ด้านล่างนี้เป็นข้อมูลจริงในการออกแบบภาพ/อินโฟกราฟิก/รายงาน ห้ามแต่งตัวเลขเพิ่ม หากต้องย่อให้รักษาค่าหลักไว้ และใช้รูปแบบเงินบาท\n\n".json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}
