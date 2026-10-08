<?php
require __DIR__.'/_bootstrap.php';require __DIR__.'/../app/db.php';header('Content-Type: application/json; charset=utf-8');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST only']);exit;}
$data=json_decode(file_get_contents('php://input'),true);if(!is_array($data)){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Invalid JSON']);exit;}
try{$result=import_collector_payload(db(),$data);echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
