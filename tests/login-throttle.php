<?php
/** Pure filesystem/login regression; does not need PDO or an installed PAN. */
require_once __DIR__.'/../app/login_throttle.php';

function check(bool $condition, string $description): void {
    if (!$condition) throw new RuntimeException('FAIL: '.$description);
    echo 'PASS: '.$description."\n";
}
function attempt(string $ip, string $user, bool $valid, string $dir, int $now, ?int &$calls = null): array {
    return pan_login_throttled_attempt($ip,$user,str_repeat('x',32),$dir,
        static function () use ($valid, &$calls): bool { $calls++; return $valid; },$now);
}
function clean(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $item) {
        if (!$item->isDot() && $item->isFile()) unlink($item->getPathname());
    }
    rmdir($dir);
}
$root=sys_get_temp_dir().'/pan-auth-test-'.bin2hex(random_bytes(6));
$now=2_000_000_000;
try {
    $calls=0;
    for($i=0;$i<4;$i++) check(attempt('192.0.2.1','admin',false,$root,$now+$i,$calls)['retry_after']===0,'login failure '.$i.' accepted and counted');
    $fifth=attempt('192.0.2.1','admin',false,$root,$now+4,$calls);
    check($fifth['retry_after']===900,'fifth failure locks username+IP for 15 min');
    $blocked=attempt('192.0.2.1','admin',true,$root,$now+10,$calls);
    check(!$blocked['ok']&&$blocked['retry_after']===894&&$calls===5,'valid credentials cannot bypass lock; validation callback is not called');
    check(attempt('192.0.2.2','admin',true,$root,$now+10,$calls)['ok'],'different IP not locked');
    check(attempt('192.0.2.1','admin',true,$root,$now+904,$calls)['ok'],'locked username can log in after cooldown');
    check(attempt('192.0.2.1','admin',false,$root,$now+905,$calls)['retry_after']===0,'successful login clears previous failures');

    // Aggregate per-IP limit prevents username spraying bypass.
    for($i=0;$i<19;$i++){
        $r=attempt('2001:db8::1','random-'.$i,false,$root,$now+$i,$calls);
        check($r['retry_after']===0,'IP spray attempt '.($i+1).' counted');
    }
    $r=attempt('2001:db8::1','random-19',false,$root,$now+20,$calls);
    check($r['retry_after']===900,'twentieth distinct username failure locks shared IP');
    $old=$calls;
    $r=attempt('2001:db8::1','another',true,$root,$now+40,$calls);
    check(!$r['ok']&&$calls===$old,'IP lock rejects before user-specific file/credential check');

    foreach (glob($root.'/*.json') as $path) {
        $raw=file_get_contents($path);
        check(strpos($raw,'admin')===false&&strpos($raw,'192.0.2')===false&&strpos($raw,'random')===false,
            'throttle disk file contains timestamps only');
    }
    // Corrupted counters must not silently fail-open.
    $corruptDir=$root.'/corrupt';
    @mkdir($corruptDir);
    $ipDigest=hash_hmac('sha256','ip:198.51.100.1',str_repeat('x',32));
    file_put_contents($corruptDir.'/'.$ipDigest.'.json','{broken');
    $threw=false;
    try{attempt('198.51.100.1','admin',true,$corruptDir,$now,$calls);}catch(RuntimeException $e){$threw=true;}
    check($threw,'corrupted throttle state fails closed');
    clean($corruptDir);
    file_put_contents($root.'/not-a-directory.json','regular file');
    $threw=false;
    try{attempt('192.0.2.1','admin',true,$root.'/not-a-directory.json',$now,$calls);}catch(RuntimeException $e){$threw=true;}
    check($threw,'non-writable storage is rejected');
    echo "LOGIN THROTTLE TESTS PASS\n";
} finally { clean($root); }
