<?php
declare(strict_types=1);

/**
 * Trust Metrics API smoke test (izole sim data).
 * php api/scripts/e2e-trust-metrics-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_trust_metrics_test_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/trust_metrics_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('zinesh_events.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('escrow_rooms.json', []);

$passed = 0;
$failed = 0;

function assert_test(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
    }
}

$roomId = 'room-tm-' . substr(bin2hex(random_bytes(3)), 0, 6);
$employerUid = 'emp-tm-1';
$workerUid = 'wrk-tm-1';
$outsiderUid = 'out-tm-1';

echo "=== Erişim kontrolü ===\n";
zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'negotiating',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
]]);

assert_test('employer participant access', zinesh_trust_metrics_can_access($roomId, $employerUid));
assert_test('worker participant access', zinesh_trust_metrics_can_access($roomId, $workerUid));
assert_test('outsider blocked', !zinesh_trust_metrics_can_access($roomId, $outsiderUid));
assert_test('unknown room blocked', !zinesh_trust_metrics_can_access('missing-room', $employerUid));

echo "=== Response shape ===\n";
$room = [
    'id' => $roomId,
    'title' => 'Test',
    'description' => str_repeat('Uzun sözleşme metni. ', 10),
    'agreedAmountTry' => 2000.0,
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
];
zinesh_escrow_memory_on_terms_version($room, ['uid' => $employerUid], 'employer', 'propose');

$raw = zinesh_trust_intelligence_metrics($roomId);
$public = zinesh_trust_metrics_public_response($raw);

assert_test('ok true', ($public['ok'] ?? false) === true);
assert_test('trust_version 0.1', ($public['trust_version'] ?? '') === '0.1');
assert_test('room_id set', ($public['room_id'] ?? '') === $roomId);
assert_test('metrics negotiation', is_array($public['metrics']['negotiation'] ?? null));
assert_test('metrics behavior', is_array($public['metrics']['behavior'] ?? null));
assert_test('generated_at set', (string)($public['generated_at'] ?? '') !== '');
assert_test('no uid leaked', !isset($public['uid']) && !isset($public['employerUid']));
assert_test('no payload field', !isset($public['payload']) && !isset($public['metrics']['payload']));
assert_test('no metadata field', !isset($public['metadata']));

echo "=== Boş / eski oda ===\n";
$emptyId = 'room-tm-empty';
zinesh_json_write('escrow_rooms.json', array_merge(
    zinesh_json_read('escrow_rooms.json'),
    [[
        'id' => $emptyId,
        'status' => 'completed',
        'employerUid' => $employerUid,
        'workerUid' => $workerUid,
    ]]
));

$empty = zinesh_trust_metrics_public_response(zinesh_trust_intelligence_metrics($emptyId));
assert_test('empty room ok', ($empty['ok'] ?? false) === true);
assert_test('empty negotiation rounds 0', ($empty['metrics']['negotiation']['negotiation_rounds'] ?? -1) === 0);
assert_test('empty completion unknown', ($empty['metrics']['settlement']['completion_status'] ?? '') === 'unknown'
    || ($empty['metrics']['settlement']['completion_status'] ?? '') === 'completed');

echo "=== Yetkisiz senaryo (simülasyon) ===\n";
$denied = !zinesh_trust_metrics_can_access($roomId, $outsiderUid);
assert_test('403 scenario: outsider cannot access', $denied);

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
