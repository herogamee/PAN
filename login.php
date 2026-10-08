<?php
require_once __DIR__.'/app/config.php';require_once __DIR__.'/app/auth.php';require_once __DIR__.'/app/login_throttle.php';
header('Cache-Control: no-store, private'); header('X-Frame-Options: DENY');
if(!hub_is_installed()){header('Location: ./install/');exit;}
if(hub_logged_in()){header('Location: ./');exit;}
$error='';$next=(string)($_GET['next']??$_POST['next']??'./');if(!preg_match('~^/(?!/)~',$next))$next='./';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  hub_session_start();
  $given=(string)($_POST['csrf_token']??'');$known=(string)($_SESSION['hub_csrf']??'');
  if($known===''||$given===''||!hash_equals($known,$given)){
    http_response_code(419);$error='แบบฟอร์มหมดอายุ กรุณารีเฟรชหน้านี้แล้วเข้าสู่ระบบใหม่';
  }else{
    $username=(string)($_POST['username']??'');$password=(string)($_POST['password']??'');
    try{
      $cfg=hub_config();
      $secret=(string)($cfg['api_key']??$cfg['admin_password_hash']??'');
      $result=pan_login_throttled_attempt(
        (string)($_SERVER['REMOTE_ADDR']??''),$username,$secret,HUB_STORAGE.'/auth-throttle',
        static function () use ($cfg,$username,$password): bool {
          $userOk=isset($cfg['admin_user'])&&hash_equals((string)$cfg['admin_user'],trim($username));
          $passOk=isset($cfg['admin_password_hash'])&&password_verify($password,(string)$cfg['admin_password_hash']);
          return $userOk&&$passOk;
        }
      );
      if($result['ok'] && hub_login_attempt($username,$password)){header('Location: '.$next);exit;}
      if($result['retry_after']>0){
        http_response_code(429);header('Retry-After: '.(int)$result['retry_after']);
        $error='มีการเข้าสู่ระบบไม่สำเร็จหลายครั้ง กรุณาลองใหม่ภายหลัง';
      }else{
        usleep(250000);$error='ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
      }
    }catch(Throwable $e){
      // Fail closed if the persistent limiter cannot be locked or written.
      http_response_code(503);$error='ระบบเข้าสู่ระบบไม่พร้อมชั่วคราว กรุณาตรวจสิทธิ์เขียนโฟลเดอร์ storage';
    }
  }
}
function lh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · PAN — น้องแพน</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f7fb;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}.card{width:min(420px,calc(100% - 28px));background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 12px 40px rgba(0,0,0,.05)}h1{margin:0 0 6px;font-size:24px}.muted{color:#6b7280;margin-bottom:18px}label{font-weight:700;display:block;margin:10px 0 5px}input{width:100%;padding:11px;border:1px solid #d1d5db;border-radius:9px}button{width:100%;margin-top:16px;border:0;background:#ee4d2d;color:#fff;padding:12px;border-radius:10px;font-weight:800}.err{background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:9px;color:#991b1b;margin-bottom:10px}</style></head><body><form class="card" method="post"><h1>PAN — น้องแพน</h1><div class="muted">Marketplace & Commerce Assistant · by itoom.work<br>Admin Login · v2.5.2</div><?php if($error):?><div class="err"><?=lh($error)?></div><?php endif;?><?=hub_csrf_field()?> <input type="hidden" name="next" value="<?=lh($next)?>"><label>Username</label><input name="username" autocomplete="username" autofocus><label>Password</label><input type="password" name="password" autocomplete="current-password"><button>เข้าสู่ระบบ</button></form></body></html>
