<?php
declare(strict_types=1);

/**
 * Risk Engine Orchestrator v0.1 e2e (izole sim data).
 * php api/scripts/e2e-risk-engine-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_risk_engine_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/risk_engine_lib.php';
require_once $apiDir . '/trust_intelligence_lib.php';
require_once $apiDir . '/trust_signal_runtime_lib.php';
require_once $apiDir . '/context_resolver_lib.php';
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

/** @return bool */
function observation_has_id(array $obsPkg, string $obsId): bool
{
    foreach ($obsPkg['observations'] ?? [] as $row) {
        if (is_array($row) && ($row['observation_id'] ?? '') === $obsId) {
            return true;
        }
    }
    return false;
}

/** @return array<string,mixed>|null */
function observation_find(array $obsPkg, string $obsId): ?array
{
    foreach ($obsPkg['observations'] ?? [] as $row) {
        if (is_array($row) && ($row['observation_id'] ?? '') === $obsId) {
            return $row;
        }
    }
    return null;
}

/**
 * Risk Engine alt katman çıktıları ile birebir tutarlılık.
 */
function assert_engine_layers_match(string $roomId, ?string $actorId, array $engine): void
{
    $metrics = zinesh_trust_intelligence_metrics($roomId);
    $signals = zinesh_trust_signal_runtime_for_room($roomId, $actorId);
    $contexts = zinesh_context_resolver_for_room($roomId, $actorId);
    $observations = zinesh_risk_observation_runtime_for_room($roomId, $actorId);

    assert_test('engine metrics match trust intelligence', json_encode($engine['metrics'] ?? null) === json_encode($metrics));
    assert_test('engine signals match signal runtime', json_encode($engine['signals'] ?? null) === json_encode($signals));
    assert_test('engine contexts match context resolver', json_encode($engine['contexts'] ?? null) === json_encode($contexts));
    assert_test('engine observations match risk observation runtime', json_encode($engine['observations'] ?? null) === json_encode($observations));
}

$employerUid = 'emp-re-1';
$workerUid = 'wrk-re-1';

echo "=== Boş oda ===\n";
zinesh_json_write('escrow_rooms.json', []);
$emptyEngine = zinesh_risk_engine_for_room('room-re-missing');
assert_test('risk engine version set', ($emptyEngine['risk_engine_version'] ?? '') === '0.1');
assert_test('empty room observations count 0', count($emptyEngine['observations']['observations'] ?? []) === 0);
assert_engine_layers_match('room-re-missing', null, $emptyEngine);

echo "=== İlk işlem ===\n";
$firstRoom = 'room-re-first';
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
$firstEngine = zinesh_risk_engine_for_room($firstRoom, $employerUid);
assert_test('first transaction observation via engine', observation_has_id($firstEngine['observations'] ?? [], 'OBS-HIS-002'));
assert_engine_layers_match($firstRoom, $employerUid, $firstEngine);

echo "=== Yüksek revizyon ===\n";
$revRoom = 'room-re-rev';
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
$revEngine = zinesh_risk_engine_for_room($revRoom);
assert_test('high revision observation via engine', observation_has_id($revEngine['observations'] ?? [], 'OBS-REV-001'));
assert_engine_layers_match($revRoom, null, $revEngine);

echo "=== Uzun müzakere ===\n";
$longNegRoom = 'room-re-longneg';
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
    ['id' => 'evt-re-1', 'room_id' => $longNegRoom, 'event_type' => 'terms_proposed', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-01T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-re-2', 'room_id' => $longNegRoom, 'event_type' => 'counter_offer_created', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-05T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-re-3', 'room_id' => $longNegRoom, 'event_type' => 'counter_offer_created', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-10T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-re-4', 'room_id' => $longNegRoom, 'event_type' => 'counter_offer_created', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-12T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-re-5', 'room_id' => $longNegRoom, 'event_type' => 'terms_accepted', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-15T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-re-6', 'room_id' => $longNegRoom, 'event_type' => 'escrow_locked', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-20T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
]);
$longNegEngine = zinesh_risk_engine_for_room($longNegRoom);
assert_test('high negotiation observation via engine', observation_has_id($longNegEngine['observations'] ?? [], 'OBS-NEG-001'));
assert_engine_layers_match($longNegRoom, null, $longNegEngine);

echo "=== Dispute ===\n";
$dspRoom = 'room-re-dsp';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $dspRoom,
    'status' => 'disputed',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Dispute test',
    'createdAt' => '2026-08-01T10:00:00+03:00',
]]);
zinesh_domain_event_emit($dspRoom, 'dispute_opened', $workerUid, 'worker', [], ['idempotency_key' => $dspRoom . ':dsp']);
$dspEngine = zinesh_risk_engine_for_room($dspRoom);
assert_test('settlement instability observation via engine', observation_has_id($dspEngine['observations'] ?? [], 'OBS-SET-001'));
assert_engine_layers_match($dspRoom, null, $dspEngine);

echo "=== Alt runtime katmanları değişmez ===\n";
$sigBefore = zinesh_trust_signal_runtime_for_room($longNegRoom);
$ctxBefore = zinesh_context_resolver_for_room($longNegRoom);
$obsBefore = zinesh_risk_observation_runtime_for_room($longNegRoom);
$metricsBefore = zinesh_trust_intelligence_metrics($longNegRoom);
zinesh_risk_engine_for_room($longNegRoom);
$sigAfter = zinesh_trust_signal_runtime_for_room($longNegRoom);
$ctxAfter = zinesh_context_resolver_for_room($longNegRoom);
$obsAfter = zinesh_risk_observation_runtime_for_room($longNegRoom);
$metricsAfter = zinesh_trust_intelligence_metrics($longNegRoom);
assert_test('trust metrics unchanged by risk engine', json_encode($metricsBefore) === json_encode($metricsAfter));
assert_test('signal runtime unchanged by risk engine', json_encode($sigBefore) === json_encode($sigAfter));
assert_test('context resolver unchanged by risk engine', json_encode($ctxBefore) === json_encode($ctxAfter));
assert_test('risk observation runtime unchanged by risk engine', json_encode($obsBefore) === json_encode($obsAfter));

echo "=== Explainability korunuyor ===\n";
$negObs = observation_find($longNegEngine['observations'] ?? [], 'OBS-NEG-001');
$explainOk = is_array($negObs)
    && ($negObs['explainability']['chain'] ?? '') === 'risk_observation → context → signal → metric → event'
    && ($negObs['evidence_chain']['chain'] ?? '') === 'risk_observation → context → signal → metric → event'
    && is_array($negObs['contexts_used'] ?? null)
    && is_array($negObs['signals_used'] ?? null)
    && is_array($negObs['metrics_used'] ?? null)
    && ($negObs['confidence'] ?? '') !== '';
assert_test('observation explainability preserved in engine', $explainOk);

$signalList = is_array($longNegEngine['signals']['signals'] ?? null) ? $longNegEngine['signals']['signals'] : [];
$signalExplainOk = false;
foreach ($signalList as $sig) {
    if (!is_array($sig)) {
        continue;
    }
    if (($sig['explanation']['chain'] ?? '') === 'signal → metric → event') {
        $signalExplainOk = true;
        break;
    }
}
assert_test('signal explainability preserved in engine', $signalExplainOk);

echo "=== Determinism ===\n";
$det1 = zinesh_risk_engine_for_room($longNegRoom);
$det2 = zinesh_risk_engine_for_room($longNegRoom);
$normalizeEngine = static function (array $pkg): array {
    if (isset($pkg['metrics']) && is_array($pkg['metrics'])) {
        unset($pkg['metrics']['generated_at']);
    }
    return $pkg;
};
assert_test('determinism identical engine package', json_encode($normalizeEngine($det1)) === json_encode($normalizeEngine($det2)));

echo "=== Replayability ===\n";
$rep1 = zinesh_risk_engine_for_room($revRoom);
$rep2 = zinesh_risk_engine_for_room($revRoom);
$ids1 = zinesh_risk_observation_runtime_observation_ids($rep1['observations'] ?? []);
$ids2 = zinesh_risk_observation_runtime_observation_ids($rep2['observations'] ?? []);
assert_test('replay same observation ids via engine', $ids1 === $ids2);

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
