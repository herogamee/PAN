<?php
require_once __DIR__.'/../app/config.php';
require_once __DIR__.'/../app/auth.php';
require_once __DIR__.'/../app/db.php';
require_once __DIR__.'/../app/migrate.php';

if(hub_is_installed()){header('Location: ../');exit;}
$errors=[];$success=false;
$setupToken=trim((string)getenv('PAN_SETUP_TOKEN'));$remote=(string)($_SERVER['REMOTE_ADDR']??'');$isLocal=in_array($remote,['127.0.0.1','::1',''],true);
if(!$isLocal && $setupToken===''){$errors[]='Public First Run ถูกล็อก: กรุณาตั้ง environment PAN_SETUP_TOKEN ก่อนติดตั้ง';}
$canonicalSqlite=HUB_STORAGE.DIRECTORY_SEPARATOR.HUB_SQLITE_FILENAME;
$legacySqlite=HUB_STORAGE.DIRECTORY_SEPARATOR.HUB_LEGACY_SQLITE_FILENAME;
$olderLegacySqlite=HUB_STORAGE.DIRECTORY_SEPARATOR.HUB_OLDER_LEGACY_SQLITE_FILENAME;
$existingSqlite=is_file($canonicalSqlite)&&filesize($canonicalSqlite)>0?$canonicalSqlite:(is_file($legacySqlite)&&filesize($legacySqlite)>0?$legacySqlite:(is_file($olderLegacySqlite)&&filesize($olderLegacySqlite)>0?$olderLegacySqlite:$canonicalSqlite));
$hasExistingSqlite=is_file($existingSqlite)&&filesize($existingSqlite)>0;
$existingSqliteRel='storage/'.basename($existingSqlite);
$defaultUrl=hub_app_url_guess();

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    if($setupToken!=='' && !hash_equals($setupToken,(string)($_POST['setup_token']??'')))$errors[]='Setup Token ไม่ถูกต้อง';
    $driver=in_array($_POST['driver']??'sqlite',['sqlite','mysql'],true)?$_POST['driver']:'sqlite';
    $appUrl=rtrim(trim((string)($_POST['app_url']??$defaultUrl)),'/');
    $admin=trim((string)($_POST['admin_user']??'admin'));
    $password=(string)($_POST['admin_password']??'');
    if($admin==='')$errors[]='กรุณาระบุชื่อผู้ดูแลระบบ';
    if(strlen($password)<8)$errors[]='รหัสผ่านผู้ดูแลต้องอย่างน้อย 8 ตัวอักษร';
    if(!hub_storage_ready())$errors[]='โฟลเดอร์ storage/ ต้องเขียนได้โดย Web Server';
    $dbCfg=[];
    if($driver==='sqlite'){
        $dbCfg=['driver'=>'sqlite','path'=>$hasExistingSqlite?$existingSqliteRel:'storage/'.HUB_SQLITE_FILENAME];
        if(!extension_loaded('pdo_sqlite'))$errors[]='PHP ยังไม่ได้เปิด extension pdo_sqlite';
    }else{
        $dbCfg=[
          'driver'=>'mysql','host'=>trim((string)($_POST['db_host']??'127.0.0.1')),
          'port'=>(int)($_POST['db_port']??3306),'database'=>trim((string)($_POST['db_name']??'')),
          'username'=>(string)($_POST['db_user']??''),'password'=>(string)($_POST['db_pass']??''),'charset'=>'utf8mb4'
        ];
        if($dbCfg['database']==='')$errors[]='กรุณาระบุชื่อฐานข้อมูล MySQL';
        if(!extension_loaded('pdo_mysql'))$errors[]='PHP ยังไม่ได้เปิด extension pdo_mysql';
    }
    if(!$errors){
        try{
            $pdo=db_connect($dbCfg);ensure_schema_v200($pdo);
            $migration=null;
            if($driver==='mysql'&&$hasExistingSqlite&&!empty($_POST['migrate_existing'])){
                @set_time_limit(0);
                $migration=migrate_sqlite_to_mysql(['driver'=>'sqlite','path'=>$existingSqliteRel],$dbCfg);
            }
            $cfg=[
              'version'=>'2.5.2','installed_at'=>date('c'),'app_url'=>$appUrl,
              'admin_user'=>$admin,'admin_password_hash'=>password_hash($password,PASSWORD_DEFAULT),
              'api_key'=>bin2hex(random_bytes(32)),'maintenance'=>false,'db'=>$dbCfg
            ];
            if($migration){$cfg['previous_db']=['driver'=>'sqlite','path'=>$existingSqliteRel];$cfg['last_migration']=['at'=>date('c'),'from'=>'sqlite','to'=>'mysql','counts'=>$migration['target']];}
            hub_save_config($cfg);
            hub_login_attempt($admin,$password);
            header('Location: ../database.php?installed=1');exit;
        }catch(Throwable $e){$errors[]=$e->getMessage();}
    }
}
function eh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ติดตั้ง PAN — น้องแพน 2.5.2</title><style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:#f6f7fb;color:#18181b;margin:0;padding:32px}.wrap{max-width:980px;margin:auto}.hero,.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:22px;margin-bottom:14px}.hero h1{margin:0 0 8px}.muted{color:#6b7280;line-height:1.6}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.choice{border:1px solid #ddd;border-radius:13px;padding:15px}label{font-weight:700;display:block;margin:10px 0 5px}input[type=text],input[type=password],input[type=number]{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:9px}.mysql{padding-top:4px}.note{background:#fff7ed;border:1px solid #fed7aa;padding:12px;border-radius:10px;margin:12px 0}.ok{background:#ecfdf5;border:1px solid #a7f3d0}.err{background:#fef2f2;border:1px solid #fecaca}.btn{border:0;background:#ee4d2d;color:#fff;padding:12px 18px;border-radius:10px;font-weight:800;cursor:pointer}.checks{display:grid;gap:6px;font-size:13px}@media(max-width:760px){.grid{grid-template-columns:1fr}body{padding:14px}}
</style></head><body><div class="wrap">
<div class="hero"><h1>PAN — น้องแพน 2.5.2 · First Run</h1><div class="muted">ตั้งค่าฐานข้อมูล, Admin Login และ API Key สำหรับ Shopee Connector ในครั้งเดียว รองรับ SQLite และ MySQL ตั้งแต่วันแรก</div></div>
<?php if($errors):?><div class="card err"><b>ติดตั้งยังไม่สำเร็จ</b><ul><?php foreach($errors as $e):?><li><?=eh($e)?></li><?php endforeach;?></ul></div><?php endif;?>
<div class="card"><h3>ตรวจสภาพแวดล้อม</h3><div class="checks"><div>PHP: <b><?=eh(PHP_VERSION)?></b></div><div>PDO SQLite: <b><?=extension_loaded('pdo_sqlite')?'พร้อม':'ยังไม่เปิด'?></b></div><div>PDO MySQL: <b><?=extension_loaded('pdo_mysql')?'พร้อม':'ยังไม่เปิด'?></b></div><div>storage/: <b><?=hub_storage_ready()?'เขียนได้':'เขียนไม่ได้'?></b></div><?php if($hasExistingSqlite):?><div>พบ SQLite เดิม <code><?=eh(basename($existingSqlite))?></code>: <b><?=number_format(filesize($existingSqlite)/1024/1024,2)?> MB</b> — สามารถย้ายเข้า MySQL ตอนติดตั้งได้</div><?php endif;?></div></div>
<form method="post" class="card">
<h3>1. เลือกฐานข้อมูล</h3><div class="grid">
<label class="choice"><input type="radio" name="driver" value="sqlite" <?=($_POST['driver']??'sqlite')==='sqlite'?'checked':''?>> SQLite<div class="muted">ง่ายที่สุด ไม่ต้องสร้าง DB เพิ่ม เหมาะกับผู้ใช้คนเดียว/Collector ไม่กี่เครื่อง และเริ่มใช้งานเร็ว</div></label>
<label class="choice"><input type="radio" name="driver" value="mysql" <?=($_POST['driver']??'')==='mysql'?'checked':''?>> MySQL / MariaDB<div class="muted">แนะนำสำหรับใช้งานระยะยาวบน server, หลาย Collector, backup/monitoring จริงจัง หรือข้อมูลโตต่อเนื่อง</div></label>
</div>
<div class="mysql"><div class="grid"><div><label>MySQL Host</label><input name="db_host" value="<?=eh($_POST['db_host']??'127.0.0.1')?>"></div><div><label>Port</label><input type="number" name="db_port" value="<?=eh($_POST['db_port']??'3306')?>"></div><div><label>Database</label><input name="db_name" value="<?=eh($_POST['db_name']??'itoom_pan')?>"></div><div><label>Username</label><input name="db_user" value="<?=eh($_POST['db_user']??'')?>"></div></div><label>Password</label><input type="password" name="db_pass" value=""></div>
<?php if($hasExistingSqlite):?><div class="note"><label style="margin:0"><input type="checkbox" name="migrate_existing" value="1" <?=!empty($_POST['migrate_existing'])?'checked':''?>> ถ้าเลือก MySQL ให้ย้ายข้อมูลจาก SQLite เดิมเข้า MySQL ตอนนี้</label><div class="muted">ระบบจะไม่ลบ SQLite เดิม และจะสลับไป MySQL เฉพาะเมื่อ copy + ตรวจจำนวนข้อมูลผ่านครบ</div></div><?php endif;?>
<?php if($setupToken!==''):?><h3>2. Setup Token</h3><label>Setup Token</label><input type="password" name="setup_token" autocomplete="off" placeholder="PAN_SETUP_TOKEN"><div class="muted">ใช้ยืนยันว่า First Run นี้เป็นของผู้ดูแลระบบตัวจริง</div><?php endif;?><h3>3. URL และ Admin</h3><label>PAN URL</label><input name="app_url" value="<?=eh($_POST['app_url']??$defaultUrl)?>" placeholder="https://pan.itoom.work"><div class="grid"><div><label>Admin Username</label><input name="admin_user" value="<?=eh($_POST['admin_user']??'admin')?>"></div><div><label>Admin Password</label><input type="password" name="admin_password" autocomplete="new-password"></div></div>
<div class="note ok"><b>API Key จะถูกสร้างให้อัตโนมัติ</b><div class="muted">หลังติดตั้ง เปิดหน้า Database Manager เพื่อ Copy PAN URL + API Key ไปใส่ Shopee Connector</div></div>
<button class="btn" type="submit">ติดตั้งและตรวจการเชื่อมต่อ</button>
</form></div></body></html>
