<?php
declare(strict_types=1);
putenv('ZINESH_SIM_DATA_DIR=/tmp/zinesh_trap_test');
putenv('ZINESH_DEMO_MODE=1');
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/demo_lib.php';
require '/www/wwwroot/zinesh.com/api/escrow_room_lib.php';
zinesh_ensure_core_data_files();
zinesh_json_write('escrow_rooms.json', []);
zinesh_demo_ensure_users();
$e = zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID);
$w = zinesh_find_user_by_uid(ZINESH_DEMO_WORKER_UID);
zinesh_update_user(ZINESH_DEMO_EMPLOYER_UID, static function (array &$u) {
    $u['usdtBalance'] = 50000.0;
    $u['escrowBalance'] = 0.0;
});
$c = zinesh_escrow_room_connect($e, '90002', 'employer');
$rid = (string)($c['room']['id'] ?? '');
$desc = str_repeat('Trap test contract text with enough characters. ', 3);
zinesh_escrow_room_propose_terms($e, $rid, 5000.0, 'Trap', $desc, false);
// Simulate balance spent elsewhere before worker accepts
zinesh_update_user(ZINESH_DEMO_EMPLOYER_UID, static function (array &$u) {
    $u['usdtBalance'] = 100.0;
    $u['escrowBalance'] = 0.0;
});
$a = zinesh_escrow_room_accept_terms($w, $rid, false);
$room = zinesh_escrow_room_find($rid);
echo json_encode([
    'accept_ok' => $a['ok'] ?? false,
    'accept_msg' => $a['message'] ?? '',
    'status' => $room['status'] ?? '',
    'employer_escrow' => zinesh_find_user_by_uid(ZINESH_DEMO_EMPLOYER_UID)['escrowBalance'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
