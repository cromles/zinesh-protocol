<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/escrow_room_lib.php';

zinesh_backfill_missing_user_tickets();
$users = zinesh_load_users();
echo "user_count=" . count($users) . PHP_EOL;
foreach ($users as $u) {
    $email = (string)($u['email'] ?? '');
    if ($email === '') continue;
    echo json_encode([
        'email' => $email,
        'ticket' => (string)($u['ticketNumber'] ?? ''),
        'kyc' => (string)($u['kycStatus'] ?? 'none'),
        'emailVerified' => !empty($u['emailVerified']),
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

if (count($users) >= 2) {
    $a = $users[0];
    $b = $users[1];
    $ticketB = (string)($b['ticketNumber'] ?? '');
    if ($ticketB !== '') {
        $r = zinesh_escrow_room_connect($a, $ticketB, 'employer');
        echo 'connect_test=' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
