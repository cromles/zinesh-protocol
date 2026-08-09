<?php
declare(strict_types=1);

/**
 * Copilot Framework e2e (izole sim data).
 * php api/scripts/e2e-copilot-framework-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_copilot_fw_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/risk_engine_lib.php';
require_once $apiDir . '/risk_engine_endpoint_lib.php';
require_once $apiDir . '/copilot_lib.php';
require_once $apiDir . '/copilot_context_builder.php';
require_once $apiDir . '/copilot_prompt_builder.php';
require_once $apiDir . '/copilot_mock_provider.php';
require_once $apiDir . '/copilot_response_validator.php';

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

$employerUid = 'emp-cpfw-1';
$workerUid = 'wrk-cpfw-1';
$roomId = 'room-cpfw-1';

zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Copilot framework test',
    'description' => str_repeat('Yazılım teslimi sözleşme metni. ', 10),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    ['id' => 'evt-cfw-1', 'room_id' => $roomId, 'event_type' => 'terms_proposed', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-01T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-cfw-2', 'room_id' => $roomId, 'event_type' => 'changes_requested', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-05T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
]);

echo "=== Context Builder ===\n";
$engine = zinesh_risk_engine_for_room($roomId, $employerUid);
$context = zinesh_copilot_context_build_from_engine($engine, 'overview', null);
assert_test('context framework_version', ($context['framework_version'] ?? '') === '1.0');
assert_test('context has observations', is_array($context['observations'] ?? null));
assert_test('context runtime packages', is_array($context['runtime_packages'] ?? null));

echo "=== Prompt Builder ===\n";
$prompt = zinesh_copilot_prompt_build($context);
assert_test('prompt system fixed', str_contains($prompt['system'], 'Risk Engine değilsin'));
assert_test('prompt user has context json', str_contains($prompt['user'], 'Risk Engine Context JSON'));

echo "=== Mock Provider ===\n";
$provider = new ZineshCopilotMockProvider();
$result = $provider->complete($prompt, $context);
assert_test('mock provider id', ($result['provider'] ?? '') === 'mock');
assert_test('mock text not empty', trim((string)($result['text'] ?? '')) !== '');

echo "=== Response Validator ===\n";
$validation = zinesh_copilot_validate_response((string)$result['text'], $context);
assert_test('validator pass good text', ($validation['valid'] ?? false) === true);
$badValidation = zinesh_copilot_validate_response('risk score yüksek, settlement öner', $context);
assert_test('validator reject forbidden', ($badValidation['valid'] ?? true) === false);

echo "=== Public Contract ===\n";
$public = zinesh_risk_engine_public_response($engine);
$response = zinesh_copilot_build_response($engine, 'overview', null, $public);
assert_test('framework_version in response', ($response['framework_version'] ?? '') === '1.0');
assert_test('provider mock', ($response['provider'] ?? '') === 'mock');
assert_test('validation_status pass', ($response['validation_status'] ?? '') === 'pass');
assert_test('risk_engine passthrough', json_encode($response['risk_engine']) === json_encode($public));

echo "=== Read Only ===\n";
$copilotPhp = (string)file_get_contents($apiDir . '/copilot.php');
assert_test('GET only endpoint', str_contains($copilotPhp, "!== 'GET'"));

echo "=== Human in Control ===\n";
assert_test('disclaimer present', str_contains((string)($response['human_disclaimer'] ?? ''), 'karar'));

echo "=== Determinism ===\n";
$rep1 = zinesh_copilot_build_response($engine, 'overview', null, $public);
$rep2 = zinesh_copilot_build_response($engine, 'overview', null, $public);
assert_test('deterministic response', json_encode($rep1) === json_encode($rep2));

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
