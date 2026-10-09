<?php
/**
 * Buyer-order purchase-date semantics only. Use creation-time evidence for display
 * and analytics, never paid/shipped/received/Complete timestamps as substitutes.
 * Delivery timestamps are retained in historical raw storage but retired from UI
 * until a real Buyer API delivery contract is verified.
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

/** Format stored Gregorian dates for Thai users. Dates without a real time stay date-only.
 * Naive date/times are already Asia/Bangkok from the Shopee Connector; explicit
 * ISO-8601 offsets are converted into Thailand's UTC+07:00 at display time.
 * Never rewrite the database values or convert the year used by SQL queries.
 */
function pan_thai_date_display(string $raw): string {
    $raw=trim($raw);
    if(!preg_match('/^(20\d{2})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?(Z|[+-]\d{2}:?\d{2})?)?$/D',$raw,$m))return '';
    $year=(int)$m[1];$month=(int)$m[2];$day=(int)$m[3];
    if(!checkdate($month,$day,$year))return '';
    $asDate=sprintf('%02d/%02d/%04d',$day,$month,$year+543);
    if(!isset($m[4])||$m[4]==='')return $asDate;
    if((int)$m[4]>23||(int)$m[5]>59||(isset($m[6])&&(int)$m[6]>59))return '';
    $zone=new DateTimeZone('Asia/Bangkok');
    $clock=sprintf('%04d-%02d-%02d %02d:%02d:%02d',$year,$month,$day,(int)$m[4],(int)$m[5],(int)($m[6]??0));
    $offset=$m[7]??'';
    if($offset!=='') {
        if($offset==='Z')$offset='+00:00';
        elseif(strlen($offset)===5)$offset=substr($offset,0,3).':'.substr($offset,3);
        try {$stamp=DateTimeImmutable::createFromFormat('!Y-m-d H:i:sP',$clock.$offset);}
        catch(Throwable $e){return '';}
        if($stamp===false||$stamp->format('Y-m-d H:i:s')!==$clock)return '';
        $stamp=$stamp->setTimezone($zone);
    }else{
        $stamp=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$clock,$zone);
        if($stamp===false||$stamp->format('Y-m-d H:i:s')!==$clock)return '';
    }
    return sprintf('%02d/%02d/%04d %02d:%02d', (int)$stamp->format('d'),(int)$stamp->format('m'),(int)$stamp->format('Y')+543,(int)$stamp->format('H'),(int)$stamp->format('i'));
}
function pan_thai_be_year(string|int $gregorian): string {
    return preg_match('/^20\d{2}$/D',(string)$gregorian)?(string)((int)$gregorian+543):(string)$gregorian;
}
/** Show previously verified purchase records not seen in an incomplete Full Sync,
 * without silently changing their historical verification state in the database.
 * This does not grant verification to unrelated legacy/suspicious records.
 */
function pan_purchase_visibility_sql(string $alias=''): string {
    $p=$alias!==''?$alias.'.':'';
    return "({$p}validation_state IN ('verified_v045','verified_v049','verified_v049_date_unknown','verified_v200') OR ({$p}validation_state='not_seen_full_scan' AND TRIM(COALESCE({$p}source_account_id,''))<>''))";
}
/** Only for ordering rows with no credible purchase date: import/observation time
 * NEVER becomes the displayed date or the month of purchase.
 */
function pan_order_sort_sql(string $alias=''): string {
    $p=$alias!==''?$alias.'.':'';
    return 'COALESCE('.pan_order_placed_sql($alias).",NULLIF({$p}last_seen_at,''),NULLIF({$p}created_at,''))";
}

/** Text for an order-created column, preserving date-only precision. */
function pan_order_placed_view(array $order): array {
    $rawCreated=(string)($order['order_created_at']??'');
    $created=pan_date_text($rawCreated);
    $createdView=pan_thai_date_display($rawCreated);
    if ($createdView!=='' && ($created!=='' || preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/',$rawCreated)))
        return ['value'=>$createdView,'note'=>preg_match('/^20\d{2}-\d{2}-\d{2}$/D',$rawCreated)?'Shopee ไม่ระบุเวลา':'','title'=>'วันที่สร้างคำสั่งซื้อ (เวลาไทย 24 ชั่วโมง หากมีเวลา)'];
    if (pan_order_date_source_is_other_event((string)($order['date_source']??''))) {
        return ['value'=>'ไม่ทราบวันที่สั่งซื้อ','note'=>'ข้อมูลเดิมมาจากเหตุการณ์อื่น','title'=>'ไม่ใช้วันชำระเงิน วันส่งสินค้า หรือวันปิดออเดอร์แทนวันสั่งซื้อ'];
    }
    $date=pan_date_text((string)($order['order_date']??''));
    if ($date!=='')return ['value'=>pan_thai_date_display($date),'note'=>pan_time_is_precise($date)?'':'Shopee ไม่ระบุเวลา','title'=>'ข้อมูลมีเพียงวันที่สั่งซื้อ ไม่สร้างเวลาสมมติ'];
    return ['value'=>'ไม่ทราบวันที่สั่งซื้อ','note'=>'รอข้อมูลจาก Shopee','title'=>'ไม่มีหลักฐานวันที่สร้างออเดอร์'];
}

/** The same purchase-date contract is used for sort, dashboard and analytics. */
function pan_order_placed_sql(string $alias=''): string {
    $p=$alias!==''?$alias.'.':'';
    $source="LOWER(COALESCE({$p}date_source,''))";
    $isOther="($source LIKE '%pay%' OR $source LIKE '%shipping%' OR $source LIKE '%shipment%' OR $source LIKE '%tracking%' OR $source LIKE '%deliver%' OR $source LIKE '%complet%' OR $source LIKE '%receiv%' OR $source LIKE '%dispatch%' OR $source LIKE '%handover%')";
    return "COALESCE(NULLIF({$p}order_created_at,''),NULLIF(CASE WHEN $isOther THEN '' ELSE {$p}order_date END,''))";
}
