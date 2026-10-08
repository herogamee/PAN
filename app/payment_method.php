<?php
/**
 * Display-only interpretation of Shopee's buyer payment_method field.
 * Numeric values are opaque codes, NOT names or proof of payment success.
 * Do not invent mappings for undocumented private API values such as 6/92.
 */

declare(strict_types=1);

function pan_payment_method_is_code(string $value): bool {
    return preg_match('/^[0-9]+$/D', trim($value)) === 1;
}

function pan_payment_method_label(string $value): string {
    $value = trim($value);
    if ($value === '') return 'ไม่ทราบช่องทางการชำระเงิน';
    if (pan_payment_method_is_code($value)) return 'ยังไม่ทราบช่องทาง (รหัส Shopee '.$value.')';
    return $value;
}
