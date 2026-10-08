<?php
require __DIR__.'/_bootstrap.php';
require __DIR__.'/../app/db.php';
require __DIR__.'/../app/sync_anchor.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'GET only']); exit; }
try {
    $result=recent_sync_anchor(db(),trim((string)($_GET['account_id']??'')));
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
