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
