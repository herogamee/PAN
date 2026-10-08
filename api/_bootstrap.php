<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/auth.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-PAN-Key, X-ITOOM-Commerce-Key, X-Purchase-Hub-Key');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

if (!hub_is_installed()) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'PAN ยังไม่ได้ติดตั้ง กรุณาเปิดหน้าเว็บเพื่อทำ First Run'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = hub_config();
if (!empty($cfg['maintenance'])) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'PAN อยู่ในโหมด Maintenance / Database Migration กรุณาลองใหม่หลังการย้ายฐานข้อมูลเสร็จ'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Browser admin session is accepted for export links; Connector should provide X-PAN-Key (legacy X-ITOOM-Commerce-Key and X-Purchase-Hub-Key are also accepted).
if (!hub_api_key_valid() && !hub_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Unauthorized: X-PAN-Key ไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE);
    exit;
}
