#!/usr/bin/env python3
import json
import os
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "").strip()

PHP = r"""<?php
declare(strict_types=1);
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/escrow_room_lib.php';
require_once '/www/wwwroot/zinesh.com/api/tl_havale_lib.php';

$usersOut = [];
foreach (zinesh_load_users() as $u) {
    zinesh_ensure_wallet_fields($u);
    $usersOut[] = [
        'ticket' => (string)($u['ticketNumber'] ?? ''),
        'normalized' => zinesh_normalize_member_ticket((string)($u['ticketNumber'] ?? '')),
        'havaleRef' => zinesh_havale_user_reference($u),
        'email' => $u['email'] ?? '',
        'usdt' => round((float)($u['usdtBalance'] ?? 0), 2),
        'escrow' => round((float)($u['escrowBalance'] ?? 0), 2),
    ];
}

$roomsOut = [];
foreach (zinesh_escrow_rooms_load() as $room) {
    if ((string)($room['status'] ?? '') !== 'completed') continue;
    $completedAt = (string)($room['completedAt'] ?? '');
    $ts = strtotime($completedAt);
    if ($ts === false) continue;
    if ($ts < strtotime('2026-07-09 00:00:00') || $ts > strtotime('2026-07-09 23:59:59')) continue;
    $roomsOut[] = [
        'id' => $room['id'] ?? '',
        'employerUid' => $room['employerUid'] ?? '',
        'workerUid' => $room['workerUid'] ?? '',
        'amount' => $room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0,
        'payoutTry' => $room['payoutTry'] ?? 0,
        'completedAt' => $completedAt,
        'senderDebitReconciled' => !empty($room['senderDebitReconciled']),
    ];
}

echo json_encode([
    'userCount' => count($usersOut),
    'users' => $usersOut,
    'todayCompletedRooms' => $roomsOut,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"""

if not PASS:
    raise SystemExit("ZINESH_DEPLOY_PASS required")
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASS, timeout=25)
sftp = ssh.open_sftp()
with sftp.file("/tmp/z_wallet_snapshot.php", "w") as f:
    f.write(PHP)
sftp.close()
_, stdout, stderr = ssh.exec_command("php /tmp/z_wallet_snapshot.php; rm -f /tmp/z_wallet_snapshot.php")
print(stdout.read().decode())
err = stderr.read().decode().strip()
if err:
    print("ERR:", err)
ssh.close()
