<?php
declare(strict_types=1);

/**
 * Intelligence Copilot e2e (izole sim data).
 * php api/scripts/e2e-copilot-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_copilot_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/risk_engine_lib.php';
require_once $apiDir . '/risk_engine_endpoint_lib.php';
require_once $apiDir . '/copilot_lib.php';

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

$employerUid = 'emp-copilot-1';
$workerUid = 'wrk-copilot-1';
$roomId = 'room-copilot-1';

zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Copilot test',
    'description' => str_repeat('Yazılım teslimi sözleşme metni. ', 10),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    ['id' => 'evt-cp-1', 'room_id' => $roomId, 'event_type' => 'terms_proposed', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-01T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-cp-2', 'room_id' => $roomId, 'event_type' => 'changes_requested', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-05T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-cp-3', 'room_id' => $roomId, 'event_type' => 'counter_offer_created', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-10T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
]);

echo "=== Copilot response ===\n";
$engine = zinesh_risk_engine_for_room($roomId, $employerUid);
$public = zinesh_risk_engine_public_response($engine);
$response = zinesh_copilot_build_response($engine, 'overview', null, $public);
assert_test('copilot_version set', ($response['copilot_version'] ?? '') === '1.0');
assert_test('endpoint_version set', ($response['endpoint_version'] ?? '') === '1.0');
assert_test('explanation not empty', trim((string)($response['explanation'] ?? '')) !== '');
assert_test('human disclaimer set', str_contains((string)($response['human_disclaimer'] ?? ''), 'karar'));

echo "=== Explainability korunumu ===\n";
assert_test(
    'explainability chain',
    ($response['explainability_chain'] ?? '') === ZINESH_COPILOT_EXPLAINABILITY_CHAIN
);

$rows = zinesh_copilot_observation_rows($engine);
$firstObsId = is_array($rows[0] ?? null) ? (string)($rows[0]['observation_id'] ?? '') : '';
if ($firstObsId !== '') {
    $why = zinesh_copilot_build_response($engine, 'why_observation', $firstObsId, $public);
    assert_test('why_observation explanation', str_contains((string)($why['explanation'] ?? ''), $firstObsId));
    assert_test('why has sources', is_array($why['sources'] ?? null));
}

echo "=== Public Contract ===\n";
assert_test('risk_engine passthrough', json_encode($response['risk_engine']) === json_encode($public));

echo "=== Read Only ===\n";
$copilotSource = (string)file_get_contents($apiDir . '/copilot.php');
$copilotLibSource = (string)file_get_contents($apiDir . '/copilot_lib.php');
assert_test('copilot.php GET only pattern', str_contains($copilotSource, "!== 'GET'"));
assert_test('copilot lib no json write', !str_contains($copilotLibSource, 'zinesh_json_write'));

echo "=== Human in Control ===\n";
$forbidden = ['risk score', 'tavsiye ed', 'settlement öner', 'fraud tespiti'];
$advisoryOk = true;
foreach ($forbidden as $word) {
    if (stripos((string)($response['explanation'] ?? ''), $word) !== false) {
        $advisoryOk = false;
        break;
    }
}
assert_test('no forbidden advisory language', $advisoryOk);

echo "=== Determinism ===\n";
$rep1 = zinesh_copilot_build_response($engine, 'overview', null, $public);
$rep2 = zinesh_copilot_build_response($engine, 'overview', null, $public);
assert_test('determinism same output', json_encode($rep1) === json_encode($rep2));

echo "=== Replayability ===\n";
$engine2 = zinesh_risk_engine_for_room($roomId, $employerUid);
$public2 = zinesh_risk_engine_public_response($engine2);
$replay = zinesh_copilot_build_response($engine2, 'overview', null, $public2);
assert_test('replay same explanation', ($replay['explanation'] ?? '') === ($rep1['explanation'] ?? ''));

echo "=== Runtime immutability ===\n";
$engineBefore = json_encode($engine);
zinesh_copilot_build_response($engine, 'overview', null, $public);
assert_test('engine not mutated', json_encode($engine) === $engineBefore);

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
