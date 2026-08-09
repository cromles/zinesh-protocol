<?php
declare(strict_types=1);

/**
 * Escrow memory / event sourcing hazırlık testleri (izole sim data).
 * php api/scripts/e2e-escrow-memory-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_escrow_memory_test_' . getmypid();
if (!is_dir($simDir)) {
    mkdir($simDir, 0750, true);
}
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('zinesh_events.json', []);
zinesh_json_write('contract_versions.json', []);

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

$roomId = 'room-test-' . substr(bin2hex(random_bytes(4)), 0, 8);
$employerUid = 'emp-' . bin2hex(random_bytes(4));
$workerUid = 'wrk-' . bin2hex(random_bytes(4));

$employer = ['uid' => $employerUid, 'name' => 'Test Employer'];
$worker = ['uid' => $workerUid, 'name' => 'Test Worker'];

echo "=== Teklif akışı ===\n";
$room = [
    'id' => $roomId,
    'title' => 'Web sitesi',
    'description' => str_repeat('Responsive web sitesi teslimi. ', 8),
    'agreedAmountTry' => 5000.0,
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
];

zinesh_escrow_memory_on_terms_version($room, $employer, 'employer', 'propose');
$versions = zinesh_contract_versions_for_room($roomId);
$events = zinesh_domain_events_for_room($roomId);

assert_test('version 1 oluşur', count($versions) === 1 && (int)($versions[0]['version_number'] ?? 0) === 1);
assert_test('contract_created event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'contract_created')) === 1);
assert_test('terms_proposed event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'terms_proposed')) === 1);

echo "=== Counter offer ===\n";
$room['title'] = '5 sayfalı responsive web sitesi';
$room['description'] = str_repeat('5 sayfa, 2 revizyon, 15 Ağustos teslim. ', 6);
$room['agreedAmountTry'] = 6000.0;
$room['termsProposedBy'] = 'worker';

zinesh_escrow_memory_on_terms_version($room, $worker, 'worker', 'counter_offer', ['reason' => 'scope_change']);
$versions = zinesh_contract_versions_for_room($roomId);
$events = zinesh_domain_events_for_room($roomId);

assert_test('version 2 oluşur', count($versions) === 2);
assert_test('counter_offer_created event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'counter_offer_created')) === 1);
assert_test('terms_updated event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'terms_updated')) >= 1);

echo "=== Kabul + escrow ===\n";
$room['employerLockedTry'] = 6000.0;
$room['lockedAt'] = date('c');
$room['status'] = 'locked';

zinesh_escrow_memory_on_terms_accepted($room, $worker, 'worker');
$events = zinesh_domain_events_for_room($roomId);
$accepted = zinesh_contract_version_accepted($roomId);

assert_test('terms_accepted event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'terms_accepted')) === 1);
assert_test('contract_finalized event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'contract_finalized')) === 1);
assert_test('escrow_funded event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'escrow_funded')) === 1);
assert_test('escrow_locked event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'escrow_locked')) === 1);
assert_test('accepted_at set on version', $accepted !== null && (string)($accepted['accepted_at'] ?? '') !== '');

echo "=== Dispute ===\n";
$room['dispute'] = ['filedAt' => date('c')];
zinesh_escrow_memory_on_dispute_opened($room, $employer, 60.0, 'employer_locked');
$events = zinesh_domain_events_for_room($roomId);

assert_test('dispute_opened event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'dispute_opened')) === 1);
assert_test('dispute_deposit_paid event', count(array_filter($events, static fn($e) => ($e['event_type'] ?? '') === 'dispute_deposit_paid')) === 1);

echo "=== Idempotency ===\n";
$before = count(zinesh_domain_events_for_room($roomId));
zinesh_escrow_memory_on_terms_accepted($room, $worker, 'worker');
$after = count(zinesh_domain_events_for_room($roomId));
assert_test('terms_accepted idempotent (no duplicate)', $before === $after);

echo "=== Timeline API ===\n";
$timeline = zinesh_escrow_room_timeline($roomId);
assert_test('timeline has entries', count($timeline) > 0);
assert_test('timeline entry shape', isset($timeline[0]['event'], $timeline[0]['description'], $timeline[0]['date']));

$ctx = zinesh_escrow_room_ai_context($roomId);
assert_test('ai context versions', count($ctx['contract_versions'] ?? []) === 2);
assert_test('ai context counter_offer stat', (int)($ctx['behavior_stats']['counter_offer_count'] ?? 0) >= 1);

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
echo "Sim data: {$simDir}\n";

exit($failed > 0 ? 1 : 0);
