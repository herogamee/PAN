<?php
require __DIR__.'/_bootstrap.php';require __DIR__.'/../app/db.php';header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST only']);exit;}
try{$d=json_decode(file_get_contents('php://input'),true);if(!is_array($d))throw new RuntimeException('Invalid JSON');$r=reconcile_account_scan(db(),trim((string)($d['account_id']??'')),trim((string)($d['scan_id']??'')));echo json_encode(['ok'=>true]+$r,JSON_UNESCAPED_UNICODE);}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
