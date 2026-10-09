<?php
/** Order/delivery UI/provenance contract, synthetic fixtures only. */
declare(strict_types=1);
require_once __DIR__.'/../app/order_timeline.php';
function verify(bool $ok,string $label):void {
    if(!$ok)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
verify(pan_date_text('2026-10-09')==='2026-10-09','date-only has no invented time');
verify(pan_date_text('2026-10-09 14:18:11')==='2026-10-09 14:18:11','actual timestamp preserved');
verify(pan_time_is_precise('2026-10-09')===false,'date-only precision correctly marked');
verify(pan_date_text('2026-02-30')==='','invalid calendar date rejected');
verify(pan_date_text('2026-10-09 25:11:00')==='','impossible time rejected');
$placed=pan_order_placed_view(['order_created_at'=>'2026-10-09 14:18:11','order_date'=>'2026-10-09']);
verify($placed['value']==='2026-10-09 14:18:11'&&$placed['note']==='', 'ordered timestamp shown when verified created event exists');
$placed=pan_order_placed_view(['order_date'=>'2026-10-09','date_source'=>'info_card.create_time']);
verify($placed['value']==='2026-10-09'&&str_contains($placed['note'],'เวลา'),'purchase date-only explicitly says no time');
foreach(['info_card.pay_time','shipping.tracking_info.ctime','order_complete_fallback','detail.completed_time'] as $source){
    $placed=pan_order_placed_view(['order_date'=>'2026-10-09','date_source'=>$source]);
    verify($placed['value']==='ไม่ทราบวันที่สั่งซื้อ','not mislabelled order date from '.$source);
}
$confirmed=pan_delivery_view(['delivered_at'=>'2026-10-09 15:40:17','delivery_date_source'=>'detail.shipping.tracking_info.delivered_time', 'completed_at'=>'2026-10-11 10:00:00']);
verify($confirmed['value']==='2026-10-09 15:40:17','verified courier time is used independent of completed_at');
foreach(['order_complete_fallback','detail.shipping.delivery_time','detail.shipping.estimated_delivered_time','detail.shipping.received_time','detail.buyer_received_time',''] as $source){
    $view=pan_delivery_view(['delivered_at'=>'2026-10-09 15:40:17','delivery_date_source'=>$source]);
    verify($view['value']!=='2026-10-09 15:40:17','ambiguous courier time hidden for '.$source);
}
$completed=pan_delivery_view(['delivered_at'=>'','completed_at'=>'2026-10-09 15:40:17','detail_enriched'=>1]);
verify($completed['value']==='Shopee ไม่ระบุวันรับพัสดุ','order Complete never used as delivered_at');
verify(pan_delivery_source_confirmed('detail.parcel.actual_delivery_time'),'explicit actual_delivery_time accepted');
verify(!pan_delivery_source_confirmed('detail.parcel.estimated_delivered_time'),'estimated delivery blocked');
$sql=pan_order_placed_sql('o');
verify(!str_contains($sql,'completed_at')&&!str_contains($sql,'delivered_at')&&str_contains($sql,'date_source'),'order purchase date SQL excludes complete/delivered fallback');
echo "ORDER TIMELINE CONTRACT PASS\n";
