<?php
declare(strict_types=1);

/**
 * Risk Observation Runtime v0.1 e2e (izole sim data).
 * php api/scripts/e2e-risk-observation-runtime-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_risk_obs_rt_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/risk_observation_runtime_lib.php';
require_once $apiDir . '/context_resolver_lib.php';
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
function observation_has_id(array $pkg, string $obsId): bool
{
    foreach ($pkg['observations'] ?? [] as $row) {
        if (is_array($row) && ($row['observation_id'] ?? '') === $obsId) {
            return true;
        }
    }
    return false;
}

/** @return array<string,mixed>|null */
function observation_find(array $pkg, string $obsId): ?array
{
    foreach ($pkg['observations'] ?? [] as $row) {
        if (is_array($row) && ($row['observation_id'] ?? '') === $obsId) {
            return $row;
        }
    }
    return null;
}

/** @return bool */
function context_signal_has_id(array $ctxPkg, string $signalId): bool
{
    foreach ($ctxPkg['contexts'] ?? [] as $ctx) {
        if (!is_array($ctx)) {
            continue;
        }
        foreach ($ctx['signals_used'] ?? [] as $row) {
            if (is_array($row) && ($row['signal_id'] ?? '') === $signalId) {
                return true;
            }
        }
    }
    return false;
}

$employerUid = 'emp-obs-1';
$workerUid = 'wrk-obs-1';

echo "=== Boş oda → observation yok ===\n";
zinesh_json_write('escrow_rooms.json', []);
$emptyPkg = zinesh_risk_observation_runtime_for_room('room-obs-missing');
assert_test('empty room observations count 0', count($emptyPkg['observations'] ?? []) === 0);
assert_test('model version set', ($emptyPkg['model_version'] ?? '') === '1.0');

echo "=== İlk işlem → OBS-HIS-002 ===\n";
$firstRoom = 'room-obs-first';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $firstRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'İlk iş',
    'createdAt' => '2026-08-05T10:00:00+03:00',
    'lockedAt' => '2026-08-05T11:00:00+03:00',
]]);
zinesh_domain_event_emit($firstRoom, 'escrow_locked', $employerUid, 'employer', [], ['idempotency_key' => $firstRoom . ':lock']);
$firstPkg = zinesh_risk_observation_runtime_for_room($firstRoom, $employerUid);
assert_test('first transaction observation', observation_has_id($firstPkg, 'OBS-HIS-002'));

echo "=== Düşük geçmiş → OBS-HIS-001 ===\n";
assert_test('low historical evidence observation', observation_has_id($firstPkg, 'OBS-HIS-001'));

echo "=== Yüksek revizyon → OBS-REV-001 ===\n";
$revRoom = 'room-obs-rev';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $revRoom,
    'status' => 'terms_pending',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Freelance yazılım revizyon testi',
    'description' => str_repeat('Uzun yazılım sözleşme metni. ', 14),
    'agreedAmountTry' => 5000.0,
    'createdAt' => '2026-08-06T09:00:00+03:00',
]]);
$roomRev = [
    'id' => $revRoom,
    'title' => 'Freelance yazılım revizyon testi',
    'description' => str_repeat('Uzun yazılım sözleşme metni. ', 14),
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
zinesh_escrow_memory_on_changes_requested($roomRev, ['uid' => $workerUid], 'Revizyon 3');
$roomRev['agreedAmountTry'] = 5600.0;
zinesh_escrow_memory_on_terms_version($roomRev, ['uid' => $employerUid], 'employer', 'counter_offer', ['reason' => 'scope2']);
$revPkg = zinesh_risk_observation_runtime_for_room($revRoom);
$revCtxPkg = zinesh_context_resolver_for_room($revRoom);
assert_test('revision room has SIG-NEG-001 in context', context_signal_has_id($revCtxPkg, 'SIG-NEG-001'));
assert_test('revision room has SIG-CTR-001 in context', context_signal_has_id($revCtxPkg, 'SIG-CTR-001'));
assert_test('high revision pattern observation', observation_has_id($revPkg, 'OBS-REV-001'));

echo "=== Uzun müzakere → OBS-NEG-001 ===\n";
$longNegRoom = 'room-obs-longneg';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $longNegRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Kurumsal yazılım uzun müzakere',
    'description' => str_repeat('Sözleşme metni yazılım. ', 10),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    ['id' => 'evt-on-1', 'room_id' => $longNegRoom, 'event_type' => 'terms_proposed', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-01T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-on-2', 'room_id' => $longNegRoom, 'event_type' => 'counter_offer_created', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-05T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-on-3', 'room_id' => $longNegRoom, 'event_type' => 'counter_offer_created', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-10T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-on-4', 'room_id' => $longNegRoom, 'event_type' => 'counter_offer_created', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-12T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-on-5', 'room_id' => $longNegRoom, 'event_type' => 'terms_accepted', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-15T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-on-6', 'room_id' => $longNegRoom, 'event_type' => 'escrow_locked', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-20T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
]);
$longNegPkg = zinesh_risk_observation_runtime_for_room($longNegRoom);
assert_test('high negotiation complexity observation', observation_has_id($longNegPkg, 'OBS-NEG-001'));

echo "=== Dispute → OBS-SET-001 ===\n";
$dspRoom = 'room-obs-dsp';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $dspRoom,
    'status' => 'disputed',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Dispute test',
    'createdAt' => '2026-08-01T10:00:00+03:00',
]]);
zinesh_domain_event_emit($dspRoom, 'dispute_opened', $workerUid, 'worker', [], ['idempotency_key' => $dspRoom . ':dsp']);
$dspPkg = zinesh_risk_observation_runtime_for_room($dspRoom);
assert_test('settlement instability observation', observation_has_id($dspPkg, 'OBS-SET-001'));

echo "=== Context katmanı değişmez ===\n";
$ctxBefore = zinesh_context_resolver_for_room($longNegRoom);
$riskPkg = zinesh_risk_observation_runtime_for_room($longNegRoom);
$ctxAfter = zinesh_context_resolver_for_room($longNegRoom);
assert_test('context runtime unchanged by risk observation', json_encode($ctxBefore) === json_encode($ctxAfter));

echo "=== Explainability zinciri ===\n";
$negObs = observation_find($longNegPkg, 'OBS-NEG-001');
$explainOk = is_array($negObs)
    && ($negObs['explainability']['chain'] ?? '') === 'risk_observation → context → signal → metric → event'
    && ($negObs['evidence_chain']['chain'] ?? '') === 'risk_observation → context → signal → metric → event'
    && is_array($negObs['contexts_used'] ?? null)
    && is_array($negObs['signals_used'] ?? null)
    && is_array($negObs['metrics_used'] ?? null)
    && ($negObs['confidence'] ?? '') !== '';
assert_test('explainability chain complete', $explainOk);

echo "=== Determinism ===\n";
$det1 = zinesh_risk_observation_runtime_for_room($longNegRoom);
$det2 = zinesh_risk_observation_runtime_for_room($longNegRoom);
assert_test('determinism identical package', json_encode($det1) === json_encode($det2));

echo "=== Replayability ===\n";
$rep1 = zinesh_risk_observation_runtime_for_room($revRoom);
$rep2 = zinesh_risk_observation_runtime_for_room($revRoom);
assert_test('replay same observation ids', zinesh_risk_observation_runtime_observation_ids($rep1) === zinesh_risk_observation_runtime_observation_ids($rep2));

echo "=== Catalog observation set (15) ===\n";
assert_test('catalog has 15 observation ids', count(zinesh_risk_observation_catalog_ids()) === 15);

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
