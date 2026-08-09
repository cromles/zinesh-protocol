<?php
declare(strict_types=1);

function zinesh_config_heal_tl_havale(array &$cfg, array $base): void
{
    $baseH = is_array($base['tl_havale'] ?? null) ? $base['tl_havale'] : [];
    $mergedH = is_array($cfg['tl_havale'] ?? null) ? $cfg['tl_havale'] : [];
    $norm = static function (mixed $iban): string {
        return strtoupper(preg_replace('/\s+/', '', (string)$iban) ?? '');
    };
    $baseIban = $norm($baseH['iban'] ?? '');
    $mergedIban = $norm($mergedH['iban'] ?? '');
    if ($baseIban === '') {
        return;
    }
    if ($mergedIban === '') {
        $cfg['tl_havale'] = array_replace($baseH, $mergedH);
        $cfg['tl_havale']['iban'] = (string)($baseH['iban'] ?? '');
        $mergedIban = $baseIban;
    }
    // config.local.php may disable havale while config.php still carries the IBAN.
    if (($mergedH['enabled'] ?? null) === false && strlen($baseIban) >= 15) {
        $cfg['tl_havale']['enabled'] = $baseH['enabled'] ?? true;
    }
}

function zinesh_config_heal_session_idle(array &$cfg, array $base): void
{
    // Base config disables idle logout; stale config.local must not re-enable short test timeouts.
    if ((int)($base['session_idle_seconds'] ?? -1) === 0) {
        $cfg['session_idle_seconds'] = 0;
    }
}

function zinesh_config_heal_session_bind(array &$cfg): void
{
    // IP/UA fingerprint oturumu düşürüyordu — kapalı tut.
    $cfg['session_bind_fingerprint'] = false;
    // Token rotasyonu mobil oturumu bozuyordu — fiilen kapalı.
    $cfg['session_rotate_seconds'] = 60 * 60 * 24 * 365;
    $cfg['session_idle_seconds'] = 0;
    // Mobil için uzun mutlak süre
    if ((int)($cfg['session_max_seconds'] ?? 0) < 60 * 60 * 24 * 30) {
        $cfg['session_max_seconds'] = 60 * 60 * 24 * 30;
    }
}

function zinesh_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $base = require __DIR__ . '/config.php';
    $cfg = $base;
    $local = __DIR__ . '/config.local.php';
    if (is_readable($local)) {
        $localCfg = require $local;
        if (is_array($localCfg)) {
            $cfg = array_replace_recursive($cfg, $localCfg);
            zinesh_config_heal_tl_havale($cfg, $base);
            zinesh_config_heal_session_idle($cfg, $base);
        }
    }
    zinesh_config_heal_session_bind($cfg);
    return $cfg;
}

function zinesh_resolve_data_dir(): string {
    $simDir = getenv('ZINESH_SIM_DATA_DIR');
    if (is_string($simDir) && $simDir !== '') {
        if (!is_dir($simDir)) {
            mkdir($simDir, 0750, true);
        }
        return rtrim($simDir, '/');
    }
    $configured = rtrim((string)(zinesh_config()['data_dir'] ?? ''), '/');
    $candidates = array_values(array_unique(array_filter([
        $configured,
        __DIR__ . '/data',
    ])));

    $readableOnly = null;
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $probe = $dir . '/users.json';
        $usersOk = file_exists($probe) && is_readable($probe);
        if ($usersOk && is_writable($dir)) {
            return $dir;
        }
        if ($usersOk && $readableOnly === null) {
            $readableOnly = $dir;
        }
        if (!$usersOk && is_writable($dir) && $readableOnly === null) {
            $readableOnly = $dir;
        }
    }
    return $readableOnly ?? ($configured !== '' ? $configured : __DIR__ . '/data');
}

function zinesh_data_path(string $file): string {
    $dir = zinesh_resolve_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir . '/' . $file;
}

function zinesh_json_read(string $file): array {
    $path = zinesh_data_path($file);
    if (!file_exists($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function zinesh_json_write(string $file, array $data): void {
    file_put_contents(
        zinesh_data_path($file),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * Atomik okuma-yazma (eşzamanlı isteklerde veri kaybını önler).
 * Kilit alınamazsa kısa retry; hâlâ başarısızsa 503.
 * @return mixed Mutator dönüş değeri
 */
function zinesh_json_atomic(string $file, callable $mutator) {
    $cfg = zinesh_config()['file_lock'] ?? [];
    $retries = max(1, (int)($cfg['retries'] ?? 6));
    $retryMs = max(1, (int)($cfg['retry_ms'] ?? 30));
    $last = null;

    for ($attempt = 0; $attempt < $retries; $attempt++) {
        try {
            return zinesh_json_atomic_once($file, $mutator);
        } catch (RuntimeException $e) {
            $last = $e;
            if ($attempt < $retries - 1) {
                usleep($retryMs * 1000);
            }
        }
    }

    error_log('zinesh_json_atomic_failed: ' . $file . ' — ' . ($last?->getMessage() ?? 'unknown'));
    zinesh_json_response([
        'ok' => false,
        'message' => 'Geçici yoğunluk. Lütfen birkaç saniye sonra tekrar deneyin.',
    ], 503);
}

/** @return mixed */
function zinesh_json_atomic_once(string $file, callable $mutator) {
    $path = zinesh_data_path($file);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $fp = fopen($path, 'c+');
    if ($fp === false) {
        throw new RuntimeException('Veri dosyası açılamadı: ' . $file);
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Kilit alınamadı: ' . $file);
        }
        $raw = stream_get_contents($fp);
        $data = json_decode($raw ?: '[]', true);
        if (!is_array($data)) {
            $data = [];
        }
        $result = $mutator($data);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        return $result;
    } finally {
        fclose($fp);
    }
}

function zinesh_json_response(array $payload, int $code = 200): void {
    http_response_code($code);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Kamuya açık, kısa süre cache'lenebilir JSON yanıtları (Cloudflare edge). */
function zinesh_json_cached_response(array $payload, int $maxAgeSec = 120, int $code = 200): void {
    http_response_code($code);
    $maxAgeSec = max(0, $maxAgeSec);
    $swr = min(600, max(60, $maxAgeSec * 2));
    header('Cache-Control: public, max-age=' . $maxAgeSec . ', stale-while-revalidate=' . $swr);
    header('CDN-Cache-Control: public, max-age=' . $maxAgeSec);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function zinesh_cors_allowed_origins(): array {
    $cfg = zinesh_config()['cors_allowed_origins'] ?? null;
    if (is_array($cfg) && $cfg !== []) {
        return array_values(array_map(static fn($v) => rtrim((string)$v, '/'), $cfg));
    }
    return [
        'https://www.zinesh.com',
        'https://zinesh.com',
        'https://app.zinesh.com',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ];
}

function zinesh_cors(): void {
    header('Content-Type: application/json; charset=utf-8');

    $origin = rtrim((string)($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    $allowed = zinesh_cors_allowed_origins();
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Session-Token, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function zinesh_input(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}
