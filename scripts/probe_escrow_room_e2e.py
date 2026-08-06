#!/usr/bin/env python3
"""E2E test escrow rooms + messages on live server via SSH PHP."""
import json
import os
import sys
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$api = '/www/wwwroot/zinesh.com/api';
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
require_once $api . '/tl_mode_lib.php';
require_once $api . '/escrow_room_lib.php';

echo 'php=' . PHP_VERSION . PHP_EOL;
echo 'mbstring=' . (extension_loaded('mbstring') ? '1' : '0') . PHP_EOL;
echo 'tl_mode=' . (zinesh_tl_mode_enabled() ? '1' : '0') . PHP_EOL;

$dataDir = zinesh_resolve_data_dir();
echo 'data_dir=' . $dataDir . PHP_EOL;
$roomsPath = zinesh_data_path('escrow_rooms.json');
$msgPath = zinesh_data_path('escrow_room_messages.json');
echo 'rooms_exists=' . (file_exists($roomsPath) ? '1' : '0') . PHP_EOL;
echo 'rooms_writable=' . (is_writable(dirname($roomsPath)) ? '1' : '0') . PHP_EOL;
echo 'msg_exists=' . (file_exists($msgPath) ? '1' : '0') . PHP_EOL;

$users = zinesh_load_users();
$withTicket = [];
foreach ($users as $u) {
    $t = trim((string)($u['ticketNumber'] ?? ''));
    if ($t !== '' && empty($u['isSystemWallet'])) {
        $withTicket[] = $u;
    }
}
echo 'users_with_ticket=' . count($withTicket) . PHP_EOL;
if (count($withTicket) < 2) {
    echo "FAIL need 2 users with tickets\n";
    exit(1);
}
$a = $withTicket[0];
$b = $withTicket[1];
echo 'userA=' . ($a['email'] ?? '') . ' ticket=' . ($a['ticketNumber'] ?? '') . PHP_EOL;
echo 'userB=' . ($b['email'] ?? '') . ' ticket=' . ($b['ticketNumber'] ?? '') . PHP_EOL;

// Test json_read 2-arg (should not fatal)
try {
    $t = zinesh_json_read('escrow_rooms.json', []);
    echo 'json_read_2arg_ok=1 count=' . count($t) . PHP_EOL;
} catch (Throwable $e) {
    echo 'json_read_2arg_FAIL=' . $e->getMessage() . PHP_EOL;
}

// Connect A as employer to B
$res = zinesh_escrow_room_connect($a, (string)$b['ticketNumber'], 'employer');
echo 'connect_ok=' . (!empty($res['ok']) ? '1' : '0') . PHP_EOL;
if (empty($res['ok'])) {
    echo 'connect_err=' . ($res['message'] ?? '') . PHP_EOL;
    exit(1);
}
$room = $res['room'] ?? [];
$roomId = (string)($room['id'] ?? '');
echo 'room_id=' . $roomId . PHP_EOL;

// B should see room in list
$listB = zinesh_escrow_rooms_for_user((string)$b['uid']);
echo 'peer_sees_room=' . (count($listB) > 0 ? '1' : '0') . ' count=' . count($listB) . PHP_EOL;

require_once $api . '/notifications_lib.php';
$ntf = zinesh_notifications_query((string)$b['uid'], 7, 5);
echo 'peer_notifications=' . count($ntf['notifications'] ?? []) . PHP_EOL;
if (!empty($ntf['notifications'][0]['type'])) {
    echo 'last_ntf_type=' . $ntf['notifications'][0]['type'] . PHP_EOL;
}

// Send message from A
$send = zinesh_escrow_room_send_message($a, $roomId, 'E2E test mesaji ' . date('H:i:s'));
echo 'send_ok=' . (!empty($send['ok']) ? '1' : '0') . PHP_EOL;
if (empty($send['ok'])) {
    echo 'send_err=' . ($send['message'] ?? '') . PHP_EOL;
}
$msgs = zinesh_escrow_room_messages($roomId);
echo 'msg_count=' . count($msgs) . PHP_EOL;
if (count($msgs) > 0) {
    echo 'last_msg=' . json_encode($msgs[count($msgs)-1], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

// B reads messages
$msgsB = zinesh_escrow_room_messages($roomId);
echo 'peer_msg_count=' . count($msgsB) . PHP_EOL;

// Send from B
$sendB = zinesh_escrow_room_send_message($b, $roomId, 'Karsidan cevap ' . date('H:i:s'));
echo 'peer_send_ok=' . (!empty($sendB['ok']) ? '1' : '0') . PHP_EOL;
$msgs2 = zinesh_escrow_room_messages($roomId);
echo 'msg_count_after=' . count($msgs2) . PHP_EOL;

echo $msgPath . ' size=' . (file_exists($msgPath) ? filesize($msgPath) : 0) . PHP_EOL;
echo "E2E_DONE\n";
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_escrow_e2e.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command("php /tmp/z_escrow_e2e.php 2>&1; rm -f /tmp/z_escrow_e2e.php")
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    print(out)
    if err.strip():
        print("STDERR:", err)
    ssh.close()
    return 0 if "E2E_DONE" in out and "send_ok=1" in out else 1


if __name__ == "__main__":
    sys.exit(main())
