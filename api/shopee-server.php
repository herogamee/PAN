<?php
require_once __DIR__.'/../app/bootstrap.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
if (!hub_is_installed() || !hub_logged_in()) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'กรุณาเข้าสู่ระบบ PAN']); exit; }
if (hub_maintenance_mode()) { http_response_code(503); echo json_encode(['ok'=>false,'error'=>'Maintenance']); exit; }
// This privileged browser-control endpoint deliberately does not accept API keys/CORS.
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST only']); exit; }
$given=(string)($_POST['csrf_token']??'');
if ($given==='' || !hash_equals(hub_csrf_token(),$given)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF ไม่ถูกต้อง กรุณาโหลดหน้าใหม่'],JSON_UNESCAPED_UNICODE); exit; }
$action = (string)($_POST['action'] ?? '');
$allowed = ['status','screen','open','close','account','check-access','sync','resume','repair','pause','purchase','login','click','drag','text','key','scroll'];
if (!in_array($action,$allowed,true)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid action']); exit; }
$cfg = hub_config();
$token = (string)(getenv('PAN_CONNECTOR_TOKEN') ?: ($cfg['shopee_server_token'] ?? ''));
$port = (int)(getenv('PAN_CONNECTOR_PORT') ?: ($cfg['shopee_server_port'] ?? 3210));
session_write_close();
try {
    if (strlen($token)<32) throw new RuntimeException('ยังไม่ได้ตั้งค่าบริการ Shopee Server: รัน setup.php ตามคู่มือติดตั้ง');
    if ($port<1024 || $port>65535) throw new RuntimeException('Invalid connector port');
    if (preg_match('/[\r\n]/',$token)) throw new RuntimeException('Invalid connector token');
    $read = in_array($action,['status','screen'],true);
    $payload='';
    if (!$read) {
        $data = ['action'=>$action];
        foreach (['profile','text','key'] as $field) if (isset($_POST[$field])) $data[$field]=(string)$_POST[$field];
        foreach (['x','y','endX','endY'] as $field) if (isset($_POST[$field])) $data[$field]=is_numeric($_POST[$field])?(float)$_POST[$field]:null;
        $payload=json_encode($data);
    }
    // A loopback HTTP/1.0 socket needs no optional curl extension and never follows redirects.
    $socket=@stream_socket_client('tcp://127.0.0.1:'.$port,$errno,$errstr,3);
    if (!$socket) throw new RuntimeException('ติดต่อบริการ Shopee Server ไม่ได้ กรุณาตรวจว่าบริการกำลังทำงาน');
    try {
        stream_set_timeout($socket,55);
        $wire=($read?'GET':'POST').' /'.($read?$action:'command')." HTTP/1.0\r\nHost: 127.0.0.1\r\nAuthorization: Bearer ".$token."\r\nContent-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\nConnection: close\r\n\r\n".$payload;
        while ($wire!=='') { $sent=fwrite($socket,$wire); if (!$sent) throw new RuntimeException('Connector write failed'); $wire=substr($wire,$sent); }
        $response='';
        while (!feof($socket)) {
            $chunk=fread($socket,65536);
            if ($chunk===false || stream_get_meta_data($socket)['timed_out']) throw new RuntimeException('Connector timeout');
            $response.=$chunk;
            if (strlen($response)>8*1024*1024) throw new RuntimeException('Connector response too large');
        }
    } finally { fclose($socket); }
    $parts=explode("\r\n\r\n",$response,2);
    if (count($parts)!==2 || !preg_match('~^HTTP/1\.[01] (\d{3})~',$parts[0],$match)) throw new RuntimeException('Invalid connector response');
    $status=(int)$match[1]; $body=$parts[1]; $type='';
    if (preg_match('/^Content-Type:\s*([^\r\n]+)/mi',$parts[0],$match)) $type=$match[1];
    http_response_code($status ?: 502);
    if ($action==='screen' && $status===200 && strpos($type,'image/jpeg')===0) header('Content-Type: image/jpeg');
    echo $body;
} catch (Throwable $e) { http_response_code(503); echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE); }
