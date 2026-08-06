<?php
declare(strict_types=1);

/**
 * Pazar Radarı cron — X Türkçe anahtar kelime taraması → radar_leads.jsonl
 * CLI: php cron_marketing_radar.php
 */
require_once __DIR__ . '/marketing_engine_lib.php';

if (php_sapi_name() !== 'cli' && (string)($_SERVER['REQUEST_METHOD'] ?? '') !== '') {
    $key = trim((string)($_GET['key'] ?? ''));
    $expected = zinesh_cron_secret();
    if ($expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'forbidden']);
        exit;
    }
}

$result = zinesh_marketing_radar_scan();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
