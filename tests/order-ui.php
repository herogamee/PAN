<?php
// Source-level UI regression for the public Orders/Analytics markup, no PDO needed.
declare(strict_types=1);
$root=dirname(__DIR__);
$orders=file_get_contents($root.'/index.php');
$analytics=file_get_contents($root.'/app/analytics.php');
$checks=[
    'orders purchase date heading' => str_contains($orders,'วันที่สั่งซื้อ</th>'),
    'orders delivered date heading' => str_contains($orders,'วันที่ได้รับพัสดุ</th>'),
    'Orders UI uses verified delivery helper' => str_contains($orders,'pan_delivery_view($o)'),
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
    'date received column retained' => str_contains($orders,'วันที่ได้รับพัสดุ</th>'),
    'analytics purchase date excludes completion fallback' => str_contains($analytics,"pan_order_placed_sql()"),
];
foreach($checks as $label=>$pass){
    if(!$pass)throw new RuntimeException('FAIL '.$label);
    echo 'PASS '.$label."\n";
}
