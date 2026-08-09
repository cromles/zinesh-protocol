#!/usr/bin/env python3
"""Belirli üye numaraları için escrow durumu."""
from __future__ import annotations

import json
import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

TICKETS = sys.argv[1:] if len(sys.argv) > 1 else ["22595", "59639"]

HOST = require_deploy_host()
PASS = require_deploy_pass()

PHP = r"""<?php
declare(strict_types=1);
$tickets = json_decode(getenv('PROBE_TICKETS') ?: '[]', true);
$api = '/www/wwwroot/zinesh.com/api';
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
require_once $api . '/escrow_room_lib.php';
require_once $api . '/verification_lib.php';

$out = [];
foreach ($tickets as $ticket) {
    $u = zinesh_find_user_by_ticket((string)$ticket);
    if (!$u) {
        $out[] = ['ticket' => $ticket, 'found' => false];
        continue;
    }
    zinesh_ensure_wallet_fields($u);
    $ver = zinesh_contract_verification_status($u);
    $rooms = zinesh_escrow_rooms_for_user((string)$u['uid']);
    $out[] = [
        'ticket' => $ticket,
        'found' => true,
        'email' => $u['email'] ?? '',
        'emailVerified' => !empty($u['emailVerified']),
        'kycStatus' => $u['kycStatus'] ?? '',
        'verification_ok' => $ver['ok'],
        'missing' => $ver['missing'],
        'usdtBalance' => $u['usdtBalance'] ?? 0,
        'escrowBalance' => $u['escrowBalance'] ?? 0,
        'available' => round((float)($u['usdtBalance'] ?? 0) - (float)($u['escrowBalance'] ?? 0), 2),
        'rooms' => array_map(static function ($r) {
            return [
                'id' => $r['id'] ?? '',
                'status' => $r['status'] ?? '',
                'amount' => $r['agreedAmountTry'] ?? 0,
                'termsProposedBy' => $r['termsProposedBy'] ?? '',
                'lockingAt' => $r['lockingAt'] ?? null,
                'lockingEmployerFunded' => !empty($r['lockingEmployerFunded']),
            ];
        }, $rooms),
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()
    remote = "/tmp/z_ticket_probe.php"
    with sftp.file(remote, "w") as f:
        f.write(PHP)
    sftp.close()
    tickets_json = json.dumps(TICKETS)
    cmd = f"PROBE_TICKETS={json.dumps(tickets_json)} php {remote} 2>&1; rm -f {remote}"
    _, stdout, _ = ssh.exec_command(cmd)
    print(stdout.read().decode("utf-8", errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
