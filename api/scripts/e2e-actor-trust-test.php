<?php
declare(strict_types=1);

/**
 * Actor Trust Aggregation v0.1 smoke test (izole sim data).
 * php api/scripts/e2e-actor-trust-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_actor_trust_test_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/actor_trust_lib.php';

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

$actorId = 'actor-agg-1';
$peerId = 'peer-agg-1';
$roomA = 'room-agg-a';
$roomB = 'room-agg-b';

echo "=== Hiç işlem olmayan kullanıcı ===\n";
$empty = zinesh_actor_trust_aggregate('actor-no-history');
assert_test('empty actor transactions 0', ($empty['metrics']['transactions'] ?? -1) === 0);
assert_test('empty actor fallback version', ($empty['trust_version'] ?? '') === '0.1');

echo "=== Çoklu oda aggregation ===\n";
zinesh_json_write('escrow_rooms.json', [
    [
        'id' => $roomA,
        'status' => 'completed',
        'employerUid' => $actorId,
        'workerUid' => $peerId,
        'createdAt' => '2026-08-01T10:00:00+03:00',
        'lockedAt' => '2026-08-01T11:00:00+03:00',
        'completedAt' => '2026-08-01T12:00:00+03:00',
    ],
    [
        'id' => $roomB,
        'status' => 'disputed',
        'employerUid' => $peerId,
        'workerUid' => $actorId,
        'createdAt' => '2026-08-02T09:00:00+03:00',
    ],
]);

$roomPayload = [
    'title' => 'Proje',
    'description' => str_repeat('Sözleşme metni uzun. ', 10),
    'agreedAmountTry' => 1000.0,
    'employerUid' => $actorId,
    'workerUid' => $peerId,
];
$roomPayload['id'] = $roomA;
zinesh_escrow_memory_on_terms_version($roomPayload, ['uid' => $actorId], 'employer', 'propose');
zinesh_escrow_memory_on_terms_accepted(
    array_merge($roomPayload, ['employerLockedTry' => 1000, 'lockedAt' => '2026-08-01T11:00:00+03:00']),
    ['uid' => $peerId],
    'worker'
);
zinesh_domain_event_emit(
    $roomA,
    'settlement_completed',
    'system',
    'system',
    ['duration_sec' => 3600],
    ['idempotency_key' => $roomA . ':agg:settle:a']
);

$roomPayload['id'] = $roomB;
$roomPayload['employerUid'] = $peerId;
$roomPayload['workerUid'] = $actorId;
zinesh_escrow_memory_on_terms_version($roomPayload, ['uid' => $peerId], 'employer', 'propose');
zinesh_escrow_memory_on_changes_requested($roomPayload, ['uid' => $actorId], 'Daha fazla revizyon istiyorum');
zinesh_domain_event_emit(
    $roomB,
    'dispute_opened',
    $actorId,
    'worker',
    ['deposit_try' => 10],
    ['idempotency_key' => $roomB . ':agg:dispute']
);

$agg = zinesh_actor_trust_aggregate($actorId);

assert_test('two transactions', ($agg['metrics']['transactions'] ?? 0) === 2);
assert_test('successful settlements >= 1', ($agg['metrics']['successful_settlements'] ?? 0) >= 1);
assert_test('disputes counted', ($agg['metrics']['disputes'] ?? 0) >= 1);
assert_test('avg completion >= 0', ($agg['metrics']['history']['average_completion_time'] ?? -1) >= 0);
assert_test('avg negotiation rounds >= 0', ($agg['metrics']['history']['average_negotiation_rounds'] ?? -1) >= 0);
assert_test('behavior cooperation in range', ($agg['metrics']['behavior']['cooperation'] ?? -1) >= 0
    && ($agg['metrics']['behavior']['cooperation'] ?? 101) <= 100);
assert_test('actor_id set', ($agg['actor_id'] ?? '') === $actorId);

echo "=== Eski oda (event yok) ===\n";
$legacyId = 'room-legacy';
zinesh_json_write('escrow_rooms.json', array_merge(
    zinesh_json_read('escrow_rooms.json'),
    [[
        'id' => $legacyId,
        'status' => 'completed',
        'employerUid' => $actorId,
        'workerUid' => $peerId,
        'completedAt' => '2025-01-01T10:00:00+03:00',
    ]]
));
$legacyAgg = zinesh_actor_trust_aggregate($actorId);
assert_test('legacy room included without error', ($legacyAgg['metrics']['transactions'] ?? 0) >= 3);

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
