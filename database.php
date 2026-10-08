<?php
require_once __DIR__.'/app/bootstrap.php';
hub_require_installed();hub_require_login();
require_once __DIR__.'/app/db.php';
require_once __DIR__.'/app/migrate.php';

$db=db();$cfg=hub_config();$dbCfg=hub_db_config($cfg);$driver=db_driver($db);$flash='';$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    hub_verify_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='clear_maintenance'){
            $cfg['maintenance']=false;hub_save_config($cfg);header('Location: ./database.php?maintenance_cleared=1');exit;
        }
        if($action==='rotate_api_key'){
            $cfg['api_key']=bin2hex(random_bytes(32));hub_save_config($cfg);header('Location: ./database.php?rotated=1');exit;
        }
        $target=[
          'driver'=>'mysql','host'=>trim((string)($_POST['db_host']??'127.0.0.1')),'port'=>(int)($_POST['db_port']??3306),
          'database'=>trim((string)($_POST['db_name']??'')),'username'=>(string)($_POST['db_user']??''),'password'=>(string)($_POST['db_pass']??''),'charset'=>'utf8mb4'
        ];
        if($action==='test_mysql'){
            $t=db_connect($target);ensure_schema_v200($t);$flash='เชื่อมต่อ MySQL สำเร็จ และตรวจ schema ผ่าน';
        }
        if($action==='migrate_mysql'){
            @set_time_limit(0);
            ignore_user_abort(true);
            if($driver!=='sqlite')throw new RuntimeException('Migration อัตโนมัตินี้รองรับ SQLite → MySQL เท่านั้น');
            if(empty($_POST['confirm_backup']))throw new RuntimeException('กรุณายืนยันว่าเข้าใจว่า SQLite เดิมจะถูกเก็บไว้เป็น backup');
            $cfg['maintenance']=true;hub_save_config($cfg);
            try{
                $result=migrate_sqlite_to_mysql($dbCfg,$target);
                $cfg['previous_db']=$dbCfg;$cfg['db']=$target;$cfg['maintenance']=false;$cfg['last_migration']=['at'=>date('c'),'from'=>'sqlite','to'=>'mysql','counts'=>$result['target']];
                hub_save_config($cfg);
            }catch(Throwable $e){$cfg['maintenance']=false;hub_save_config($cfg);throw $e;}
            header('Location: ./database.php?migrated=1');exit;
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$cfg=hub_config();$driver=db_driver($db);$counts=db_counts($db);$apiKey=(string)($cfg['api_key']??'');$appUrl=(string)($cfg['app_url']??'');
$previousSqlitePath='';
if($driver==='mysql' && is_array($cfg['previous_db']??null) && (($cfg['previous_db']['driver']??'')==='sqlite')) $previousSqlitePath=db_sqlite_path_from_config($cfg['previous_db']);
if(isset($_GET['migrated']))$flash='ย้าย SQLite → MySQL สำเร็จ ตรวจจำนวนข้อมูลผ่าน และสลับระบบไปใช้ MySQL แล้ว';
if(isset($_GET['rotated']))$flash='สร้าง API Key ใหม่แล้ว กรุณาอัปเดต Key ใน Connector';
if(isset($_GET['installed']))$flash='ติดตั้งสำเร็จ พร้อมใช้งานแล้ว';
if(isset($_GET['maintenance_cleared']))$flash='ปิด Maintenance mode แล้ว';
function dh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Database Manager · PAN — น้องแพน</title><style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;background:#f6f7fb;color:#18181b;margin:0}.layout{display:grid;grid-template-columns:220px 1fr;min-height:100vh}.side{background:#fff;border-right:1px solid #e5e7eb;padding:20px 14px}.side a{display:block;padding:10px 12px;border-radius:9px;text-decoration:none;color:#444;margin:3px 0}.side a.active{background:#fff0ec;color:#ee4d2d;font-weight:800}.main{padding:28px}.wrap{max-width:1050px;margin:auto}.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:18px;margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.stat{padding:12px;border:1px solid #eee;border-radius:10px}.stat b{display:block;font-size:22px}.muted{color:#6b7280;line-height:1.6}.badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#ecfdf5;color:#047857;font-weight:800}.warning{background:#fff7ed;border-color:#fed7aa}.error{background:#fef2f2;border-color:#fecaca}.ok{background:#ecfdf5;border-color:#a7f3d0}.formgrid{display:grid;grid-template-columns:2fr 1fr 2fr 2fr;gap:10px}label{display:block;font-weight:700;margin:8px 0 5px}input{width:100%;padding:9px;border:1px solid #d1d5db;border-radius:8px}.btn{border:1px solid #ddd;background:#fff;padding:10px 13px;border-radius:9px;font-weight:800;cursor:pointer}.btn.primary{background:#ee4d2d;color:#fff;border-color:#ee4d2d}.code{font-family:ui-monospace,monospace;background:#f3f4f6;padding:9px;border-radius:8px;word-break:break-all}.row{display:flex;gap:9px;flex-wrap:wrap;align-items:center}@media(max-width:850px){.layout{display:block}.side{display:none}.grid,.formgrid{grid-template-columns:1fr 1fr}.main{padding:14px}}@media(max-width:520px){.grid,.formgrid{grid-template-columns:1fr}}
</style></head><body><div class="layout"><aside class="side"><b style="display:block;padding:8px 12px 18px">PAN <small style="display:block;color:#6b7280;font-weight:600;margin-top:3px">น้องแพน · by itoom.work</small><span style="color:#ee4d2d">v2.5.2</span></b><a href="./">← Dashboard</a><a class="active" href="./database.php">Database Manager</a><a href="./?page=settings">ตั้งค่า / จัดการข้อมูล</a><a href="./logout.php">ออกจากระบบ</a></aside><main class="main"><div class="wrap">
<h1>Database Manager</h1><p class="muted">เลือกเริ่มด้วย SQLite ได้ แล้วค่อยย้ายไป MySQL เมื่อระบบโตขึ้น โดยไม่ต้องทิ้งข้อมูลเดิม</p>
<?php if($flash):?><div class="card ok"><b><?=dh($flash)?></b></div><?php endif;?><?php if($error):?><div class="card error"><b>ไม่สำเร็จ:</b> <?=dh($error)?></div><?php endif;?>
<?php if(!empty($cfg['maintenance'])):?><div class="card error"><b>Maintenance mode ยังเปิดอยู่</b><p class="muted">อาจเกิดจาก Migration ก่อนหน้าถูกตัดกลางทาง API Collector จะตอบ 503 จนกว่าจะปิดโหมดนี้ ตรวจ MySQL ปลายทางก่อน แล้วค่อยปลด Maintenance</p><form method="post"><?=hub_csrf_field()?><input type="hidden" name="action" value="clear_maintenance"><button class="btn" onclick="return confirm('ปิด Maintenance mode?')">ปลด Maintenance</button></form></div><?php endif;?>
<div class="card"><div class="row"><h3 style="margin:0">ฐานข้อมูลปัจจุบัน</h3><span class="badge"><?=strtoupper(dh($driver))?></span></div><div class="grid" style="margin-top:12px"><?php foreach($counts as $k=>$n):?><div class="stat"><span><?=dh($k)?></span><b><?=number_format($n)?></b></div><?php endforeach;?></div><p class="muted">Driver ปัจจุบันอ่านจาก <code>storage/config.php</code> และสามารถ override ด้วย environment variables HUB_DB_* ได้</p></div>
<div class="card"><h3>ตั้งค่า Connector</h3><label>PAN URL</label><div class="code" id="huburl"><?=dh($appUrl)?></div><label>API Key</label><div class="code" id="apikey"><?=dh($apiKey)?></div><p class="muted">ใส่ทั้ง 2 ค่าใน Shopee Connector แล้วกด “ตรวจการเชื่อมต่อ PAN” API ทุกตัวต้องมี Key นี้ จึงไม่เปิดรับการเขียนข้อมูลแบบสาธารณะอีกต่อไป</p><form method="post" onsubmit="return confirm('สร้าง API Key ใหม่? Connector ที่ใช้ Key เดิมจะเชื่อมต่อไม่ได้จนกว่าจะอัปเดต')"><?=hub_csrf_field()?><input type="hidden" name="action" value="rotate_api_key"><button class="btn">สร้าง API Key ใหม่</button></form></div>
<?php if($driver==='sqlite'):?>
<div class="card warning"><h3>ย้าย SQLite → MySQL / MariaDB</h3><p class="muted">ระหว่างย้าย ระบบจะเข้า Maintenance ชั่วคราวเพื่อไม่ให้ Collector เขียนข้อมูลพร้อมกัน จากนั้นจะสร้าง schema, copy ข้อมูล, ตรวจจำนวนแถวทุกตาราง และ <b>สลับ config ไป MySQL เฉพาะเมื่อผ่านครบ</b> ไฟล์ SQLite เดิมจะไม่ถูกลบและใช้เป็น rollback backup ได้</p>
<form method="post"><div class="formgrid"><div><label>Host</label><input name="db_host" value="127.0.0.1"></div><div><label>Port</label><input name="db_port" value="3306"></div><div><label>Database</label><input name="db_name" value="itoom_pan"></div><div><label>Username</label><input name="db_user"></div></div><label>Password</label><input type="password" name="db_pass"><?=hub_csrf_field()?><div style="margin:12px 0"><label style="font-weight:500"><input style="width:auto" type="checkbox" name="confirm_backup" value="1"> ฉันเข้าใจว่า MySQL ปลายทางต้องเป็นฐานว่าง และ SQLite เดิมจะถูกเก็บไว้เป็น backup</label></div><div class="row"><button class="btn" name="action" value="test_mysql">ทดสอบ MySQL</button><button class="btn primary" name="action" value="migrate_mysql" onclick="return confirm('เริ่มย้ายฐานข้อมูล SQLite → MySQL ตอนนี้?')">ย้ายข้อมูลและสลับไป MySQL</button></div></form></div>
<?php else:?>
<div class="card ok"><h3>ระบบกำลังใช้ MySQL แล้ว</h3><p class="muted">เหมาะสำหรับการใช้งานระยะยาวบน server, หลาย Collector และข้อมูลที่โตต่อเนื่อง แนะนำให้ตั้ง scheduled database backup ฝั่ง server เพิ่มเติม</p><?php if($previousSqlitePath!==''&&is_file($previousSqlitePath)):?><p class="muted">SQLite ก่อนย้ายยังถูกเก็บไว้ที่ <code><?=dh(str_replace(HUB_ROOT.DIRECTORY_SEPARATOR,'',$previousSqlitePath))?></code> เป็น snapshot สำรอง ณ เวลาที่ Migration สำเร็จ ระบบจะไม่เขียนไฟล์นี้ต่อหลังสลับไป MySQL</p><?php endif;?><?php if(!empty($cfg['last_migration'])):?><div class="code">Migration ล่าสุด: <?=dh($cfg['last_migration']['at']??'')?> · SQLite → MySQL</div><?php endif;?></div>
<?php endif;?>
<div class="card"><h3>เลือกแบบไหนดี?</h3><p><b>SQLite:</b> ใช้ง่ายที่สุด, backup เป็นไฟล์เดียว, เหมาะกับเริ่มต้น/ผู้ใช้เดียว/เขียนข้อมูลไม่พร้อมกันมาก</p><p><b>MySQL/MariaDB:</b> แนะนำเมื่อใช้จริงบน `itoom.work` ระยะยาว, มีหลาย Collector/หลายอุปกรณ์, ต้องการ backup/monitoring/replication หรือ query หนักขึ้น</p><p class="muted">ไม่จำเป็นต้องย้ายเพราะ “จำนวน Order ถึงเลขตายตัว” — ตัวชี้วัดที่ควรย้ายคือ concurrency, เวลา query/analytics, backup requirement และการเติบโตของระบบมากกว่า</p></div>
</div></main></div></body></html>
