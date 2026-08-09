<?php
declare(strict_types=1);

/**
 * Risk Engine backend integration e2e (izole sim data).
 * php api/scripts/e2e-risk-engine-backend-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_risk_engine_be_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/risk_engine_lib.php';
require_once $apiDir . '/risk_engine_endpoint_lib.php';
require_once $apiDir . '/risk_observation_runtime_lib.php';

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

/** @return list<array<string,mixed>> */
function risk_engine_backend_observation_rows(array $public): array
{
    $pkg = is_array($public['observations'] ?? null) ? $public['observations'] : [];
    return is_array($pkg['observations'] ?? null) ? $pkg['observations'] : [];
}

$employerUid = 'emp-rebe-1';
$workerUid = 'wrk-rebe-1';
$outsiderUid = 'outsider-rebe-1';
$roomId = 'room-rebe-1';

zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Backend integration test',
    'description' => str_repeat('Yazılım teslimi sözleşme metni. ', 10),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    ['id' => 'evt-rb-1', 'room_id' => $roomId, 'event_type' => 'terms_proposed', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-01T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-rb-2', 'room_id' => $roomId, 'event_type' => 'counter_offer_created', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-05T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-rb-3', 'room_id' => $roomId, 'event_type' => 'counter_offer_created', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-10T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-rb-4', 'room_id' => $roomId, 'event_type' => 'counter_offer_created', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-12T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-rb-5', 'room_id' => $roomId, 'event_type' => 'terms_accepted', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-15T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-rb-6', 'room_id' => $roomId, 'event_type' => 'escrow_locked', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-20T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
]);

echo "=== Endpoint çalışıyor ===\n";
$engine = zinesh_risk_engine_for_room($roomId, $employerUid);
$public = zinesh_risk_engine_public_response($engine);
assert_test('endpoint_version set', ($public['endpoint_version'] ?? '') === '1.0');
assert_test('risk_engine_version set', ($public['risk_engine_version'] ?? '') === '0.1');
assert_test('room_id matches', ($public['room_id'] ?? '') === $roomId);

echo "=== Response schema ===\n";
$schemaKeys = [
    'endpoint_version',
    'risk_engine_version',
    'room_id',
    'actor_id',
    'metrics',
    'signals',
    'contexts',
    'observations',
];
$schemaOk = true;
foreach ($schemaKeys as $key) {
    if (!array_key_exists($key, $public)) {
        $schemaOk = false;
        break;
    }
}
assert_test('response schema keys', $schemaOk);
assert_test('metrics is full runtime package', is_array($public['metrics']['metrics'] ?? null));
assert_test('signals is full runtime package', is_array($public['signals']['signals'] ?? null));
assert_test('contexts is full runtime package', is_array($public['contexts']['contexts'] ?? null));
assert_test('observations is full runtime package', is_array($public['observations']['observations'] ?? null));

echo "=== Participant access ===\n";
assert_test('employer participant access', zinesh_risk_engine_can_access($roomId, $employerUid));
assert_test('worker participant access', zinesh_risk_engine_can_access($roomId, $workerUid));

echo "=== Unauthorized = 403 (access model) ===\n";
assert_test('outsider denied', zinesh_risk_engine_can_access($roomId, $outsiderUid) === false);
assert_test('empty uid denied', zinesh_risk_engine_can_access($roomId, '') === false);

echo "=== Explainability korunuyor ===\n";
$obsRows = risk_engine_backend_observation_rows($public);
$explainOk = true;
foreach ($obsRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $chain = (string)($row['explainability']['chain'] ?? '');
    $ev = (string)($row['evidence_chain']['chain'] ?? '');
    if ($chain !== 'risk_observation → context → signal → metric → event'
        || $ev !== 'risk_observation → context → signal → metric → event') {
        $explainOk = false;
        break;
    }
}
assert_test('explainability chain preserved', $obsRows === [] || $explainOk);

echo "=== Evidence chain korunuyor ===\n";
$evidenceOk = true;
foreach ($obsRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    if (!is_array($row['evidence_chain'] ?? null) || ($row['evidence_chain']['chain'] ?? '') === '') {
        $evidenceOk = false;
        break;
    }
}
assert_test('evidence chain preserved', $obsRows === [] || $evidenceOk);

echo "=== Confidence korunuyor ===\n";
$confidenceOk = true;
foreach ($obsRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $c = strtolower((string)($row['confidence'] ?? ''));
    if (!in_array($c, ['low', 'medium', 'high'], true)) {
        $confidenceOk = false;
        break;
    }
}
assert_test('confidence values valid', $obsRows === [] || $confidenceOk);

echo "=== Runtime davranışı değişmiyor ===\n";
$runtimeBefore = zinesh_risk_engine_for_room($roomId, $employerUid);
zinesh_risk_engine_public_response($runtimeBefore);
$runtimeAfter = zinesh_risk_engine_for_room($roomId, $employerUid);
assert_test('runtime unchanged by public response', json_encode($runtimeBefore) === json_encode($runtimeAfter));

echo "=== Public response = runtime passthrough ===\n";
$expected = [
    'endpoint_version' => ZINESH_RISK_ENGINE_ENDPOINT_VERSION,
    'risk_engine_version' => $runtimeBefore['risk_engine_version'] ?? '',
    'room_id' => $runtimeBefore['room_id'] ?? '',
    'actor_id' => $runtimeBefore['actor_id'] ?? null,
    'metrics' => $runtimeBefore['metrics'] ?? [],
    'signals' => $runtimeBefore['signals'] ?? [],
    'contexts' => $runtimeBefore['contexts'] ?? [],
    'observations' => $runtimeBefore['observations'] ?? [],
];
assert_test('public mirrors runtime packages', json_encode(zinesh_risk_engine_public_response($runtimeBefore)) === json_encode($expected));

echo "=== Read-only ===\n";
$endpointSource = (string)file_get_contents($apiDir . '/risk_engine.php');
$libSource = (string)file_get_contents($apiDir . '/risk_engine_endpoint_lib.php');
assert_test('endpoint calls zinesh_risk_engine_for_room only', str_contains($endpointSource, 'zinesh_risk_engine_for_room'));
assert_test('endpoint no json write', !str_contains($endpointSource, 'zinesh_json_write'));
assert_test('endpoint lib no json write', !str_contains($libSource, 'zinesh_json_write'));
assert_test('endpoint file exists', is_file($apiDir . '/risk_engine.php'));

echo "=== Determinism ===\n";
$det1 = zinesh_risk_engine_public_response(zinesh_risk_engine_for_room($roomId, $employerUid));
$det2 = zinesh_risk_engine_public_response(zinesh_risk_engine_for_room($roomId, $employerUid));
$normalize = static function (array $pkg): array {
    if (isset($pkg['metrics']) && is_array($pkg['metrics'])) {
        unset($pkg['metrics']['generated_at']);
    }
    return $pkg;
};
assert_test('determinism same output', json_encode($normalize($det1)) === json_encode($normalize($det2)));

echo "=== Replayability ===\n";
$rep1 = zinesh_risk_engine_for_room($roomId, $employerUid);
$rep2 = zinesh_risk_engine_for_room($roomId, $employerUid);
$ids1 = zinesh_risk_observation_runtime_observation_ids($rep1['observations'] ?? []);
$ids2 = zinesh_risk_observation_runtime_observation_ids($rep2['observations'] ?? []);
assert_test('replay same observation ids', $ids1 === $ids2);

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
