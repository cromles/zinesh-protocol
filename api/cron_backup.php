<?php
declare(strict_types=1);

/**
 * Hourly verified local snapshot + Google Drive off-site upload.
 * CLI (root cron): php /www/wwwroot/zinesh.com/api/cron_backup.php
 * HTTP: GET/POST with cron_key
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/backup_lib.php';
require_once __DIR__ . '/backup_gdrive_lib.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $key = (string)($_GET['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
    $expected = zinesh_cron_secret();
    if ($expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit('forbidden');
    }
}

$result = zinesh_backup_run_pipeline(30);

if (!empty($result['local']['ok'])) {
    zinesh_audit('backup', [
        'files' => (int)($result['local']['copied'] ?? 0),
        'path' => (string)($result['local']['path'] ?? ''),
        'source' => (string)($result['local']['source'] ?? ''),
        'verified' => true,
        'gdrive' => (bool)($result['gdrive']['ok'] ?? false),
        'gdrive_skipped' => (bool)($result['gdrive']['skipped'] ?? false),
    ]);
}

if ($isCli) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(!empty($result['ok']) ? 0 : 1);
}

header('Content-Type: application/json');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
