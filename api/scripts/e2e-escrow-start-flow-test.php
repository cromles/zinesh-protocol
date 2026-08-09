<?php
declare(strict_types=1);

/**
 * Escrow başlatma akışı: connect → propose → accept → locked
 * php api/scripts/e2e-escrow-start-flow-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_escrow_start_' . getmypid();
if (!is_dir($simDir)) {
    mkdir($simDir, 0750, true);
}
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/verification_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('escrow_rooms.json', []);
zinesh_json_write('escrow_room_messages.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('zinesh_events.json', []);

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

$employerUid = 'emp-' . bin2hex(random_bytes(4));
$workerUid = 'wrk-' . bin2hex(random_bytes(4));

$employer = [
    'uid' => $employerUid,
    'email' => 'employer@test.local',
    'name' => 'Employer A',
    'emailVerified' => true,
    'ticketNumber' => '11111',
    'usdtBalance' => 10000.0,
    'escrowBalance' => 0.0,
];
$worker = [
    'uid' => $workerUid,
    'email' => 'worker@test.local',
    'name' => 'Worker B',
    'emailVerified' => true,
    'ticketNumber' => '22222',
    'usdtBalance' => 5000.0,
    'escrowBalance' => 0.0,
];

zinesh_json_write('users.json', [$employer, $worker]);

echo "=== Connect ===\n";
$connect = zinesh_escrow_room_connect($employer, '22222', 'employer');
assert_test('connect ok', !empty($connect['ok']));
$roomId = (string)($connect['room']['id'] ?? '');
assert_test('room created', $roomId !== '');

echo "=== Propose ===\n";
$desc = str_repeat('Responsive web sitesi teslimi ve bakım. ', 4);
$propose = zinesh_escrow_room_propose_terms($employer, $roomId, 3000.0, 'Web sitesi', $desc, false);
assert_test('propose ok', !empty($propose['ok']));
assert_test('terms_pending', ($propose['room']['status'] ?? '') === 'terms_pending');

echo "=== Accept (worker) ===\n";
$accept = zinesh_escrow_room_accept_terms($worker, $roomId, false);
assert_test('accept ok', !empty($accept['ok']));
assert_test('locked status', ($accept['room']['status'] ?? '') === 'locked');
assert_test('employer locked amount', (float)($accept['room']['employerLockedTry'] ?? 0) === 3000.0);

$employerAfter = zinesh_find_user_by_uid($employerUid);
assert_test('employer escrow funded', $employerAfter && (float)$employerAfter['escrowBalance'] === 3000.0);

echo "=== Abandoned locking recovery ===\n";
// Simulate stuck locking (no fund flags)
zinesh_json_atomic('escrow_rooms.json', static function (array &$rows) use ($roomId) {
    foreach ($rows as $i => $row) {
        if ((string)($row['id'] ?? '') === $roomId) {
            $rows[$i]['status'] = 'locking';
            $rows[$i]['lockingAt'] = date('c');
            unset($rows[$i]['lockingEmployerFunded'], $rows[$i]['lockingWorkerCollateralFunded']);
            return true;
        }
    }
    return false;
});
$recovered = zinesh_escrow_room_recover_stale_transient_if_needed($roomId);
assert_test('abandoned locking recovered', !empty($recovered['recovered']));
$roomAfter = zinesh_escrow_room_find($roomId);
assert_test('back to terms_pending', ($roomAfter['status'] ?? '') === 'terms_pending');

echo "=== Insufficient balance does not trap locking ===\n";
zinesh_update_user($employerUid, static function (array &$u) {
    $u['usdtBalance'] = 100.0;
    $u['escrowBalance'] = 0.0;
});
zinesh_json_atomic('escrow_rooms.json', static function (array &$rows) use ($roomId) {
    foreach ($rows as $i => $row) {
        if ((string)($row['id'] ?? '') === $roomId) {
            $rows[$i]['status'] = 'terms_pending';
            $rows[$i]['agreedAmountTry'] = 5000.0;
            return true;
        }
    }
    return false;
});
$failAccept = zinesh_escrow_room_accept_terms($worker, $roomId, false);
assert_test('accept fails on low balance', empty($failAccept['ok']));
$roomStuck = zinesh_escrow_room_find($roomId);
assert_test('room not stuck in locking', ($roomStuck['status'] ?? '') === 'terms_pending');

echo "\n=== Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
