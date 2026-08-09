<?php
declare(strict_types=1);

/**
 * Trust Intelligence v0.1 smoke test (izole sim data).
 * php api/scripts/e2e-trust-intelligence-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_trust_intel_test_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/trust_intelligence_lib.php';

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

$roomId = 'room-trust-' . substr(bin2hex(random_bytes(3)), 0, 6);
$employerUid = 'emp-trust-1';
$workerUid = 'wrk-trust-1';

echo "=== Boş / eski oda ===\n";
$emptyId = 'room-empty-trust';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $emptyId,
    'status' => 'completed',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'completedAt' => '2026-01-02T12:00:00+03:00',
]]);

$emptyMetrics = zinesh_trust_intelligence_metrics($emptyId);
assert_test('empty room returns metrics', is_array($emptyMetrics['metrics'] ?? null));
assert_test('empty room trust_version', ($emptyMetrics['trust_version'] ?? '') === '0.1');
assert_test('empty negotiation defaults', ($emptyMetrics['metrics']['negotiation']['negotiation_rounds'] ?? -1) === 0);
assert_test('empty behavior signals zero', ($emptyMetrics['metrics']['behavior']['conflict_signal'] ?? -1) === 0);

echo "=== Müzakere senaryosu ===\n";
zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'terms_pending',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Web',
    'description' => str_repeat('Sözleşme metni uzun. ', 10),
    'agreedAmountTry' => 3000.0,
    'createdAt' => '2026-08-07T09:00:00+03:00',
]]);

$room = [
    'id' => $roomId,
    'title' => 'Web',
    'description' => str_repeat('Sözleşme metni uzun. ', 10),
    'agreedAmountTry' => 3000.0,
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
];
zinesh_escrow_memory_on_terms_version($room, ['uid' => $employerUid], 'employer', 'propose');
zinesh_escrow_memory_on_changes_requested($room, ['uid' => $workerUid], 'Kapsam genişlemeli');
$room['agreedAmountTry'] = 3500.0;
zinesh_escrow_memory_on_terms_version($room, ['uid' => $workerUid], 'worker', 'counter_offer', ['reason' => 'scope_change']);

$metrics = zinesh_trust_intelligence_metrics($roomId);
$neg = $metrics['metrics']['negotiation'] ?? [];

assert_test('terms proposed counted', ($neg['terms_proposed_count'] ?? 0) >= 1);
assert_test('changes requested counted', ($neg['changes_requested_count'] ?? 0) === 1);
assert_test('counter offer counted', ($neg['counter_offer_count'] ?? 0) === 1);
assert_test('negotiation rounds > 0', ($neg['negotiation_rounds'] ?? 0) > 0);
assert_test('cooperation signal in range', ($metrics['metrics']['behavior']['cooperation_signal'] ?? -1) >= 0
    && ($metrics['metrics']['behavior']['cooperation_signal'] ?? 101) <= 100);
assert_test('contract creation time set', ($metrics['metrics']['time']['contract_creation_time'] ?? '') !== '');

echo "=== Tamamlanma senaryosu ===\n";
zinesh_domain_event_emit(
    $roomId,
    'settlement_completed',
    'system',
    'system',
    ['amount_try' => 3500, 'currency' => 'TRY'],
    ['idempotency_key' => $roomId . ':test:settlement']
);
zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'completed',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'completedAt' => '2026-08-07T12:00:00+03:00',
    'lockedAt' => '2026-08-07T10:00:00+03:00',
    'createdAt' => '2026-08-07T09:00:00+03:00',
]]);

$done = zinesh_trust_intelligence_metrics($roomId);
$settlement = $done['metrics']['settlement'] ?? [];

assert_test('successful settlement true', ($settlement['successful_settlement'] ?? false) === true);
assert_test('completion status completed', ($settlement['completion_status'] ?? '') === 'completed');
assert_test('duration_seconds >= 0', ($done['metrics']['time']['duration_seconds'] ?? -1) >= 0);

echo "=== Geçersiz room id ===\n";
$invalid = zinesh_trust_intelligence_metrics('');
assert_test('empty room_id safe defaults', ($invalid['metrics']['settlement']['completion_status'] ?? '') === 'unknown');

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
