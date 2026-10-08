<?php
require __DIR__.'/_bootstrap.php';require __DIR__.'/../app/db.php';
header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST only']);exit;}
try{$d=json_decode(file_get_contents('php://input'),true);if(!is_array($d))throw new RuntimeException('Invalid JSON');$r=delete_cancelled_orders(db(),trim((string)($d['account_id']??'')),is_array($d['order_nos']??null)?$d['order_nos']:[]);echo json_encode(['ok'=>true]+$r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
