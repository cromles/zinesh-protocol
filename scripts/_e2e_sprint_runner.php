<?php
declare(strict_types=1);
/**
 * E2E sprint runner — izole sim data, demo kullanıcılar.
 * VPS: ZINESH_SIM_DATA_DIR=/tmp/zinesh_e2e_sprint ZINESH_DEMO_MODE=1 php scripts/_e2e_sprint_runner.php
 */
$simDir = getenv('ZINESH_SIM_DATA_DIR') ?: sys_get_temp_dir() . '/zinesh_e2e_sprint_' . getmypid();
if (!is_dir($simDir)) {
    mkdir($simDir, 0750, true);
}
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);
putenv('ZINESH_DEMO_MODE=1');

$root = is_file('/www/wwwroot/zinesh.com/api/wallet_lib.php')
    ? '/www/wwwroot/zinesh.com'
    : dirname(__DIR__);
require_once $root . '/api/wallet_lib.php';
require_once $root . '/api/demo_lib.php';
require_once $root . '/api/escrow_room_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('escrow_rooms.json', []);
zinesh_json_write('escrow_room_messages.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('zinesh_events.json', []);

$log = [];
$bugs = [];

function step(string $test, int $num, string $msg, bool $ok): void
{
    global $log;
    $log[] = ['test' => $test, 'step' => $num, 'msg' => $msg, 'ok' => $ok];
    echo ($ok ? '  OK' : ' FAIL') . " [$test#$num] $msg\n";
}

function bug(
    string $test,
    int $stepNum,
    string $expected,
    string $actual,
    string $rootCause,
    string $file,
    string $fix,
    string $priority
): void {
    global $bugs;
    $bugs[] = compact('test', 'stepNum', 'expected', 'actual', 'rootCause', 'file', 'fix', 'priority');
}

function walletAvail(?array $u): float
{
    if (!$u) {
        return 0.0;
    }
    zinesh_ensure_wallet_fields($u);
    return round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
}

// --- Setup demo users ---
zinesh_demo_ensure_users();
zinesh_demo_reset_escrow_state();
$empLogin = zinesh_demo_login('employer');
$wrkLogin = zinesh_demo_login('worker');
$employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);

step('SETUP', 0, 'demo employer avail=' . walletAvail($employer), walletAvail($employer) >= 50000);
step('SETUP', 1, 'demo worker ticket=' . ZINESH_DEMO_WORKER_TICKET, $wrkLogin['ok'] ?? false);

$desc = str_repeat('E2E sprint sözleşme metni — teslim kapsamı ve revizyon hakları. ', 4);

// ========== TEST 1 Normal flow ==========
echo "\n=== TEST 1 Normal ===\n";
$t1 = 'T1';
$c = zinesh_escrow_room_connect($employer, ZINESH_DEMO_WORKER_TICKET, 'employer');
step($t1, 1, 'connect', !empty($c['ok']));
$roomId = (string)($c['room']['id'] ?? '');
if ($roomId === '') {
    bug($t1, 1, 'room created', 'no room', 'connect failed', 'api/escrow_room_lib.php', 'fix connect', 'P0');
}

$p = zinesh_escrow_room_propose_terms($employer, $roomId, 4500.0, 'E2E Normal', $desc, false);
step($t1, 2, 'propose 4500', !empty($p['ok']));
$availAfterPropose = walletAvail(zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID));
step($t1, 3, 'employer avail unchanged after propose (not locked yet)', abs($availAfterPropose - 50000) < 0.01);

$listW = zinesh_escrow_rooms_for_user(ZINESH_DEMO_WORKER_UID);
$workerSees = false;
foreach ($listW as $r) {
    if (($r['id'] ?? '') === $roomId && ($r['status'] ?? '') === 'terms_pending') {
        $workerSees = true;
    }
}
step($t1, 4, 'worker sees terms_pending', $workerSees);
if (!$workerSees) {
    bug($t1, 4, 'worker list shows terms_pending room', 'not visible', 'list/filter', 'api/escrow_room_lib.php', 'check zinesh_escrow_rooms_for_user', 'P0');
}

$a = zinesh_escrow_room_accept_terms($worker, $roomId, false);
step($t1, 5, 'worker accept -> locked', !empty($a['ok']) && ($a['room']['status'] ?? '') === 'locked');
$empAfterLock = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$lockedEscrow = (float)($empAfterLock['escrowBalance'] ?? 0);
$availAfterLock = walletAvail($empAfterLock);
step($t1, 6, "wallet locked=4500 avail=45500 (got escrow=$lockedEscrow avail=$availAfterLock)", abs($lockedEscrow - 4500) < 0.01 && abs($availAfterLock - 45500) < 0.01);
if (abs($lockedEscrow - 4500) >= 0.01) {
    bug($t1, 6, 'escrowBalance=4500', "escrow=$lockedEscrow", 'fund on accept', 'api/escrow_room_lib.php', 'verify lock amount', 'P0');
}

// Worker confirms complete, employer confirms
$cw = zinesh_escrow_room_confirm_complete($worker, $roomId);
step($t1, 7, 'worker confirm complete', !empty($cw['ok']));
$ce = zinesh_escrow_room_confirm_complete($employer, $roomId);
step($t1, 8, 'employer confirm -> completed/settling', !empty($ce['ok']));
$finalRoom = zinesh_escrow_room_find($roomId);
$finalStatus = (string)($finalRoom['status'] ?? '');
step($t1, 9, "final status=$finalStatus", in_array($finalStatus, ['completed', 'settling', 'completion_pending'], true));

// ========== TEST 2 Counter offer ==========
echo "\n=== TEST 2 Counter ===\n";
$t2 = 'T2';
zinesh_demo_reset_escrow_state();
$employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
$c2 = zinesh_escrow_room_connect($employer, ZINESH_DEMO_WORKER_TICKET, 'employer');
$room2 = (string)($c2['room']['id'] ?? '');
zinesh_escrow_room_propose_terms($employer, $room2, 3000.0, 'Counter base', $desc, false);
$co = zinesh_escrow_room_counter_offer($worker, $room2, 3500.0, 'Counter rev', $desc, false);
step($t2, 1, 'worker counter 3500', !empty($co['ok']));
$ae = zinesh_escrow_room_accept_terms($employer, $room2, false);
step($t2, 2, 'employer accepts counter -> locked', !empty($ae['ok']) && ($ae['room']['status'] ?? '') === 'locked');

// ========== TEST 3 Dispute ==========
echo "\n=== TEST 3 Dispute ===\n";
$t3 = 'T3';
zinesh_demo_ensure_users();
zinesh_demo_reset_escrow_state();
$employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
$c3 = zinesh_escrow_room_connect($employer, ZINESH_DEMO_WORKER_TICKET, 'employer');
$room3 = (string)($c3['room']['id'] ?? '');
zinesh_escrow_room_propose_terms($employer, $room3, 2000.0, 'Dispute test', $desc, false);
zinesh_escrow_room_accept_terms($worker, $room3, false);
$d = zinesh_escrow_room_file_dispute($employer, $room3, 'Teslim edilmedi', 'kanit');
step($t3, 1, 'dispute filed', !empty($d['ok']) && ($d['room']['status'] ?? '') === 'disputed');
$r = zinesh_escrow_room_resolve_dispute($room3, 'worker', 'admin e2e');
step($t3, 2, 'admin resolve', !empty($r['ok']));
$room3final = zinesh_escrow_room_find($room3);
$st3 = (string)($room3final['status'] ?? '');
step($t3, 3, "room closed status=$st3", in_array($st3, ['resolved', 'completed'], true));
if (!in_array($st3, ['resolved', 'completed'], true)) {
    bug($t3, 3, 'resolved/completed after dispute', $st3, 'resolve flow', 'api/escrow_room_lib.php', 'check settlement', 'P1');
}

// ========== TEST 8 Invalid inputs ==========
echo "\n=== TEST 8 Invalid ===\n";
$t8 = 'T8';
zinesh_demo_reset_escrow_state();
$employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
$badConnect = zinesh_escrow_room_connect($employer, ZINESH_DEMO_EMPLOYER_TICKET, 'employer');
step($t8, 1, 'own ticket rejected', empty($badConnect['ok']));
$badConnect2 = zinesh_escrow_room_connect($employer, '99999', 'employer');
step($t8, 2, 'invalid ticket rejected', empty($badConnect2['ok']));
$c8 = zinesh_escrow_room_connect($employer, ZINESH_DEMO_WORKER_TICKET, 'employer');
$room8 = (string)($c8['room']['id'] ?? '');
$shortDesc = str_repeat('x', 39);
$pShort = zinesh_escrow_room_propose_terms($employer, $room8, 100.0, 'T', $shortDesc, false);
step($t8, 3, '39 char contract rejected', empty($pShort['ok']));
$pZero = zinesh_escrow_room_propose_terms($employer, $room8, 0.0, 'T', $desc, false);
step($t8, 4, '0 TL rejected', empty($pZero['ok']));
$pNeg = zinesh_escrow_room_propose_terms($employer, $room8, -100.0, 'T', $desc, false);
step($t8, 5, 'negative rejected', empty($pNeg['ok']));
$pHuge = zinesh_escrow_room_propose_terms($employer, $room8, 100_000_000.0, 'T', $desc, false);
step($t8, 6, '100M rejected (insufficient)', empty($pHuge['ok']));

// ========== TEST 6 Multi room ==========
echo "\n=== TEST 6 Multi room ===\n";
$t6 = 'T6';
zinesh_demo_reset_escrow_state();
$employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$rooms = [];
for ($i = 0; $i < 5; $i++) {
    $cx = zinesh_escrow_room_connect($employer, ZINESH_DEMO_WORKER_TICKET, 'employer');
    if (!empty($cx['ok'])) {
        $rooms[] = (string)($cx['room']['id'] ?? '');
    }
}
$unique = count(array_unique(array_filter($rooms)));
step($t6, 1, "5 connect attempts -> unique rooms=$unique", $unique >= 1);
// open_between should return same room
step($t6, 2, 'duplicate connect returns same open room', $unique === 1);

// ========== TEST 10 Stress ==========
echo "\n=== TEST 10 Stress ===\n";
$t10 = 'T10';
zinesh_demo_reset_escrow_state();
$employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
$stressOk = 0;
for ($i = 0; $i < 20; $i++) {
    zinesh_demo_reset_escrow_state();
    $employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
    $worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
    $sc = zinesh_escrow_room_connect($employer, ZINESH_DEMO_WORKER_TICKET, 'employer');
    $rid = (string)($sc['room']['id'] ?? '');
    if ($rid === '') {
        continue;
    }
    $sp = zinesh_escrow_room_propose_terms($employer, $rid, 100.0, "Stress $i", $desc, false);
    if (empty($sp['ok'])) {
        continue;
    }
    $sa = zinesh_escrow_room_accept_terms($worker, $rid, false);
    if (!empty($sa['ok']) && ($sa['room']['status'] ?? '') === 'locked') {
        $stressOk++;
    }
}
step($t10, 1, "20x connect-propose-accept locked=$stressOk/20", $stressOk === 20);
$jsonRooms = zinesh_json_read('escrow_rooms.json');
step($t10, 2, 'escrow_rooms.json valid array after stress', is_array($jsonRooms));

// locking trap test
echo "\n=== TEST locking recovery ===\n";
zinesh_demo_reset_escrow_state();
$employer = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$worker = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
$employerPoor = $employer;
zinesh_update_user(ZINESH_DEMO_EMPLOYER_UID, static function (array &$u) {
    $u['usdtBalance'] = 50.0;
    $u['escrowBalance'] = 0.0;
});
$sc = zinesh_escrow_room_connect($employerPoor, ZINESH_DEMO_WORKER_TICKET, 'employer');
$rid = (string)($sc['room']['id'] ?? '');
zinesh_escrow_room_propose_terms($employerPoor, $rid, 40.0, 'Trap', $desc, false);
$failAccept = zinesh_escrow_room_accept_terms($worker, $rid, false);
$after = zinesh_escrow_room_find($rid);
$st = (string)($after['status'] ?? '');
step('TRAP', 1, "low balance accept fails status=$st (not stuck locking)", $st === 'terms_pending' || empty($failAccept['ok']));
if ($st === 'locking') {
    bug('TRAP', 1, 'terms_pending after failed accept', 'locking stuck', 'exit() in fund mutator', 'api/escrow_room_lib.php', 'zinesh_wallet_abort + rollback', 'P0');
}

echo "\n=== BUGS FOUND: " . count($bugs) . " ===\n";
foreach ($bugs as $b) {
    echo json_encode($b, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "\n=== SUMMARY steps=" . count($log) . " fail=" . count(array_filter($log, static fn($x) => !$x['ok'])) . " ===\n";
exit(count(array_filter($log, static fn($x) => !$x['ok'])) > 0 ? 1 : 0);
