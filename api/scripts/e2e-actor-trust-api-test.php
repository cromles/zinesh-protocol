<?php
declare(strict_types=1);

/**
 * Actor Trust API smoke test (izole sim data).
 * php api/scripts/e2e-actor-trust-api-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_actor_trust_api_test_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/actor_trust_endpoint_lib.php';

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

$actorUid = 'actor-api-1';
$peerUid = 'peer-api-1';
$outsiderUid = 'outsider-api-1';
$roomId = 'room-api-1';

echo "=== Erişim kontrolü ===\n";
assert_test('self access without actor_id', zinesh_actor_trust_can_access_self('', $actorUid));
assert_test('self access with matching actor_id', zinesh_actor_trust_can_access_self($actorUid, $actorUid));
assert_test('other actor blocked', !zinesh_actor_trust_can_access_self($peerUid, $actorUid));
assert_test('empty session blocked', !zinesh_actor_trust_can_access_self('', ''));

echo "=== Kendi verisi ===\n";
zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'completed',
    'employerUid' => $actorUid,
    'workerUid' => $peerUid,
    'createdAt' => '2026-08-01T10:00:00+03:00',
    'lockedAt' => '2026-08-01T11:00:00+03:00',
    'completedAt' => '2026-08-01T12:00:00+03:00',
]]);

$room = [
    'id' => $roomId,
    'title' => 'Proje',
    'description' => str_repeat('Sözleşme metni uzun. ', 10),
    'agreedAmountTry' => 1500.0,
    'employerUid' => $actorUid,
    'workerUid' => $peerUid,
];
zinesh_escrow_memory_on_terms_version($room, ['uid' => $actorUid], 'employer', 'propose');
zinesh_escrow_memory_on_terms_accepted(
    array_merge($room, ['employerLockedTry' => 1500, 'lockedAt' => '2026-08-01T11:00:00+03:00']),
    ['uid' => $peerUid],
    'worker'
);
zinesh_domain_event_emit(
    $roomId,
    'settlement_completed',
    'system',
    'system',
    ['duration_sec' => 1800],
    ['idempotency_key' => $roomId . ':api:settle']
);

$raw = zinesh_actor_trust_aggregate($actorUid);
$public = zinesh_actor_trust_public_response($raw);

assert_test('ok true', ($public['ok'] ?? false) === true);
assert_test('trust_version 0.1', ($public['trust_version'] ?? '') === '0.1');
assert_test('actor_id matches session', ($public['actor_id'] ?? '') === $actorUid);
assert_test('transactions >= 1', ($public['metrics']['transactions'] ?? 0) >= 1);
assert_test('metrics behavior present', is_array($public['metrics']['behavior'] ?? null));
assert_test('metrics history present', is_array($public['metrics']['history'] ?? null));
assert_test('no room ids leaked', !isset($public['room_ids']) && !isset($public['metrics']['rooms']));
assert_test('no events leaked', !isset($public['events']) && !isset($public['metrics']['events']));
assert_test('no metadata leaked', !isset($public['metadata']));
assert_test('no peer uid leaked', !isset($public['peerUid']) && !isset($public['workerUid']));

echo "=== Başkasının verisi engeli ===\n";
$blocked = !zinesh_actor_trust_can_access_self($outsiderUid, $actorUid);
assert_test('403 scenario: cannot query other actor_id', $blocked);

echo "=== Eski / boş kullanıcı ===\n";
$empty = zinesh_actor_trust_public_response(zinesh_actor_trust_aggregate('legacy-user-no-rooms'));
assert_test('legacy user ok', ($empty['ok'] ?? false) === true);
assert_test('legacy user zero transactions', ($empty['metrics']['transactions'] ?? -1) === 0);
assert_test('legacy user zero behavior', ($empty['metrics']['behavior']['cooperation'] ?? -1) === 0.0);

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
