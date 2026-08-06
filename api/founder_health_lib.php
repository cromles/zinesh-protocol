<?php
declare(strict_types=1);

/** @return list<string> */
function zinesh_founder_health_data_files(): array {
    return [
        'users.json',
        'sessions.json',
        'withdrawals.json',
        'escrow_rooms.json',
        'havale_pending.json',
    ];
}

/**
 * auth.php ile uyumlu oturum girdisi — Bearer, X-Session-Token, query, cookie.
 *
 * @return array{sessionToken:string}
 */
function zinesh_founder_health_auth_input(): array {
    $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));

    if ($token === '') {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
    }

    if ($token === '' && isset($_GET['sessionToken'])) {
        $token = trim((string)$_GET['sessionToken']);
    }

    if ($token === '' && !empty($_COOKIE['zinesh_session'])) {
        $token = trim((string)$_COOKIE['zinesh_session']);
    }

    return ['sessionToken' => $token];
}

/** Oturum doğrulama — auth.php ile aynı token kaynakları; yazma hatasında salt okunur yedek. */
function zinesh_founder_health_resolve_user(array $input): ?array {
    try {
        $user = zinesh_resolve_user_from_auth_input($input);
        if ($user) {
            return $user;
        }
    } catch (Throwable) {
        /* sessions.json atomic yazılamazsa salt okunur doğrulamaya düş */
    }

    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token === '') {
        return null;
    }

    $uid = zinesh_founder_health_session_uid_readonly($token);
    if ($uid === null || $uid === '') {
        return null;
    }

    return zinesh_find_user_by_uid($uid);
}

function zinesh_founder_health_session_uid_readonly(string $token): ?string {
    if ($token === '') {
        return null;
    }

    $sessions = zinesh_json_read('sessions.json');
    $session = $sessions[$token] ?? null;
    if (!is_array($session)) {
        return null;
    }

    $now = time();
    if (($session['expires'] ?? 0) < $now) {
        return null;
    }

    $last = zinesh_session_last_activity($session);
    $idle = zinesh_session_idle_seconds();
    if ($idle > 0 && $last > 0 && ($now - $last) > $idle) {
        return null;
    }

    if (zinesh_session_bind_fingerprint()) {
        $storedFp = (string)($session['fp'] ?? '');
        $expectedFp = zinesh_client_fingerprint();
        if ($storedFp !== '' && $expectedFp !== '' && !hash_equals($storedFp, $expectedFp)) {
            // Soft bind — salt okunur yolda oturumu düşürme.
            return $uid !== '' ? $uid : null;
        }
    }

    $uid = (string)($session['uid'] ?? '');
    return $uid !== '' ? $uid : null;
}

/** @return array{usedPct:float,usedMb:float,totalMb:float}|null */
function zinesh_founder_memory_from_free_command(): ?array {
    if (!function_exists('shell_exec')) {
        return null;
    }
    $raw = shell_exec('free -b 2>/dev/null');
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    foreach (preg_split('/\R/', trim($raw)) ?: [] as $line) {
        if (!preg_match('/^Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $m)) {
            continue;
        }
        $total = (int)$m[1];
        if ($total <= 0) {
            return null;
        }
        $available = (int)$m[6];
        $used = max(0, $total - $available);
        return [
            'usedPct' => round(($used / $total) * 100, 1),
            'usedMb' => round($used / (1024 ** 2)),
            'totalMb' => round($total / (1024 ** 2)),
        ];
    }
    return null;
}

function zinesh_founder_uptime_from_command(): ?int {
    if (!function_exists('shell_exec')) {
        return null;
    }
    $raw = trim((string)shell_exec('cat /proc/uptime 2>/dev/null'));
    if ($raw === '') {
        return null;
    }
    $parts = preg_split('/\s+/', $raw);
    $seconds = (float)($parts[0] ?? 0);
    return $seconds > 0 ? (int)round($seconds) : null;
}

function zinesh_founder_file_health_label(string $detail): string {
    return match ($detail) {
        'ok' => 'Sağlıklı',
        'readable only' => 'Yazma izni yok',
        'missing' => 'Dosya bulunamadı',
        'invalid json' => 'Geçersiz JSON',
        'permission denied' => 'Okuma izni yok',
        default => $detail,
    };
}

/** @return array{file:string,status:string,detail:string,label:string} */
function zinesh_founder_file_health(string $filename): array {
    $path = zinesh_data_path($filename);

    if (!file_exists($path)) {
        $detail = 'missing';
        return [
            'file' => $filename,
            'status' => 'red',
            'detail' => $detail,
            'label' => zinesh_founder_file_health_label($detail),
        ];
    }

    if (!is_readable($path)) {
        $detail = 'permission denied';
        return [
            'file' => $filename,
            'status' => 'red',
            'detail' => $detail,
            'label' => zinesh_founder_file_health_label($detail),
        ];
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        $detail = 'permission denied';
        return [
            'file' => $filename,
            'status' => 'red',
            'detail' => $detail,
            'label' => zinesh_founder_file_health_label($detail),
        ];
    }

    $trimmed = trim($raw);
    if ($trimmed === '[]' || $trimmed === '{}') {
        // Boş dizi / nesne sağlıklı kabul edilir.
    } elseif ($trimmed === '') {
        $detail = 'invalid json';
        return [
            'file' => $filename,
            'status' => 'red',
            'detail' => $detail,
            'label' => zinesh_founder_file_health_label($detail),
        ];
    } else {
        json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $detail = 'invalid json';
            return [
                'file' => $filename,
                'status' => 'red',
                'detail' => $detail,
                'label' => zinesh_founder_file_health_label($detail),
            ];
        }
    }

    if (!is_writable($path)) {
        $detail = 'readable only';
        return [
            'file' => $filename,
            'status' => 'yellow',
            'detail' => $detail,
            'label' => zinesh_founder_file_health_label($detail),
        ];
    }

    return [
        'file' => $filename,
        'status' => 'green',
        'detail' => 'ok',
        'label' => zinesh_founder_file_health_label('ok'),
    ];
}

/** @return array<string,mixed> */
function zinesh_founder_backup_health_file(): array {
    $path = zinesh_data_path('backup_health.json');
    if (!is_readable($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function zinesh_founder_format_backup_time(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        return $value;
    }
    try {
        $dt = new DateTimeImmutable($value);
        $dt = $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
        return $dt->format('Y-m-d H:i');
    } catch (Throwable) {
        return $value;
    }
}

/**
 * Yedek durumu — yalnızca backup_health.json (rclone yok).
 *
 * @return array{
 *   lastSuccessLabel:?string,
 *   display:string,
 *   source:string,
 *   unavailable:bool,
 *   gdriveConnected:bool,
 *   localBackupOnly:bool
 * }
 */
function zinesh_founder_backup_status_from_file(): array {
    $source = 'Google Drive backups';
    $health = zinesh_founder_backup_health_file();

    if ($health === []) {
        return [
            'lastSuccessLabel' => null,
            'display' => 'Görüntülenemiyor',
            'source' => $source,
            'unavailable' => true,
            'gdriveConnected' => false,
            'localBackupOnly' => false,
        ];
    }

    $localBackupOnly = !empty($health['localBackupOnly'])
        || (($health['mode'] ?? '') === 'local');
    $gdriveConnected = !empty($health['gdrive'])
        || !empty($health['gdriveConnected']);

    $rawLast = trim((string)($health['lastBackup'] ?? $health['lastSuccess'] ?? ''));
    $lastSuccessLabel = $rawLast !== '' ? zinesh_founder_format_backup_time($rawLast) : null;

    return [
        'lastSuccessLabel' => $lastSuccessLabel,
        'display' => $lastSuccessLabel ?? 'Görüntülenemiyor',
        'source' => (string)($health['source'] ?? ($localBackupOnly ? 'Sunucu yerel yedek' : $source)),
        'unavailable' => $lastSuccessLabel === null,
        'gdriveConnected' => $gdriveConnected,
        'localBackupOnly' => $localBackupOnly,
    ];
}

/** @return array<string,mixed> */
function zinesh_founder_server_stats(): array {
    $unknown = 'Bilinmiyor';

    $uptimeSeconds = null;
    if (is_readable('/proc/uptime')) {
        $parts = preg_split('/\s+/', trim((string)file_get_contents('/proc/uptime')));
        $uptimeSeconds = (int)round((float)($parts[0] ?? 0));
    }
    if ($uptimeSeconds === null) {
        $uptimeSeconds = zinesh_founder_uptime_from_command();
    }

    $dataDir = zinesh_resolve_data_dir();
    $diskFree = @disk_free_space($dataDir);
    $diskTotal = @disk_total_space($dataDir);
    $diskUsedPct = null;
    $diskFreeGb = null;
    if (is_numeric($diskFree) && is_numeric($diskTotal) && $diskTotal > 0) {
        $diskUsedPct = round((1 - ($diskFree / $diskTotal)) * 100, 1);
        $diskFreeGb = round($diskFree / (1024 ** 3), 1);
    }

    $memoryUsedPct = null;
    $memoryUsedMb = null;
    $memoryTotalMb = null;
    if (is_readable('/proc/meminfo')) {
        $meminfo = [];
        foreach (file('/proc/meminfo', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $meminfo[$m[1]] = (int)$m[2];
            }
        }
        $total = (int)($meminfo['MemTotal'] ?? 0);
        $available = (int)($meminfo['MemAvailable'] ?? ($meminfo['MemFree'] ?? 0));
        if ($total > 0) {
            $used = max(0, $total - $available);
            $memoryTotalMb = round($total / 1024);
            $memoryUsedMb = round($used / 1024);
            $memoryUsedPct = round(($used / $total) * 100, 1);
        }
    }
    if ($memoryUsedPct === null) {
        $fromFree = zinesh_founder_memory_from_free_command();
        if ($fromFree !== null) {
            $memoryUsedPct = $fromFree['usedPct'];
            $memoryUsedMb = $fromFree['usedMb'];
            $memoryTotalMb = $fromFree['totalMb'];
        }
    }

    $diskDisplay = $diskUsedPct !== null ? sprintf('%%%s dolu', $diskUsedPct) : $unknown;
    $diskSubDisplay = $diskFreeGb !== null ? sprintf('%s GB boş', $diskFreeGb) : $unknown;
    $memoryDisplay = $memoryUsedPct !== null ? sprintf('%%%s kullanım', $memoryUsedPct) : $unknown;
    $memorySubDisplay = ($memoryUsedMb !== null && $memoryTotalMb !== null)
        ? sprintf('%s / %s MB', $memoryUsedMb, $memoryTotalMb)
        : $unknown;

    return [
        'status' => 'green',
        'label' => 'Çalışıyor',
        'uptimeSeconds' => $uptimeSeconds,
        'uptimeLabel' => zinesh_founder_format_uptime($uptimeSeconds) ?? $unknown,
        'diskUsedPct' => $diskUsedPct,
        'diskFreeGb' => $diskFreeGb,
        'diskDisplay' => $diskDisplay,
        'diskSubDisplay' => $diskSubDisplay,
        'memoryUsedPct' => $memoryUsedPct,
        'memoryUsedMb' => $memoryUsedMb,
        'memoryTotalMb' => $memoryTotalMb,
        'memoryDisplay' => $memoryDisplay,
        'memorySubDisplay' => $memorySubDisplay,
    ];
}

function zinesh_founder_format_uptime(?int $seconds): ?string {
    if ($seconds === null || $seconds < 0) {
        return null;
    }
    $days = intdiv($seconds, 86400);
    $hours = intdiv($seconds % 86400, 3600);
    $mins = intdiv($seconds % 3600, 60);
    if ($days > 0) {
        return sprintf('%d gün %d sa', $days, $hours);
    }
    if ($hours > 0) {
        return sprintf('%d sa %d dk', $hours, $mins);
    }
    return sprintf('%d dk', max(1, $mins));
}

function zinesh_founder_format_checked_at(?string $iso = null): string {
    try {
        $dt = new DateTimeImmutable($iso ?? 'now');
        $dt = $dt->setTimezone(new DateTimeZone('Europe/Istanbul'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return date('Y-m-d H:i:s');
    }
}

/**
 * @param list<array{file:string,status:string,detail:string,label:string}> $files
 * @param array<string,mixed> $backup
 * @param array<string,mixed> $disasterRecovery
 * @param array<string,mixed> $server
 * @return array<string,mixed>
 */
function zinesh_founder_overall_status(
    array $files,
    array $backup,
    array $disasterRecovery,
    array $server,
    bool $authOk,
    string $checkedAt
): array {
    /** @var list<array{id:string,name:string,status:string,label:string}> $checks */
    $checks = [];

    foreach ($files as $file) {
        $checks[] = [
            'id' => (string)$file['file'],
            'name' => (string)$file['file'],
            'status' => (string)$file['status'],
            'label' => (string)($file['label'] ?? zinesh_founder_file_health_label((string)$file['detail'])),
        ];
    }

    $gdriveConnected = !empty($disasterRecovery['connected']);
    $localBackupOnly = !empty($disasterRecovery['localOnly']);
    $checks[] = [
        'id' => 'google_drive',
        'name' => 'Google Drive',
        'status' => $gdriveConnected ? 'green' : ($localBackupOnly ? 'yellow' : 'red'),
        'label' => $gdriveConnected ? 'Bağlı' : ($localBackupOnly ? 'İsteğe bağlı' : 'Bağlı değil'),
    ];

    $backupOk = empty($backup['unavailable']) && !empty($backup['lastSuccessLabel']);
    $backupWarn = !$backupOk && $gdriveConnected;
    $checks[] = [
        'id' => 'backup',
        'name' => 'Yedek',
        'status' => $backupOk ? 'green' : ($backupWarn ? 'yellow' : 'red'),
        'label' => $backupOk
            ? (string)($backup['lastSuccessLabel'] ?? 'Sağlıklı')
            : ($backupWarn ? 'Yedek zamanı yok' : 'Görüntülenemiyor'),
    ];

    $diskOk = isset($server['diskUsedPct']) && $server['diskUsedPct'] !== null;
    $checks[] = [
        'id' => 'disk',
        'name' => 'Disk',
        'status' => $diskOk ? 'green' : 'yellow',
        'label' => $diskOk ? (string)($server['diskDisplay'] ?? 'Sağlıklı') : 'Bilinmiyor',
    ];

    $memoryOk = isset($server['memoryUsedPct']) && $server['memoryUsedPct'] !== null;
    $checks[] = [
        'id' => 'memory',
        'name' => 'Bellek',
        'status' => $memoryOk ? 'green' : 'yellow',
        'label' => $memoryOk ? (string)($server['memoryDisplay'] ?? 'Sağlıklı') : 'Bilinmiyor',
    ];

    $checks[] = [
        'id' => 'founder_auth',
        'name' => 'Kurucu oturumu',
        'status' => $authOk ? 'green' : 'red',
        'label' => $authOk ? 'Sağlıklı' : 'Oturum gerekli',
    ];

    $totalChecks = count($checks);
    $passedChecks = 0;
    $hasYellow = false;
    $hasRed = false;
    foreach ($checks as $check) {
        if ($check['status'] === 'green') {
            $passedChecks++;
        } elseif ($check['status'] === 'yellow') {
            $hasYellow = true;
        } elseif ($check['status'] === 'red') {
            $hasRed = true;
        }
    }

    if ($hasRed) {
        $overallStatus = 'red';
        $overallLabel = 'Müdahale Gerekli';
    } elseif ($hasYellow) {
        $overallStatus = 'yellow';
        $overallLabel = 'Dikkat Gerekiyor';
    } else {
        $overallStatus = 'green';
        $overallLabel = 'Operasyonel';
    }

    return [
        'status' => $overallStatus,
        'statusLabel' => $overallLabel,
        'passedChecks' => $passedChecks,
        'totalChecks' => $totalChecks,
        'checkedAt' => $checkedAt,
        'checks' => $checks,
    ];
}

/** @return array<string,mixed> */
function zinesh_founder_system_health(bool $authOk = true): array {
    zinesh_ensure_core_data_files();

    $files = [];
    foreach (zinesh_founder_health_data_files() as $file) {
        try {
            $files[] = zinesh_founder_file_health($file);
        } catch (Throwable $e) {
            $detail = $e->getMessage();
            $files[] = [
                'file' => $file,
                'status' => 'red',
                'detail' => $detail,
                'label' => zinesh_founder_file_health_label($detail) !== $detail
                    ? zinesh_founder_file_health_label($detail)
                    : 'Hata',
            ];
        }
    }

    try {
        $backup = zinesh_founder_backup_status_from_file();
    } catch (Throwable) {
        $backup = [
            'lastSuccessLabel' => null,
            'display' => 'Görüntülenemiyor',
            'source' => 'Google Drive backups',
            'unavailable' => true,
            'gdriveConnected' => false,
            'localBackupOnly' => false,
        ];
    }

    try {
        $server = zinesh_founder_server_stats();
    } catch (Throwable) {
        $server = [
            'status' => 'green',
            'label' => 'Çalışıyor',
            'uptimeLabel' => 'Bilinmiyor',
            'diskDisplay' => 'Bilinmiyor',
            'diskSubDisplay' => 'Bilinmiyor',
            'memoryDisplay' => 'Bilinmiyor',
            'memorySubDisplay' => 'Bilinmiyor',
        ];
    }

    $checkedAt = zinesh_founder_format_checked_at(date('c'));

    $disasterRecovery = [
        'provider' => 'Google Drive',
        'connected' => (bool)$backup['gdriveConnected'],
        'localOnly' => !empty($backup['localBackupOnly']),
        'label' => $backup['gdriveConnected']
            ? 'Bağlı'
            : (!empty($backup['localBackupOnly']) ? 'İsteğe bağlı (yerel yedek)' : 'Bağlı değil'),
    ];

    $overall = zinesh_founder_overall_status(
        $files,
        [
            'lastSuccessLabel' => $backup['lastSuccessLabel'],
            'display' => $backup['display'],
            'source' => $backup['source'],
            'unavailable' => (bool)$backup['unavailable'],
        ],
        $disasterRecovery,
        $server,
        $authOk,
        $checkedAt
    );

    return [
        'updatedAt' => date('c'),
        'checkedAt' => $checkedAt,
        'overall' => $overall,
        'files' => $files,
        'backup' => [
            'lastSuccessLabel' => $backup['lastSuccessLabel'],
            'display' => $backup['display'],
            'source' => $backup['source'],
            'unavailable' => (bool)$backup['unavailable'],
        ],
        'disasterRecovery' => $disasterRecovery,
        'server' => $server,
    ];
}
