<?php
/** Source-only carrier policy regressions; no DB credentials or PDO drivers needed. */
declare(strict_types=1);
require_once __DIR__.'/../app/db.php';
function check_carrier(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException('FAIL '.$message);
    echo 'PASS '.$message."\n";
}
check_carrier(pan_optional_only_missing_fields('shipping_carrier'),'carrier alone is optional');
check_carrier(pan_optional_only_missing_fields('payment_method,shipping_carrier'),'obsolete payment and carrier together are optional');
check_carrier(pan_optional_only_missing_fields(' SHIPPING_CARRIER , payment_method '),'case/whitespace do not change optional meaning');
check_carrier(!pan_optional_only_missing_fields(''),'empty missing fields is not a legacy partial-marker');
check_carrier(!pan_optional_only_missing_fields('shipping_carrier,delivered_at'),'missing courier evidence is never optional');
check_carrier(!pan_optional_only_missing_fields('tracking_number'),'tracking evidence is not silently ignored');
$sql=pan_repair_required_sql('o');
check_carrier(str_contains($sql,'o.delivered_at')&&str_contains($sql,'o.detail_missing_fields'),'Repair SQL account alias supports verified courier evidence and carrier exclusion');
check_carrier(str_contains($sql,"'shipping_carrier'")&&str_contains($sql,"'partial'"),'Repair SQL excludes carrier-only partial but retains genuine partial');
echo "CARRIER REPAIR POLICY PASS\n";
