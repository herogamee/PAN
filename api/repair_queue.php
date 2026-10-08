<?php
require __DIR__.'/_bootstrap.php';require __DIR__.'/../app/db.php';header('Content-Type: application/json; charset=utf-8');
try{
  $id=trim((string)($_GET['account_id']??''));
  $limit=(int)($_GET['limit']??200);
  $offset=(int)($_GET['offset']??0);
  $legacy=($_GET['include_legacy']??'0')!=='0';
  $all=($_GET['all']??'0')!=='0';
  $q=repair_queue(db(),$id,$limit,$legacy,$all,$offset);
  echo json_encode(['ok'=>true,'count'=>count($q['rows']),'total'=>$q['total'],'offset'=>$q['offset'],'limit'=>$q['limit'],'has_more'=>$q['has_more'],'orders'=>$q['rows']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
