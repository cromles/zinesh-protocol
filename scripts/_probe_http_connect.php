<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';

$fizobia = zinesh_find_user_by_uid('ddc796e15027592a44181b663bee3045');
if (!$fizobia) {
    foreach (zinesh_load_users() as $u) {
        if (($u['email'] ?? '') === 'fizobia@gmail.com') {
            $fizobia = $u;
            break;
        }
    }
}
if (!$fizobia) {
    echo "fizobia not found\n";
    exit(1);
}

$token = '';
foreach (zinesh_json_read('sessions.json') as $s) {
    if (is_array($s) && ($s['uid'] ?? '') === ($fizobia['uid'] ?? '')) {
        $token = (string)($s['token'] ?? '');
        if ($token !== '') {
            break;
        }
    }
}
echo 'session_token_len=' . strlen($token) . PHP_EOL;
if ($token === '') {
    echo "no active session for fizobia\n";
    exit(1);
}

$payload = json_encode([
    'action' => 'connect',
    'peerTicket' => '59639',
    'myRole' => 'employer',
    'sessionToken' => $token,
    'uid' => $fizobia['uid'] ?? '',
    'email' => $fizobia['email'] ?? '',
], JSON_UNESCAPED_UNICODE);

$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nHost: www.zinesh.com\r\nOrigin: https://www.zinesh.com\r\n",
        'content' => $payload,
        'ignore_errors' => true,
    ],
]);
$body = file_get_contents('http://127.0.0.1/api/escrow_room.php', false, $ctx);
$status = $http_response_header[0] ?? 'no-status';
echo "HTTP $status\n";
echo $body . PHP_EOL;
