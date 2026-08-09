<?php
declare(strict_types=1);

/**
 * INV-A4 regression: wallet commit sonrası TTL recovery settling_revert yapmamalı.
 * php api/scripts/e2e-inv-a4-settlement-recovery-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_inv_a4_test_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_room_lib.php';

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

$roomId = 'room-inv-a4-' . substr(bin2hex(random_bytes(3)), 0, 6);
$employerUid = 'emp-inv-a4';
$workerUid = 'wrk-inv-a4';
$lockAmount = 1000.0;
$commission = round($lockAmount * ZINESH_ESCROW_COMMISSION_RATE, 2);
$payout = round($lockAmount - $commission, 2);
$employerUsdtBefore = 10000.0;
$employerEscrowBefore = 1000.0;

zinesh_json_write('users.json', [
    [
        'uid' => $employerUid,
        'email' => 'emp-inv-a4@test.local',
        'usdtBalance' => $employerUsdtBefore,
        'escrowBalance' => $employerEscrowBefore,
    ],
    [
        'uid' => $workerUid,
        'email' => 'wrk-inv-a4@test.local',
        'usdtBalance' => 0.0,
        'escrowBalance' => 0.0,
    ],
]);

echo "=== INV-A4: wallet commit + crash before flag + TTL recovery ===\n";

zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'settling',
    'settlingAt' => date('c', time() - 700),
    'settlingPreviousStatus' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'employerLockedTry' => $lockAmount,
    'agreedAmountTry' => $lockAmount,
    'settlingSnapshotEmployerEscrow' => $employerEscrowBefore,
    'settlingSnapshotEmployerUsdt' => $employerUsdtBefore,
    'settlingSnapshotLockTry' => $lockAmount,
]]);

zinesh_wallet_apply_tl_escrow_settlement($employerUid, $workerUid, $lockAmount, $payout, 0.0);

$employerAfterSettle = zinesh_find_user_by_uid($employerUid);
$workerAfterSettle = zinesh_find_user_by_uid($workerUid);
assert_test('wallet settlement committed (employer usdt)', round((float)($employerAfterSettle['usdtBalance'] ?? 0), 2) === round($employerUsdtBefore - $lockAmount, 2));
assert_test('wallet settlement committed (worker credit)', round((float)($workerAfterSettle['usdtBalance'] ?? 0), 2) === $payout);

$roomBeforeRecovery = null;
foreach (zinesh_json_read('escrow_rooms.json') as $row) {
    if ((string)($row['id'] ?? '') === $roomId) {
        $roomBeforeRecovery = $row;
        break;
    }
}
assert_test('room still settling before recovery', ($roomBeforeRecovery['status'] ?? '') === 'settling');
assert_test('settlingWalletApplied not set (crash sim)', empty($roomBeforeRecovery['settlingWalletApplied']));

$recovery = zinesh_escrow_room_recover_stale_transient_if_needed($roomId);
$roomAfterRecovery = zinesh_escrow_room_find($roomId);

assert_test('recovery did not settling_revert', ($recovery['action'] ?? '') !== 'settling_revert');
assert_test('room completed after recovery', ($roomAfterRecovery['status'] ?? '') === 'completed');

$employerAfterRecovery = zinesh_find_user_by_uid($employerUid);
$workerAfterRecovery = zinesh_find_user_by_uid($workerUid);
assert_test('employer wallet unchanged after recovery', round((float)($employerAfterRecovery['usdtBalance'] ?? 0), 2) === round((float)($employerAfterSettle['usdtBalance'] ?? 0), 2));
assert_test('worker wallet unchanged after recovery', round((float)($workerAfterRecovery['usdtBalance'] ?? 0), 2) === round((float)($workerAfterSettle['usdtBalance'] ?? 0), 2));

zinesh_escrow_room_recover_stale_transient_if_needed($roomId);
$employerSecondPass = zinesh_find_user_by_uid($employerUid);
$workerSecondPass = zinesh_find_user_by_uid($workerUid);
assert_test('no double debit on second recovery', round((float)($employerSecondPass['usdtBalance'] ?? 0), 2) === round((float)($employerAfterSettle['usdtBalance'] ?? 0), 2));
assert_test('no double credit on second recovery', round((float)($workerSecondPass['usdtBalance'] ?? 0), 2) === round((float)($workerAfterSettle['usdtBalance'] ?? 0), 2));

echo "=== INV-A4: wallet not committed — settling_revert still allowed ===\n";

$roomId2 = 'room-inv-a4b-' . substr(bin2hex(random_bytes(3)), 0, 6);
zinesh_json_write('users.json', array_merge(zinesh_json_read('users.json'), [[
    'uid' => 'emp-inv-a4b',
    'email' => 'emp-b@test.local',
    'usdtBalance' => 5000.0,
    'escrowBalance' => 500.0,
]]));
zinesh_json_write('escrow_rooms.json', array_merge(zinesh_json_read('escrow_rooms.json'), [[
    'id' => $roomId2,
    'status' => 'settling',
    'settlingAt' => date('c', time() - 700),
    'settlingPreviousStatus' => 'locked',
    'employerUid' => 'emp-inv-a4b',
    'workerUid' => $workerUid,
    'employerLockedTry' => 500.0,
    'settlingSnapshotEmployerEscrow' => 500.0,
    'settlingSnapshotEmployerUsdt' => 5000.0,
    'settlingSnapshotLockTry' => 500.0,
]]));

$recoveryB = zinesh_escrow_room_recover_stale_transient_if_needed($roomId2);
$roomB = zinesh_escrow_room_find($roomId2);
assert_test('no wallet commit → settling_revert', ($recoveryB['action'] ?? '') === 'settling_revert');
assert_test('room back to locked', ($roomB['status'] ?? '') === 'locked');

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
