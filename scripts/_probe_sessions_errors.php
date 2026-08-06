<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';

$sessions = zinesh_json_read('sessions.json');
echo 'sessions_type=' . gettype($sessions) . ' count=' . (is_array($sessions) ? count($sessions) : 0) . PHP_EOL;
if (is_array($sessions)) {
    $i = 0;
    foreach ($sessions as $k => $s) {
        if (!is_array($s)) continue;
        echo json_encode([
            'key' => is_string($k) ? $k : $i,
            'uid' => substr((string)($s['uid'] ?? ''), 0, 12),
            'token_len' => strlen((string)($s['token'] ?? '')),
            'expires' => $s['expiresAt'] ?? $s['expires'] ?? null,
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        if (++$i >= 8) break;
    }
}

// tail nginx/php errors for escrow
$cmds = [
    'tail -n 30 /var/log/nginx/error.log 2>/dev/null',
    'tail -n 30 /www/wwwlogs/zinesh.com.error.log 2>/dev/null',
    'tail -n 30 /www/wwwlogs/error.log 2>/dev/null',
];
foreach ($cmds as $c) {
    echo "=== $c ===\n";
    passthru($c);
}
