#!/usr/bin/env python3
"""Check live wallet + completed escrow rooms for a member ticket."""
from __future__ import annotations

import json
import os
import sys

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "").strip()
TICKET = sys.argv[1] if len(sys.argv) > 1 else "ZN-SH-DUAL-27902"

PHP = r"""<?php
declare(strict_types=1);
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/escrow_room_lib.php';

$ticket = zinesh_normalize_member_ticket(%(ticket)r);
$user = zinesh_find_user_by_ticket($ticket);
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'user_not_found', 'ticket' => $ticket], JSON_UNESCAPED_UNICODE);
    exit;
}
zinesh_ensure_wallet_fields($user);
$wallet = zinesh_wallet_state($user);
$uid = (string)($user['uid'] ?? '');
$rooms = [];
foreach (zinesh_escrow_rooms_load() as $room) {
    if ((string)($room['employerUid'] ?? '') !== $uid && (string)($room['workerUid'] ?? '') !== $uid) {
        continue;
    }
    $rooms[] = [
        'id' => $room['id'] ?? '',
        'status' => $room['status'] ?? '',
        'role' => ((string)($room['employerUid'] ?? '') === $uid) ? 'employer' : 'worker',
        'amount' => $room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0,
        'payoutTry' => $room['payoutTry'] ?? 0,
        'completedAt' => $room['completedAt'] ?? '',
        'senderDebitReconciled' => !empty($room['senderDebitReconciled']),
        'employerUsdtDebited' => !empty($room['employerUsdtDebited']),
    ];
}
usort($rooms, static fn($a, $b) => strcmp((string)($b['completedAt'] ?? ''), (string)($a['completedAt'] ?? '')));
echo json_encode([
    'ok' => true,
    'ticket' => $ticket,
    'email' => $user['email'] ?? '',
    'uid' => $uid,
    'usdtBalance' => $wallet['usdtBalance'] ?? 0,
    'escrowBalance' => $wallet['escrowBalance'] ?? 0,
    'availableUsdt' => $wallet['availableUsdt'] ?? null,
    'rooms' => $rooms,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
""" % {"ticket": TICKET}


def main() -> int:
    if not PASS:
        print("ERROR: ZINESH_DEPLOY_PASS required", file=sys.stderr)
        return 2
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    remote = "/tmp/z_check_ticket.php"
    with sftp.file(remote, "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(f"php {remote}; rm -f {remote}")
    out = stdout.read().decode()
    err = stderr.read().decode().strip()
    ssh.close()
    if err:
        print("ERR:", err, file=sys.stderr)
    print(out)
    try:
        data = json.loads(out)
    except json.JSONDecodeError:
        return 1
    return 0 if data.get("ok") else 1


if __name__ == "__main__":
    raise SystemExit(main())
