<?php
require_once __DIR__.'/../app/payment_method.php';

$checks = [
    ['6', true, 'ยังไม่ทราบช่องทาง (รหัส Shopee 6)'],
    [' 92 ', true, 'ยังไม่ทราบช่องทาง (รหัส Shopee 92)'],
    ['ShopeePay', false, 'ShopeePay'],
    ['SPayLater', false, 'SPayLater'],
    ['เก็บเงินปลายทาง', false, 'เก็บเงินปลายทาง'],
    ['', false, 'ไม่ทราบช่องทางการชำระเงิน'],
    ['6a', false, '6a'],
];
foreach ($checks as [$raw, $isCode, $expected]) {
    if (pan_payment_method_is_code($raw) !== $isCode) throw new RuntimeException('code mismatch: '.$raw);
    if (pan_payment_method_label($raw) !== $expected) throw new RuntimeException('label mismatch: '.$raw);
    echo 'PASS payment method fixture '.($raw === '' ? '(empty)' : trim($raw))."\n";
}
echo "PAYMENT METHOD DISPLAY TESTS PASS\n";
