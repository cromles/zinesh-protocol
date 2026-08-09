#!/usr/bin/env python3
"""SSH üzerinden escrow başlatma akışını test eder (connect → propose → accept → locked)."""
from __future__ import annotations

import json
import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

HOST = require_deploy_host()
PASS = require_deploy_pass()
API_ROOT = "/www/wwwroot/zinesh.com/api"

PHP = r"""<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$api = '/www/wwwroot/zinesh.com/api';
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
require_once $api . '/escrow_room_lib.php';

function line(string $k, $v): void {
    echo $k . '=' . (is_bool($v) ? ($v ? '1' : '0') : (string)$v) . PHP_EOL;
}

$users = zinesh_load_users();
$candidates = [];
foreach ($users as $u) {
    $ticket = trim((string)($u['ticketNumber'] ?? ''));
    if ($ticket === '' || !empty($u['isSystemWallet'])) continue;
    if (empty($u['emailVerified'])) continue;
    zinesh_ensure_wallet_fields($u);
    $avail = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
    $candidates[] = ['user' => $u, 'avail' => $avail, 'ticket' => $ticket];
}
usort($candidates, static fn($a, $b) => $b['avail'] <=> $a['avail']);

if (count($candidates) < 2) {
    line('fail', 'need_2_verified_users');
    exit(1);
}

$employerWrap = $candidates[0];
$workerWrap = null;
foreach (array_slice($candidates, 1) as $c) {
    if (($c['user']['uid'] ?? '') !== ($employerWrap['user']['uid'] ?? '')) {
        $workerWrap = $c;
        break;
    }
}
if ($workerWrap === null) {
    line('fail', 'need_distinct_users');
    exit(1);
}

$employer = $employerWrap['user'];
$worker = $workerWrap['user'];
line('employer_email', $employer['email'] ?? '');
line('employer_ticket', $employerWrap['ticket']);
line('employer_avail', $employerWrap['avail']);
line('worker_email', $worker['email'] ?? '');
line('worker_ticket', $workerWrap['ticket']);

// Stuck locking rooms
$stuck = 0;
foreach (zinesh_escrow_rooms_load() as $room) {
    if ((string)($room['status'] ?? '') === 'locking') {
        $stuck++;
        line('stuck_locking_room', (string)($room['id'] ?? ''));
    }
}
line('stuck_locking_count', $stuck);

$connect = zinesh_escrow_room_connect($employer, $workerWrap['ticket'], 'employer');
line('connect_ok', !empty($connect['ok']));
if (empty($connect['ok'])) {
    line('connect_msg', $connect['message'] ?? '');
    exit(1);
}
$roomId = (string)($connect['room']['id'] ?? '');
line('room_id', $roomId);

$desc = str_repeat('E2E probe sözleşme metni — teslim ve kapsam açıklaması. ', 4);
$propose = zinesh_escrow_room_propose_terms($employer, $roomId, 100.0, 'E2E Probe', $desc, false);
line('propose_ok', !empty($propose['ok']));
if (empty($propose['ok'])) {
    line('propose_msg', $propose['message'] ?? '');
    exit(1);
}
line('propose_status', $propose['room']['status'] ?? '');

$accept = zinesh_escrow_room_accept_terms($worker, $roomId, false);
line('accept_ok', !empty($accept['ok']));
line('accept_status', $accept['room']['status'] ?? '');
line('accept_msg', $accept['message'] ?? '');
if (empty($accept['ok'])) {
    $room = zinesh_escrow_room_find($roomId);
    line('room_after_fail_status', $room['status'] ?? '');
    exit(1);
}

$emp = zinesh_find_user_by_uid((string)$employer['uid']);
line('employer_escrow_after', $emp['escrowBalance'] ?? 0);
line('E2E_DONE', 1);
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()
    remote = "/tmp/z_escrow_start_probe.php"
    with sftp.file(remote, "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(f"php {remote} 2>&1; rm -f {remote}")
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    print(out)
    if err.strip():
        print("STDERR:", err, file=sys.stderr)
    ssh.close()
    return 0 if "E2E_DONE=1" in out and "accept_ok=1" in out else 1


if __name__ == "__main__":
    sys.exit(main())
