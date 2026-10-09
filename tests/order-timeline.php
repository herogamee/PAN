<?php
/** Purchase-date UI/provenance contract, synthetic fixtures only. */
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
verify(!function_exists('pan_delivery_view'), 'retired Buyer delivery view is not callable as a visible feature');
verify(!function_exists('pan_delivery_source_confirmed'), 'unverified Buyer delivery mapping is retired');
$sql=pan_order_placed_sql('o');
verify(!str_contains($sql,'completed_at')&&!str_contains($sql,'delivered_at')&&str_contains($sql,'date_source'),'order purchase date SQL excludes complete/delivered fallback');
echo "ORDER TIMELINE CONTRACT PASS\n";
