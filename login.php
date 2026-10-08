<?php
require_once __DIR__.'/app/config.php';require_once __DIR__.'/app/auth.php';
if(!hub_is_installed()){header('Location: ./install/');exit;}
if(hub_logged_in()){header('Location: ./');exit;}
$error='';$next=(string)($_GET['next']??$_POST['next']??'./');if(!preg_match('~^/(?!/)~',$next))$next='./';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
  if(hub_login_attempt((string)($_POST['username']??''),(string)($_POST['password']??''))){header('Location: '.$next);exit;}
  usleep(250000);$error='ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
}
function lh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?><!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · PAN — น้องแพน</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f7fb;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif}.card{width:min(420px,calc(100% - 28px));background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 12px 40px rgba(0,0,0,.05)}h1{margin:0 0 6px;font-size:24px}.muted{color:#6b7280;margin-bottom:18px}label{font-weight:700;display:block;margin:10px 0 5px}input{width:100%;padding:11px;border:1px solid #d1d5db;border-radius:9px}button{width:100%;margin-top:16px;border:0;background:#ee4d2d;color:#fff;padding:12px;border-radius:10px;font-weight:800}.err{background:#fef2f2;border:1px solid #fecaca;padding:10px;border-radius:9px;color:#991b1b;margin-bottom:10px}</style></head><body><form class="card" method="post"><h1>PAN — น้องแพน</h1><div class="muted">Marketplace & Commerce Assistant · by itoom.work<br>Admin Login · v2.5.0</div><?php if($error):?><div class="err"><?=lh($error)?></div><?php endif;?><input type="hidden" name="next" value="<?=lh($next)?>"><label>Username</label><input name="username" autocomplete="username" autofocus><label>Password</label><input type="password" name="password" autocomplete="current-password"><button>เข้าสู่ระบบ</button></form></body></html>
