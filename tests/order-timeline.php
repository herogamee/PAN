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
verify(pan_thai_date_display('2026-10-09')==='09/10/2569','Thai date uses BE 2569 without inventing hours');
verify(pan_thai_date_display('2026-10-09 21:39:24')==='09/10/2569 21:39','Thailand 24-hour wall clock displays minutes');
verify(pan_thai_date_display('2026-10-09T23:30:00Z')==='10/10/2569 06:30','UTC midnight converted to Asia/Bangkok calendar date');
verify(pan_thai_date_display('2026-10-09T19:45:00+05:30')==='09/10/2569 21:15','ISO explicit non-Thai timezone correctly converted');
verify(pan_thai_date_display('2026-02-30')===''&&pan_thai_date_display('2026-11-09 25:10')==='','invalid time and calendar date rejected');
verify(pan_thai_be_year('2026')==='2569'&&pan_thai_be_year('xyz')==='xyz','Gregorian 2026 converted only for UI year labels');
verify(str_contains(pan_purchase_visibility_sql('o'),"o.validation_state='not_seen_full_scan'"),'historically verified not-seen records visible without updating database');
verify(!str_contains(pan_order_placed_sql('o'),'last_seen_at')&&str_contains(pan_order_sort_sql('o'),'last_seen_at'),'sync timestamp can sort unknown-date orders but NEVER become purchase date');

$placed=pan_order_placed_view(['order_created_at'=>'2026-10-09 14:18:11','order_date'=>'2026-10-09']);
verify($placed['value']==='09/10/2569 14:18'&&$placed['note']==='', 'ordered timestamp shown when verified created event exists');
$placed=pan_order_placed_view(['order_date'=>'2026-10-09','date_source'=>'info_card.create_time']);
verify($placed['value']==='09/10/2569'&&str_contains($placed['note'],'เวลา'),'purchase date-only explicitly says no time');
foreach(['info_card.pay_time','shipping.tracking_info.ctime','order_complete_fallback','detail.completed_time'] as $source){
    $placed=pan_order_placed_view(['order_date'=>'2026-10-09','date_source'=>$source]);
    verify($placed['value']==='ไม่ทราบวันที่สั่งซื้อ','not mislabelled order date from '.$source);
}
verify(!function_exists('pan_delivery_view'), 'retired Buyer delivery view is not callable as a visible feature');
verify(!function_exists('pan_delivery_source_confirmed'), 'unverified Buyer delivery mapping is retired');
$sql=pan_order_placed_sql('o');
verify(!str_contains($sql,'completed_at')&&!str_contains($sql,'delivered_at')&&str_contains($sql,'date_source'),'order purchase date SQL excludes complete/delivered fallback');
echo "ORDER TIMELINE CONTRACT PASS\n";
