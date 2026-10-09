<?php
/**
 * Buyer-order timeline semantics: an order was placed at order_created_at, and a
 * parcel was delivered only when courier delivery evidence exists. Payment,
 * shipping, buyer acknowledgement and order completion are different events.
 * These helpers are display/query-only; no historical database rewrite.
 */
declare(strict_types=1);

function pan_date_text(string $value): string {
    $value=trim($value);
    if (!preg_match('/^(20\d{2})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/D',$value,$m)) return '';
    if (!checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return '';
    if (isset($m[4]) && ((int)$m[4]>23 || (int)$m[5]>59 || (isset($m[6]) && (int)$m[6]>59)))return '';
    return str_replace('T',' ',$value);
}

function pan_time_is_precise(string $value): bool {
    return pan_date_text($value)!=='' && preg_match('/^20\d{2}-\d{2}-\d{2}[ T]\d{2}:\d{2}/D',$value)===1;
}

function pan_order_date_source_is_other_event(string $source): bool {
    // Historical collector versions sometimes used payment or shipping time as
    // a fallback for order date. Those values are NOT a verified purchase date.
    $source=strtolower(trim($source));
    return $source!=='' && preg_match('/(?:pay(?:ment|_time)?|paid|shipping|shipment|tracking|delivery|delivered|complete(?:d)?|received|dispatch|handover)/',$source)===1;
}

/** Text for an order-created column, preserving date-only precision. */
function pan_order_placed_view(array $order): array {
    $created=pan_date_text((string)($order['order_created_at']??''));
    if ($created!=='')return ['value'=>$created,'note'=>pan_time_is_precise($created)?'':'Shopee ไม่ระบุเวลา','title'=>'วันที่สร้างคำสั่งซื้อ (Asia/Bangkok เมื่อเป็น timestamp)'];
    if (pan_order_date_source_is_other_event((string)($order['date_source']??''))) {
        return ['value'=>'ไม่ทราบวันที่สั่งซื้อ','note'=>'ข้อมูลเดิมมาจากเหตุการณ์อื่น','title'=>'ไม่ใช้วันชำระเงิน วันส่งสินค้า หรือวันปิดออเดอร์แทนวันสั่งซื้อ'];
    }
    $date=pan_date_text((string)($order['order_date']??''));
    if ($date!=='')return ['value'=>$date,'note'=>pan_time_is_precise($date)?'':'Shopee ไม่ระบุเวลา','title'=>'ข้อมูลมีเพียงวันที่สั่งซื้อ ไม่สร้างเวลาสมมติ'];
    return ['value'=>'ไม่ทราบวันที่สั่งซื้อ','note'=>'รอข้อมูลจาก Shopee','title'=>'ไม่มีหลักฐานวันที่สร้างออเดอร์'];
}

/** Only unambiguously courier-delivered timestamps qualify. */
function pan_delivery_source_confirmed(string $source): bool {
    $source=strtolower(trim($source));
    if ($source==='')return false;
    if (preg_match('/(?:estimated|estimate|expected|predicted|plan|promise|pickup|dispatch|handover|warehouse|shipout|shipped|shipping_time|buyer_received|order_complete)/',$source))return false;
    return preg_match('/(?:^|\.)(?:delivered_time|actual_delivery_time|delivery_completed_time|courier_delivered_at|carrier_delivered_at|parcel_delivered_at)$/D',$source)===1;
}

function pan_delivery_view(array $order): array {
    $date=pan_date_text((string)($order['delivered_at']??''));
    $src=(string)($order['delivery_date_source']??'');
    if ($date!=='' && pan_delivery_source_confirmed($src)) {
        return ['value'=>$date,'note'=>pan_time_is_precise($date)?'':'ไม่พบเวลานำส่ง','title'=>'วันที่ขนส่งยืนยันพัสดุถึงผู้รับ · แหล่ง: '.$src];
    }
    if ($date!=='')return ['value'=>'ยังไม่ยืนยันวันรับพัสดุ','note'=>'วันที่เดิมไม่มีหลักฐานนำส่ง','title'=>'ไม่แสดงเวลาเก่าที่มีแหล่งข้อมูลคลุมเครือ: '.$src];
    if (trim((string)($order['detail_error']??''))!=='')return ['value'=>'ตรวจวันรับพัสดุไม่สำเร็จ','note'=>'ลองเติมรายละเอียดอีกครั้ง','title'=>'API รายละเอียดออเดอร์ผิดพลาด ไม่ใช่หลักฐานว่าพัสดุถึงแล้ว'];
    if ((int)($order['detail_enriched']??0)===1)return ['value'=>'Shopee ไม่ระบุวันรับพัสดุ','note'=>'ไม่ใช้วัน Complete แทน','title'=>'API ยังไม่มี timestamp ยืนยันว่าเจ้าหน้าที่ขนส่งนำส่งพัสดุแล้ว'];
    return ['value'=>'รอข้อมูลนำส่งถึงผู้รับ','note'=>'ยังไม่ได้ยืนยันวันรับ','title'=>'ส่งสำเร็จหมายถึงพัสดุถึงผู้รับ ไม่ใช่ร้านเริ่มส่งหรือปิดออเดอร์'];
}

/** The same purchase-date contract is used for sort, dashboard and analytics. */
function pan_order_placed_sql(string $alias=''): string {
    $p=$alias!==''?$alias.'.':'';
    $source="LOWER(COALESCE({$p}date_source,''))";
    $isOther="($source LIKE '%pay%' OR $source LIKE '%shipping%' OR $source LIKE '%shipment%' OR $source LIKE '%tracking%' OR $source LIKE '%deliver%' OR $source LIKE '%complet%' OR $source LIKE '%receiv%' OR $source LIKE '%dispatch%' OR $source LIKE '%handover%')";
    return "COALESCE(NULLIF({$p}order_created_at,''),NULLIF(CASE WHEN $isOther THEN '' ELSE {$p}order_date END,''))";
}
