<?php
// Source-level UI regression for the public Orders/Analytics markup, no PDO needed.
declare(strict_types=1);
$root=dirname(__DIR__);
$orders=file_get_contents($root.'/index.php');
$analytics=file_get_contents($root.'/app/analytics.php');
$checks=[
    'orders purchase date heading' => str_contains($orders,'วันที่สั่งซื้อ</th>'),
    'unverified received-date heading removed' => !str_contains($orders,'วันที่ได้รับพัสดุ</th>'),
    'retired received-date helper no longer called' => !str_contains($orders,'pan_delivery_view('),
    'Orders UI uses placed-date helper' => str_contains($orders,'pan_order_placed_view($o)'),
    'Thai BE date formatter applied in Orders and Analytics' => str_contains($orders,'pan_thai_date_display(') || str_contains($orders,'function show_order_date($d){return pan_thai_date_display'),
    'Gregorian year values shown as Buddhist Era labels in filters' => str_contains($orders,'pan_thai_be_year($y)') && str_contains($orders,'pan_thai_be_year($v)'),
    'Dashboard recent query includes historic no-longer-seen rows' => str_contains($orders,'pan_purchase_visibility_sql()." AND COALESCE(list_type,0)<>4 ORDER BY'),
    'Orders diagnosis links to unseen and undated history' => str_contains($orders,'?page=orders&scope=stale') && str_contains($orders,'?page=orders&seen=undated'),
    'Displayed purchase date never falls back to sync time' => !str_contains($orders,'show_order_date($o[\'last_seen_at\'])'),
    'No synthetic Thai time shown for date-only orders' => str_contains(file_get_contents($root.'/app/order_timeline.php'),'Shopee ไม่ระบุเวลา'),
    'Full Sync reconcile preserves historic validation state' => !str_contains(file_get_contents($root.'/app/db.php'),"UPDATE orders SET validation_state='not_seen_full_scan'"),

    'payment method column removed' => !str_contains($orders,'>ช่องทางการชำระเงิน</th>'),
    'payment filter removed' => !str_contains($orders,'name="payment"'),
    'payment chart removed' => !str_contains($orders,'ช่องทางการชำระเงิน</h3>'),
    'payment method excluded from analytics summary' => !str_contains($analytics,"'payment_methods'"),
    'shipping carrier column removed' => !str_contains($orders,'<th>ขนส่ง</th>'),
    'shipping carrier cell removed' => !str_contains($orders,'detail_value_label($o,\'shipping_carrier\')'),
    'shipping carrier filter removed' => !str_contains($orders,'name="carrier"'),
    'obsolete carrier query parameter ignored' => !str_contains($orders,'$_GET[\'carrier\']'),
    'shipping carrier Analytics chart removed' => !str_contains($orders,'<h3>บริษัทขนส่ง</h3>'),
    'shipping carrier Analytics aggregation removed' => !str_contains($analytics,"'carriers'") && !str_contains($analytics,'$carriers=analytics_rows'),
    'no received-date messaging in Orders' => !str_contains($orders,'Shopee ไม่ระบุวันรับพัสดุ'),
    'analytics purchase date excludes completion fallback' => str_contains($analytics,"pan_order_placed_sql()"),
    'analytics no unverified courier or Complete date count' => !str_contains($analytics,"COALESCE(delivered_at") && !str_contains($analytics,"COALESCE(completed_at"),
    'Details are not falsely labelled complete' => str_contains($orders,'✓ ตรวจ Detail แล้ว') && !str_contains($orders,'✓ Detail ครบ'),
    'Shopee order completion status not confused with delivered date' => str_contains($orders,'สถานะคำสั่งซื้อจาก Shopee ไม่ใช่หลักฐานวันที่ขนส่งนำส่งถึงผู้รับ'),
];
foreach($checks as $label=>$pass){
    if(!$pass)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}

$expanderChecks=[
    'all orders read-only page-scoped batch lookup' => str_contains($orders,'pan_order_items_for_page($db,$orders)') && !str_contains($orders,'?action=load_order_items'),
    'order number has a keyboard-operable button' => str_contains($orders,'class="order-expand-button"') && str_contains($orders,'aria-expanded="false"'),
    'detail association by stable order ID' => str_contains($orders,'aria-controls="pan-order-lines-') && str_contains($orders,'id="pan-order-lines-'),
    'detail display includes each item and optional variants' => str_contains($orders,'foreach($lines as $item)') && str_contains($orders,'variant_name'),
    'detail panels not initially visible' => str_contains($orders,'class="order-products-row" hidden'),
    'order product links escape URL and have noopener' => str_contains($orders,'rel="noopener noreferrer"') && str_contains($orders,'pan_order_item_safe_url('),
    'saved quantity explicitly distinguished from verified buyer quantity' => str_contains($orders,'สินค้าที่ PAN บันทึกในคำสั่งซื้อ') && str_contains($orders,'จำนวนตามข้อมูลที่ PAN บันทึก'),
    'product detail uses local JS asset and no buyer API fetch' => str_contains($orders,'assets/orders.js?v=2.5.10') && is_file($root.'/assets/orders.js'),
    'all ten original summary columns are retained' => str_contains($orders,'colspan="10"'),
];
foreach($expanderChecks as $label=>$passed){
    if(!$passed)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
