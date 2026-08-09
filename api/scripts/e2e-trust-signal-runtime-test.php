<?php
declare(strict_types=1);

/**
 * Trust Signal Runtime v0.1 e2e (izole sim data).
 * php api/scripts/e2e-trust-signal-runtime-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_trust_signal_rt_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/trust_signal_runtime_lib.php';

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

/** @return bool */
function signal_has_id(array $pkg, string $signalId): bool
{
    foreach ($pkg['signals'] ?? [] as $row) {
        if (is_array($row) && ($row['signal_id'] ?? '') === $signalId) {
            return true;
        }
    }
    return false;
}

/** @return array<string,mixed>|null */
function signal_find(array $pkg, string $signalId): ?array
{
    foreach ($pkg['signals'] ?? [] as $row) {
        if (is_array($row) && ($row['signal_id'] ?? '') === $signalId) {
            return $row;
        }
    }
    return null;
}

$employerUid = 'emp-sig-1';
$workerUid = 'wrk-sig-1';

echo "=== Boş veri → Signal yok ===\n";
zinesh_json_write('escrow_rooms.json', []);
$emptyPkg = zinesh_trust_signal_runtime_for_room('room-sig-nonexistent');
assert_test('empty room signals count 0', count($emptyPkg['signals'] ?? []) === 0);
assert_test('catalog version set', ($emptyPkg['catalog_version'] ?? '') === '0.1');

echo "=== İlk işlem → SIG-BEH-001 ===\n";
$firstRoom = 'room-sig-first';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $firstRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'createdAt' => '2026-08-05T10:00:00+03:00',
    'lockedAt' => '2026-08-05T11:00:00+03:00',
]]);
zinesh_domain_event_emit($firstRoom, 'escrow_locked', $employerUid, 'employer', [], ['idempotency_key' => $firstRoom . ':lock']);
$firstPkg = zinesh_trust_signal_runtime_for_room($firstRoom, $employerUid);
assert_test('first transaction signal emitted', signal_has_id($firstPkg, 'SIG-BEH-001'));

echo "=== Yüksek revizyon → SIG-NEG-001 ===\n";
$revRoom = 'room-sig-rev';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $revRoom,
    'status' => 'terms_pending',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Revizyon test',
    'description' => str_repeat('Uzun sözleşme metni. ', 12),
    'agreedAmountTry' => 5000.0,
    'createdAt' => '2026-08-06T09:00:00+03:00',
]]);
$roomRev = [
    'id' => $revRoom,
    'title' => 'Revizyon test',
    'description' => str_repeat('Uzun sözleşme metni. ', 12),
    'agreedAmountTry' => 5000.0,
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
];
zinesh_escrow_memory_on_terms_version($roomRev, ['uid' => $employerUid], 'employer', 'propose');
zinesh_escrow_memory_on_changes_requested($roomRev, ['uid' => $workerUid], 'Revizyon 1');
$roomRev['agreedAmountTry'] = 5200.0;
zinesh_escrow_memory_on_terms_version($roomRev, ['uid' => $workerUid], 'worker', 'counter_offer', ['reason' => 'scope']);
zinesh_escrow_memory_on_changes_requested($roomRev, ['uid' => $employerUid], 'Revizyon 2');
$roomRev['agreedAmountTry'] = 5400.0;
zinesh_escrow_memory_on_terms_version($roomRev, ['uid' => $employerUid], 'employer', 'counter_offer', ['reason' => 'price']);
$revPkg = zinesh_trust_signal_runtime_for_room($revRoom);
assert_test('frequent revisions signal', signal_has_id($revPkg, 'SIG-NEG-001'));

echo "=== Explainability zinciri ===\n";
$negSig = signal_find($revPkg, 'SIG-NEG-001');
$explainOk = is_array($negSig)
    && ($negSig['explanation']['chain'] ?? '') === 'signal → metric → event'
    && is_array($negSig['metric_sources'] ?? null)
    && $negSig['metric_sources'] !== []
    && is_array($negSig['event_sources'] ?? null)
    && ($negSig['confidence'] ?? '') !== '';
assert_test('explainability chain complete', $explainOk);

echo "=== Determinism ===\n";
$det1 = zinesh_trust_signal_runtime_for_room($revRoom);
$det2 = zinesh_trust_signal_runtime_for_room($revRoom);
assert_test('determinism identical package', json_encode($det1) === json_encode($det2));

echo "=== Uzun müzakere → SIG-TIME-002 ===\n";
$longNegRoom = 'room-sig-longneg';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $longNegRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Uzun müzakere',
    'description' => str_repeat('Sözleşme metni. ', 10),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    [
        'id' => 'evt-ln-1',
        'room_id' => $longNegRoom,
        'event_type' => 'terms_proposed',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-01-01T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-ln-2',
        'room_id' => $longNegRoom,
        'event_type' => 'counter_offer_created',
        'actor_id' => $workerUid,
        'actor_role' => 'worker',
        'created_at' => '2026-01-05T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-ln-3',
        'room_id' => $longNegRoom,
        'event_type' => 'counter_offer_created',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-01-10T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-ln-4',
        'room_id' => $longNegRoom,
        'event_type' => 'counter_offer_created',
        'actor_id' => $workerUid,
        'actor_role' => 'worker',
        'created_at' => '2026-01-12T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-ln-5',
        'room_id' => $longNegRoom,
        'event_type' => 'terms_accepted',
        'actor_id' => $workerUid,
        'actor_role' => 'worker',
        'created_at' => '2026-01-15T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-ln-6',
        'room_id' => $longNegRoom,
        'event_type' => 'escrow_locked',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-01-20T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
]);
$longNegPkg = zinesh_trust_signal_runtime_for_room($longNegRoom);
assert_test('long negotiation signal', signal_has_id($longNegPkg, 'SIG-TIME-002'));
$time002 = signal_find($longNegPkg, 'SIG-TIME-002');
assert_test(
    'TIME-002 emit gate uses metric_sources',
    is_array($time002)
        && isset($time002['metric_sources']['time.negotiation_duration_seconds'])
        && ($time002['metric_sources']['time.negotiation_duration_seconds'] ?? 0) >= ZINESH_SIG_TIME002_MIN_SECONDS
);

echo "=== Signal output schema ===\n";
$schemaOk = is_array($negSig)
    && isset($negSig['signal_id'], $negSig['title'], $negSig['description'])
    && isset($negSig['metric_sources'], $negSig['event_sources'], $negSig['explanation'])
    && isset($negSig['confidence'], $negSig['signal_version'])
    && ($negSig['emitted'] ?? false) === true;
assert_test('required signal fields present', $schemaOk);

echo "=== Catalog signal set unchanged (18) ===\n";
assert_test('catalog has 18 signal ids', count(zinesh_trust_signal_catalog_ids()) === 18);

echo "=== Dispute → SIG-DSP-001 ===\n";
$dspRoom = 'room-sig-dsp';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $dspRoom,
    'status' => 'disputed',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'createdAt' => '2026-08-01T10:00:00+03:00',
]]);
zinesh_domain_event_emit($dspRoom, 'dispute_opened', $workerUid, 'worker', [], ['idempotency_key' => $dspRoom . ':dsp']);
$dspPkg = zinesh_trust_signal_runtime_for_room($dspRoom);
assert_test('dispute frequency signal', signal_has_id($dspPkg, 'SIG-DSP-001'));

echo "=== Teslim gecikmesi → SIG-TIME-003 ===\n";
$delayRoom = 'room-sig-delay';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $delayRoom,
    'status' => 'completed',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-02T10:00:00+03:00',
    'completedAt' => '2026-02-01T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    [
        'id' => 'evt-dd-1',
        'room_id' => $delayRoom,
        'event_type' => 'escrow_locked',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-01-02T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-dd-2',
        'room_id' => $delayRoom,
        'event_type' => 'settlement_completed',
        'actor_id' => 'system',
        'actor_role' => 'system',
        'created_at' => '2026-02-01T10:00:00+03:00',
        'payload_json' => ['amount_try' => 1000],
        'metadata_json' => [],
    ],
]);
$delayPkg = zinesh_trust_signal_runtime_for_room($delayRoom);
assert_test('delivery delay signal', signal_has_id($delayPkg, 'SIG-TIME-003'));
$time003 = signal_find($delayPkg, 'SIG-TIME-003');
assert_test(
    'TIME-003 emit gate uses lock_to_settlement metric',
    is_array($time003)
        && isset($time003['metric_sources']['time.lock_to_settlement_seconds'])
        && ($time003['metric_sources']['time.lock_to_settlement_seconds'] ?? 0) >= ZINESH_SIG_TIME003_MIN_LOCK_TO_SETTLE
);
assert_test(
    'TIME-003 event_sources are evidence only',
    is_array($time003) && is_array($time003['event_sources'] ?? null)
);

echo "=== Settlement failure → SIG-SET-003 ===\n";
$failRoom = 'room-sig-setfail';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $failRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'createdAt' => '2026-08-01T10:00:00+03:00',
    'lockedAt' => '2026-08-02T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    [
        'id' => 'evt-sf-1',
        'room_id' => $failRoom,
        'event_type' => 'escrow_locked',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-08-02T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-sf-2',
        'room_id' => $failRoom,
        'event_type' => 'settlement_failed',
        'actor_id' => 'system',
        'actor_role' => 'system',
        'created_at' => '2026-08-03T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
]);
$failPkg = zinesh_trust_signal_runtime_for_room($failRoom);
assert_test('settlement failure signal', signal_has_id($failPkg, 'SIG-SET-003'));
$set003 = signal_find($failPkg, 'SIG-SET-003');
assert_test(
    'SET-003 emit gate uses settlement.completion_status metric',
    is_array($set003)
        && ($set003['metric_sources']['settlement.completion_status'] ?? '') === 'settlement_failed'
);

echo "=== Recovery → SIG-ACT-002 ===\n";
$recRoom = 'room-sig-rec';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $recRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'createdAt' => '2026-08-01T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    [
        'id' => 'evt-rc-1',
        'room_id' => $recRoom,
        'event_type' => 'escrow_recovered',
        'actor_id' => 'system',
        'actor_role' => 'system',
        'created_at' => '2026-08-04T10:00:00+03:00',
        'payload_json' => ['recovery_action' => 'settling_revert'],
        'metadata_json' => [],
    ],
]);
$recPkg = zinesh_trust_signal_runtime_for_room($recRoom);
assert_test('recovery signal', signal_has_id($recPkg, 'SIG-ACT-002'));
$act002 = signal_find($recPkg, 'SIG-ACT-002');
assert_test(
    'ACT-002 emit gate uses activity.recovery_count metric',
    is_array($act002) && (int)($act002['metric_sources']['activity.recovery_count'] ?? 0) >= 1
);

echo "=== Replayability ===\n";
$replay1 = zinesh_trust_signal_runtime_for_room($delayRoom);
$replay2 = zinesh_trust_signal_runtime_for_room($delayRoom);
assert_test('replay same signal ids', zinesh_trust_signal_runtime_signal_ids($replay1) === zinesh_trust_signal_runtime_signal_ids($replay2));

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
