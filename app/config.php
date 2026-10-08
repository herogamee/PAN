<?php

define('HUB_ROOT', dirname(__DIR__));
define('HUB_STORAGE', HUB_ROOT . DIRECTORY_SEPARATOR . 'storage');
define('HUB_CONFIG_FILE', HUB_STORAGE . DIRECTORY_SEPARATOR . 'config.php');
define('HUB_NAME', 'PAN — น้องแพน');
define('HUB_PRODUCT_NAME', 'PAN');
define('HUB_CHARACTER_NAME', 'น้องแพน');
define('HUB_PRODUCT_ROLE', 'Marketplace & Commerce Assistant');
define('HUB_BRAND_OWNER', 'itoom.work');
define('HUB_VERSION', '2.5.2');
define('HUB_SQLITE_FILENAME', 'pan.sqlite');
define('HUB_LEGACY_SQLITE_FILENAME', 'itoom_commerce_hub.sqlite');
define('HUB_OLDER_LEGACY_SQLITE_FILENAME', 'purchase_hub.sqlite');
define('HUB_API_HEADER', 'X-PAN-Key');

function hub_storage_ready(): bool {
    if (!is_dir(HUB_STORAGE) && !@mkdir(HUB_STORAGE, 0750, true) && !is_dir(HUB_STORAGE)) return false;
    return is_writable(HUB_STORAGE);
}

function hub_is_installed(): bool {
    return is_file(HUB_CONFIG_FILE);
}

function hub_config(): array {
    if (!hub_is_installed()) return [];
    $cfg = require HUB_CONFIG_FILE;
    return is_array($cfg) ? $cfg : [];
}

function hub_save_config(array $cfg): void {
    if (!hub_storage_ready()) throw new RuntimeException('storage/ ต้องเขียนได้โดย Web Server');
    $tmp = HUB_CONFIG_FILE . '.tmp';
    $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) throw new RuntimeException('เขียนไฟล์ config ไม่สำเร็จ');
    @chmod($tmp, 0640);
    if (!@rename($tmp, HUB_CONFIG_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('บันทึก config ไม่สำเร็จ');
    }
    @chmod(HUB_CONFIG_FILE, 0640);
}

function hub_config_update(array $changes): array {
    $cfg = hub_config();
    foreach ($changes as $k => $v) $cfg[$k] = $v;
    hub_save_config($cfg);
    return $cfg;
}

function hub_app_url_guess(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $base = preg_replace('~/install/?$~', '', rtrim(dirname($script), '/.'));
    return $scheme . '://' . $host . ($base ? '/' . ltrim($base, '/') : '');
}

function hub_db_config(array $cfg = null): array {
    $cfg ??= hub_config();
    $db = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
    // Environment variables can override secrets/settings for advanced deployments.
    // PAN_DB_* is the current namespace; HUB_DB_* remains supported for upgrades from v2.3 and older.
    $maps = [
        [
            'HUB_DB_DRIVER' => 'driver', 'HUB_DB_HOST' => 'host', 'HUB_DB_PORT' => 'port',
            'HUB_DB_NAME' => 'database', 'HUB_DB_USER' => 'username', 'HUB_DB_PASS' => 'password',
            'HUB_DB_CHARSET' => 'charset', 'HUB_SQLITE_PATH' => 'path'
        ],
        [
            'PAN_DB_DRIVER' => 'driver', 'PAN_DB_HOST' => 'host', 'PAN_DB_PORT' => 'port',
            'PAN_DB_NAME' => 'database', 'PAN_DB_USER' => 'username', 'PAN_DB_PASS' => 'password',
            'PAN_DB_CHARSET' => 'charset', 'PAN_SQLITE_PATH' => 'path'
        ],
    ];
    foreach ($maps as $map) foreach ($map as $env => $key) {
        $v = getenv($env);
        if ($v !== false && $v !== '') $db[$key] = $v;
    }
    return $db;
}
