<?php
/**
 * PAN admin login throttle. Uses file locks to share failure counters across PHP
 * sessions and FPM workers; independent of PAN's database and Shopee session.
 * Files contain only HMAC digests and timestamps, never IPs or passwords.
 */

function pan_login_throttle_bucket(string $directory, string $secret, string $identity): mixed {
    $path = $directory . DIRECTORY_SEPARATOR . hash_hmac('sha256', $identity, $secret) . '.json';
    $handle = @fopen($path, 'c+b');
    if ($handle === false) throw new RuntimeException('Admin login temporarily unavailable');
    if (!@flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException('Admin login temporarily unavailable');
    }
    @chmod($path, 0600);
    return $handle;
}

function pan_login_throttle_read($handle, int $now): array {
    rewind($handle);
    $raw = stream_get_contents($handle, 4096);
    if ($raw === false) throw new RuntimeException('Admin login temporarily unavailable');
    $state = json_decode($raw, true);
    if (!is_array($state) && trim($raw) !== '') throw new RuntimeException('Admin login temporarily unavailable');
    $attempts = [];
    foreach ((array)($state['failures'] ?? []) as $t) {
        if (is_int($t) && $t > $now - 900 && $t <= $now) $attempts[] = $t;
    }
    $until = (int)($state['until'] ?? 0);
    return ['failures' => array_slice($attempts, -20), 'until' => $until > $now ? $until : 0];
}

function pan_login_throttle_write($handle, array $state): void {
    $json = json_encode($state, JSON_THROW_ON_ERROR);
    rewind($handle);
    if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
        throw new RuntimeException('Admin login temporarily unavailable');
    }
}

/**
 * Atomically check both IP and IP+user counters while validating credentials.
 * Returns ['ok'=>bool,'retry_after'=>seconds]. Caller handles generic UI errors.
 * 
 * Do not use X-Forwarded-For / CF-Connecting-IP unless the proxy has been
 * explicitly authenticated by the deployment. REMOTE_ADDR is the safe default.
 */
function pan_login_throttled_attempt(
    string $ip, string $username, string $secret, string $directory,
    callable $validate, ?int $now = null
): array {
    if (strlen($secret) < 16) throw new RuntimeException('Admin login temporarily unavailable');
    $now ??= time();
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Admin login temporarily unavailable');
    }
    if (!is_writable($directory)) throw new RuntimeException('Admin login temporarily unavailable');
    // Invalid/missing IPs share a single bucket rather than bypassing checks.
    $ip = filter_var($ip, FILTER_VALIDATE_IP) ?: 'unknown';
    $user = substr(trim($username), 0, 256);
    $handles = [];
    try {
        // Always lock IP first. An exhausted IP cannot spray arbitrary usernames
        // into unlimited new account-bucket files.
        $ipHandle = pan_login_throttle_bucket($directory, $secret, 'ip:' . $ip);
        $handles[] = $ipHandle;
        $ipState = pan_login_throttle_read($ipHandle, $now);
        if ($ipState['until'] > $now) return ['ok' => false, 'retry_after' => $ipState['until'] - $now];

        $userHandle = pan_login_throttle_bucket($directory, $secret, 'ip-user:' . $ip . ':' . $user);
        $handles[] = $userHandle;
        $userState = pan_login_throttle_read($userHandle, $now);
        if ($userState['until'] > $now) return ['ok' => false, 'retry_after' => $userState['until'] - $now];

        $ok = (bool)$validate();
        if ($ok) {
            pan_login_throttle_write($userHandle, ['failures' => [], 'until' => 0]);
            pan_login_throttle_write($ipHandle, ['failures' => [], 'until' => 0]);
            return ['ok' => true, 'retry_after' => 0];
        }

        $ipState['failures'][] = $now;
        $userState['failures'][] = $now;
        // Five errors per username+IP, or twenty from one IP, within 15 min.
        // Lock for 15 min; checking while locked does not prolong the lock.
        $ipState['until'] = count($ipState['failures']) >= 20 ? $now + 900 : 0;
        $userState['until'] = count($userState['failures']) >= 5 ? $now + 900 : 0;
        pan_login_throttle_write($ipHandle, $ipState);
        pan_login_throttle_write($userHandle, $userState);
        $wait = max($ipState['until'], $userState['until']) - $now;
        return ['ok' => false, 'retry_after' => max(0, $wait)];
    } finally {
        foreach (array_reverse($handles) as $handle) {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
