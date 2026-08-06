<?php
declare(strict_types=1);

/**
 * Bekleyen çekimleri otomatik gönderir.
 * Sunucu cron (her 2 dk): php /www/wwwroot/zinesh.com/api/cron_withdraw.php
 */
require_once __DIR__ . '/withdraw_executor.php';
require_once __DIR__ . '/security_lib.php';

$key = (string)($_GET['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
$expected = zinesh_cron_secret();
if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    exit('forbidden');
}

$results = zinesh_process_pending_withdrawals(20);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'processed' => count($results), 'results' => $results], JSON_UNESCAPED_UNICODE);
