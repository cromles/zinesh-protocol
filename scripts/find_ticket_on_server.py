#!/usr/bin/env python3
import json
import os
import sys
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "").strip()
NEEDLE = sys.argv[1] if len(sys.argv) > 1 else "27902"

PHP = """<?php
declare(strict_types=1);
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
$needle = %s;
$hits = [];
foreach (zinesh_load_users() as $u) {
    $t = (string)($u['ticketNumber'] ?? '');
    if (strpos($t, $needle) === false) continue;
    zinesh_ensure_wallet_fields($u);
    $hits[] = [
        'ticket' => $t,
        'normalized' => zinesh_normalize_member_ticket($t),
        'email' => $u['email'] ?? '',
        'uid' => $u['uid'] ?? '',
        'usdtBalance' => $u['usdtBalance'] ?? 0,
        'escrowBalance' => $u['escrowBalance'] ?? 0,
    ];
}
echo json_encode($hits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
""" % json.dumps(NEEDLE)

if not PASS:
    sys.exit("ZINESH_DEPLOY_PASS required")
ssh = paramiko.SSHClient()
ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
ssh.connect(HOST, username=USER, password=PASS, timeout=25)
sftp = ssh.open_sftp()
with sftp.file("/tmp/z_find_ticket.php", "w") as f:
    f.write(PHP)
sftp.close()
_, stdout, stderr = ssh.exec_command("php /tmp/z_find_ticket.php; rm -f /tmp/z_find_ticket.php")
print(stdout.read().decode())
err = stderr.read().decode().strip()
if err:
    print("ERR:", err)
ssh.close()
