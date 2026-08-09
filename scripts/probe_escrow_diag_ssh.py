#!/usr/bin/env python3
"""VPS: stuck rooms, app/www API, son escrow odaları."""
from __future__ import annotations

import json
import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

HOST = require_deploy_host()
PASS = require_deploy_pass()

PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
require_once $api . '/escrow_room_lib.php';

$rooms = zinesh_escrow_rooms_load();
$byStatus = [];
$stuck = [];
foreach ($rooms as $r) {
    $s = (string)($r['status'] ?? '');
    $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
    if ($s === 'locking') {
        $stuck[] = [
            'id' => $r['id'] ?? '',
            'lockingAt' => $r['lockingAt'] ?? '',
            'employerFunded' => !empty($r['lockingEmployerFunded']),
            'workerFunded' => !empty($r['lockingWorkerCollateralFunded']),
            'amount' => $r['agreedAmountTry'] ?? 0,
        ];
    }
}
echo json_encode([
    'room_count' => count($rooms),
    'by_status' => $byStatus,
    'stuck_locking' => $stuck,
    'recent' => array_slice(array_reverse($rooms), 0, 8),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=30)
    sftp = ssh.open_sftp()
    remote = "/tmp/z_escrow_diag.php"
    with sftp.file(remote, "w") as f:
        f.write(PHP)
    sftp.close()

    cmds = [
        f"php {remote} 2>&1",
        "curl -s -o /dev/null -w 'www_local=%{http_code}\\n' -X POST http://127.0.0.1/api/escrow_room.php -H 'Host: www.zinesh.com' -H 'Content-Type: application/json' -d '{\"action\":\"list\"}'",
        "curl -s -o /dev/null -w 'app_local=%{http_code}\\n' -X POST http://127.0.0.1/api/escrow_room.php -H 'Host: app.zinesh.com' -H 'Content-Type: application/json' -d '{\"action\":\"list\"}'",
        f"rm -f {remote}",
    ]
    for c in cmds:
        _, stdout, _ = ssh.exec_command(c)
        print(stdout.read().decode("utf-8", errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
