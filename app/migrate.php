<?php
require_once __DIR__ . '/db.php';

function db_counts(PDO $db): array {
    $out=[];
    foreach(['orders','order_items','accounts','collector_batches'] as $t){
        $out[$t]=db_table_exists($db,$t)?(int)$db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn():0;
    }
    return $out;
}

function db_target_is_empty(PDO $db): bool {
    foreach(db_counts($db) as $n) if($n>0)return false;
    return true;
}

function db_copy_table(PDO $source,PDO $target,string $table,int $batchSize=500): int {
    if(!db_table_exists($source,$table)||!db_table_exists($target,$table))return 0;
    $srcCols=array_keys(db_table_columns($source,$table));
    $dstCols=array_keys(db_table_columns($target,$table));
    $cols=array_values(array_intersect($srcCols,$dstCols));
    if(!$cols)return 0;
    $quoted=implode(',',array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',$cols));
    $ph=implode(',',array_fill(0,count($cols),'?'));
    $insert=$target->prepare("INSERT INTO `$table` ($quoted) VALUES ($ph)");
    $count=0;$offset=0;
    while(true){
        $rows=$source->query("SELECT $quoted FROM `$table` ORDER BY 1 LIMIT ".(int)$batchSize." OFFSET ".(int)$offset)->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows)break;
        foreach($rows as $r){$insert->execute(array_map(fn($c)=>$r[$c]??null,$cols));$count++;}
        $offset+=count($rows);
        if(count($rows)<$batchSize)break;
    }
    return $count;
}

function db_copy_legacy_accounts(PDO $source,PDO $target): int {
    if(!db_table_exists($source,'accounts'))return 0;
    $src=db_table_columns($source,'accounts');
    // Legacy v1 may have account_key plus an account_id column added by schema upgrade; prefer legacy mapping first.
    if(!isset($src['account_key'])){
        if(isset($src['account_id']))return db_copy_table($source,$target,'accounts');
        return 0;
    }
    $rows=$source->query('SELECT * FROM accounts')->fetchAll(PDO::FETCH_ASSOC);$count=0;
    $sql=$target->prepare("INSERT INTO accounts(account_id,username,nickname,country,last_sync_at,last_repair_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE username=VALUES(username),nickname=VALUES(nickname),last_sync_at=VALUES(last_sync_at),last_repair_at=VALUES(last_repair_at),updated_at=VALUES(updated_at)");
    foreach($rows as $r){
        $id=(string)($r['shopee_user_id']??'');if($id==='')$id=preg_replace('/^shopee-/','',(string)($r['account_key']??''));if($id==='')continue;
        $sql->execute([$id,(string)($r['username']??''),(string)($r['nickname']??''),'',(string)($r['last_sync_at']??''),(string)($r['last_repair_at']??''),(string)($r['created_at']??date('Y-m-d H:i:s')),(string)($r['updated_at']??date('Y-m-d H:i:s'))]);$count++;
    }
    return $count;
}

function migrate_sqlite_to_mysql(array $sqliteCfg,array $mysqlCfg): array {
    $sqliteCfg['driver']='sqlite';$mysqlCfg['driver']='mysql';
    $source=db_connect($sqliteCfg);
    $target=db_connect($mysqlCfg);
    ensure_schema_v200($source);ensure_schema_v200($target);
    if(!db_target_is_empty($target))throw new RuntimeException('ฐาน MySQL ปลายทางต้องว่างก่อน Migration เพื่อป้องกันข้อมูลซ้ำ/ชนกัน');
    $sourceCounts=db_counts($source);
    $copied=[];
    $target->beginTransaction();
    try{
        $target->exec('SET FOREIGN_KEY_CHECKS=0');
        $copied['orders']=db_copy_table($source,$target,'orders');
        $copied['order_items']=db_copy_table($source,$target,'order_items');
        $copied['accounts']=db_copy_legacy_accounts($source,$target);
        $copied['collector_batches']=db_copy_table($source,$target,'collector_batches');
        $targetCounts=db_counts($target);
        foreach(['orders','order_items','collector_batches'] as $t){
            if((int)$sourceCounts[$t] !== (int)$targetCounts[$t])throw new RuntimeException("ตรวจสอบ Migration ไม่ผ่าน: $t ต้นทาง {$sourceCounts[$t]} / ปลายทาง {$targetCounts[$t]}");
        }
        if((int)$copied['accounts'] !== (int)$targetCounts['accounts'])throw new RuntimeException('ตรวจสอบ Migration accounts ไม่ผ่าน');
        $target->exec('SET FOREIGN_KEY_CHECKS=1');
        $target->commit();
    }catch(Throwable $e){
        if($target->inTransaction())$target->rollBack();
        try{$target->exec('SET FOREIGN_KEY_CHECKS=1');}catch(Throwable $ignore){}
        throw $e;
    }
    return ['source'=>$sourceCounts,'copied'=>$copied,'target'=>$targetCounts];
}
