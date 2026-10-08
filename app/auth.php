<?php
require_once __DIR__ . '/config.php';

function hub_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('itoom_commerce_hub');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $secure,
            'httponly' => true, 'samesite' => 'Lax'
        ]);
        session_start();
    }
}

function hub_logged_in(): bool {
    hub_session_start();
    return !empty($_SESSION['hub_admin']);
}

function hub_require_login(): void {
    if (!hub_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? './';
        header('Location: ./login.php?next=' . rawurlencode($next));
        exit;
    }
}

function hub_login_attempt(string $username, string $password): bool {
    $cfg = hub_config();
    $okUser = isset($cfg['admin_user']) && hash_equals((string)$cfg['admin_user'], trim($username));
    $okPass = isset($cfg['admin_password_hash']) && password_verify($password, (string)$cfg['admin_password_hash']);
    if ($okUser && $okPass) {
        hub_session_start();
        session_regenerate_id(true);
        $_SESSION['hub_admin'] = true;
        $_SESSION['hub_admin_user'] = (string)$cfg['admin_user'];
        return true;
    }
    return false;
}

function hub_logout(): void {
    hub_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function hub_csrf_token(): string {
    hub_session_start();
    if (empty($_SESSION['hub_csrf'])) $_SESSION['hub_csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['hub_csrf'];
}

function hub_csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(hub_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function hub_verify_csrf(): void {
    hub_session_start();
    $given = (string)($_POST['csrf_token'] ?? '');
    $known = (string)($_SESSION['hub_csrf'] ?? '');
    if ($known === '' || $given === '' || !hash_equals($known, $given)) {
        http_response_code(419);
        exit('CSRF token ไม่ถูกต้อง กรุณากลับหน้าเดิมแล้วลองใหม่');
    }
}

function hub_api_key_valid(?string $provided = null): bool {
    $cfg = hub_config();
    $expected = (string)($cfg['api_key'] ?? '');
    if ($expected === '') return false;
    if ($provided === null) {
        $provided = (string)($_SERVER['HTTP_X_PAN_KEY'] ?? ($_SERVER['HTTP_X_ITOOM_COMMERCE_KEY'] ?? ($_SERVER['HTTP_X_PURCHASE_HUB_KEY'] ?? '')));
    }
    return $provided !== '' && hash_equals($expected, $provided);
}
