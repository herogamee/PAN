<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

function hub_require_installed(): void {
    if (!hub_is_installed()) {
        header('Location: ./install/');
        exit;
    }
}

function hub_maintenance_mode(): bool {
    $cfg = hub_config();
    return !empty($cfg['maintenance']);
}
