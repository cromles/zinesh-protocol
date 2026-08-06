<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/escrow_room_lib.php';

zinesh_backfill_missing_user_tickets();
$users = zinesh_load_users();
$byTicket = [];
foreach ($users as $u) {
    $t = zinesh_normalize_member_ticket((string)($u['ticketNumber'] ?? ''));
    if ($t !== '') {
        $byTicket[$t] = $u;
    }
}

foreach (['22595', '59639'] as $ticket) {
    $u = $byTicket[$ticket] ?? null;
    echo "ticket=$ticket " . ($u ? json_encode([
        'email' => $u['email'] ?? '',
        'uid' => substr((string)($u['uid'] ?? ''), 0, 12),
        'kyc' => $u['kycStatus'] ?? 'none',
    ], JSON_UNESCAPED_UNICODE) : 'NOT_FOUND') . PHP_EOL;
}

$rooms = zinesh_escrow_rooms_load();
echo 'room_count=' . count($rooms) . PHP_EOL;
foreach ($rooms as $room) {
    $e = zinesh_find_user_by_uid((string)($room['employerUid'] ?? ''));
    $w = zinesh_find_user_by_uid((string)($room['workerUid'] ?? ''));
    echo json_encode([
        'id' => $room['id'] ?? '',
        'status' => $room['status'] ?? '',
        'employerTicket' => $e['ticketNumber'] ?? '',
        'workerTicket' => $w['ticketNumber'] ?? '',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$a = $byTicket['22595'] ?? null;
$b = $byTicket['59639'] ?? null;
if ($a && $b) {
    echo '--- connect 22595 -> 59639 as employer ---' . PHP_EOL;
    $r = zinesh_escrow_room_connect($a, '59639', 'employer');
    echo json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
