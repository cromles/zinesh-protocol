<?php
declare(strict_types=1);

/**
 * S1.1: Aynı employer için eşzamanlı yalnızca bir settling oda.
 * php api/scripts/e2e-inv-s1-1-employer-settlement-guard-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_s1_1_guard_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_room_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('zinesh_events.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('escrow_room_messages.json', []);

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

$employerUid = 'emp-s1-1';
$employerOther = 'emp-s1-1-other';
$workerA = 'wrk-s1-1a';
$workerB = 'wrk-s1-1b';
$workerC = 'wrk-s1-1c';
$roomSettling = 'room-s1-1-settling';
$roomBlocked = 'room-s1-1-blocked';
$roomOtherEmployer = 'room-s1-1-other-emp';

zinesh_json_write('users.json', [
    ['uid' => $employerUid, 'email' => 'emp@s1-1.test', 'usdtBalance' => 20000.0, 'escrowBalance' => 2000.0],
    ['uid' => $employerOther, 'email' => 'other@s1-1.test', 'usdtBalance' => 5000.0, 'escrowBalance' => 500.0],
    ['uid' => $workerA, 'email' => 'wa@s1-1.test', 'usdtBalance' => 0.0, 'escrowBalance' => 0.0],
    ['uid' => $workerB, 'email' => 'wb@s1-1.test', 'usdtBalance' => 0.0, 'escrowBalance' => 0.0],
    ['uid' => $workerC, 'email' => 'wc@s1-1.test', 'usdtBalance' => 0.0, 'escrowBalance' => 0.0],
]);

zinesh_json_write('escrow_rooms.json', [
    [
        'id' => $roomSettling,
        'status' => 'settling',
        'settlingAt' => date('c'),
        'settlingPreviousStatus' => 'locked',
        'employerUid' => $employerUid,
        'workerUid' => $workerA,
        'employerLockedTry' => 1000.0,
        'agreedAmountTry' => 1000.0,
    ],
    [
        'id' => $roomBlocked,
        'status' => 'locked',
        'employerUid' => $employerUid,
        'workerUid' => $workerB,
        'employerLockedTry' => 800.0,
        'agreedAmountTry' => 800.0,
        'employerConfirmedComplete' => true,
        'workerConfirmedComplete' => true,
    ],
    [
        'id' => $roomOtherEmployer,
        'status' => 'locked',
        'employerUid' => $employerOther,
        'workerUid' => $workerC,
        'employerLockedTry' => 500.0,
        'agreedAmountTry' => 500.0,
        'employerConfirmedComplete' => true,
        'workerConfirmedComplete' => true,
    ],
]);

echo "=== S1.1: aynı employer, başka oda settling iken claim reddedilmeli ===\n";

$resultBlocked = zinesh_escrow_room_finalize_success($roomBlocked, $employerUid);
$roomAfterBlocked = null;
foreach (zinesh_json_read('escrow_rooms.json') as $row) {
    if ((string)($row['id'] ?? '') === $roomBlocked) {
        $roomAfterBlocked = $row;
        break;
    }
}

assert_test('finalize ikinci oda ok:false', ($resultBlocked['ok'] ?? true) === false);
assert_test('ikinci oda settling olmamalı', ($roomAfterBlocked['status'] ?? '') === 'locked');
assert_test('birinci oda hâlâ settling', true); // doğrulanacak aşağıda

$roomStillSettling = null;
foreach (zinesh_json_read('escrow_rooms.json') as $row) {
    if ((string)($row['id'] ?? '') === $roomSettling) {
        $roomStillSettling = $row;
        break;
    }
}
assert_test('birinci oda settling korunur', ($roomStillSettling['status'] ?? '') === 'settling');

echo "=== S1.1: farklı employer settling engeli tetiklemez ===\n";

$resultOther = zinesh_escrow_room_finalize_success($roomOtherEmployer, $employerOther);
$roomOtherAfter = null;
foreach (zinesh_json_read('escrow_rooms.json') as $row) {
    if ((string)($row['id'] ?? '') === $roomOtherEmployer) {
        $roomOtherAfter = $row;
        break;
    }
}

assert_test('farklı employer finalize ok:true', ($resultOther['ok'] ?? false) === true);
assert_test('farklı employer oda completed', ($roomOtherAfter['status'] ?? '') === 'completed');

echo "=== S1.1: settling oda bitince aynı employer ikinci oda finalize edebilir ===\n";

zinesh_json_write('escrow_rooms.json', array_map(static function (array $row) use ($roomSettling) {
    if ((string)($row['id'] ?? '') === $roomSettling) {
        $row['status'] = 'completed';
        $row['completedAt'] = date('c');
        unset($row['settlingAt'], $row['settlingPreviousStatus']);
    }
    return $row;
}, zinesh_json_read('escrow_rooms.json')));

$resultAfterFirstDone = zinesh_escrow_room_finalize_success($roomBlocked, $employerUid);
$roomBlockedFinal = null;
foreach (zinesh_json_read('escrow_rooms.json') as $row) {
    if ((string)($row['id'] ?? '') === $roomBlocked) {
        $roomBlockedFinal = $row;
        break;
    }
}

assert_test('ilk oda completed sonrası ikinci finalize ok:true', ($resultAfterFirstDone['ok'] ?? false) === true);
assert_test('ikinci oda completed', ($roomBlockedFinal['status'] ?? '') === 'completed');

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
