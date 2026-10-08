<?php
// A recent sync overlaps the newest saved order by seven calendar days.
// It is not a full-account scan and must never invoke reconcile_account_scan().
function recent_sync_anchor(PDO $db, string $accountId): array {
    if ($accountId==='' || strlen($accountId)>100) throw new InvalidArgumentException('account_id required');
    $query=$db->prepare("SELECT COUNT(*) AS total, MAX(NULLIF(order_date,'')) AS latest, MIN(CASE WHEN list_type IN (7,8,9) THEN NULLIF(order_date,'') END) AS pending_since, SUM(CASE WHEN list_type IN (7,8,9) AND COALESCE(order_date,'')='' THEN 1 ELSE 0 END) AS undated_pending FROM orders WHERE source_account_id=:aid");
    $query->execute([':aid'=>$accountId]); $row=$query->fetch(PDO::FETCH_ASSOC) ?: [];
    $latest=(string)($row['latest']??'');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$latest,new DateTimeZone('Asia/Bangkok'));
    if (!$date || $date->format('Y-m-d')!==$latest) { $date=null; $latest=''; }
    $cutoff=$date?$date->modify('-7 days')->format('Y-m-d'):'';
    $pending=(string)($row['pending_since']??'');
    if ($cutoff!=='' && $pending!=='' && $pending<$cutoff) $cutoff=$pending;
    if ((int)($row['undated_pending']??0)>0) $cutoff='';
    return ['account_id'=>$accountId,'known_orders'=>(int)($row['total']??0),'latest_order_date'=>$latest,
        'cutoff_date'=>$cutoff,'overlap_days'=>7,'pending_since'=>$pending];
}
