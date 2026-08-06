#!/usr/bin/env python3
"""One-off: credit a test havale deposit on live server."""
from __future__ import annotations

import os
import sys

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", "/www/wwwroot/zinesh.com/api")

UID = os.environ.get("ZINESH_CREDIT_UID", "1b9b932370787e42f66cddc693a1c14a")
AMOUNT = float(os.environ.get("ZINESH_CREDIT_AMOUNT", "10"))
REFERENCE = os.environ.get("ZINESH_CREDIT_REF", "ZN-SH-DUAL-27920")

PHP = f"""<?php
declare(strict_types=1);
require_once '{REMOTE_API}/wallet_lib.php';
require_once '{REMOTE_API}/tl_havale_lib.php';

$uid = {UID!r};
$amount = {AMOUNT};
$reference = {REFERENCE!r};
$user = zinesh_find_user_by_uid($uid);
if (!$user) {{
    fwrite(STDERR, "user not found\\n");
    exit(1);
}}
$id = 'hav-manual-' . substr(hash('sha256', $uid . microtime(true)), 0, 12);
$row = [
    'id' => $id,
    'uid' => $uid,
    'email' => (string)($user['email'] ?? ''),
    'name' => (string)($user['name'] ?? ''),
    'amountTry' => round($amount, 2),
    'reference' => $reference,
    'note' => 'manual test credit',
    'status' => 'pending',
    'createdAt' => gmdate('c'),
];
$result = zinesh_havale_credit_deposit($row, 'admin:manual_test');
if (empty($result['ok'])) {{
    fwrite(STDERR, ($result['message'] ?? 'credit failed') . "\\n");
    exit(2);
}}
$wallet = $result['wallet'] ?? [];
echo 'credited=' . round($amount, 2) . PHP_EOL;
echo 'balance=' . ($wallet['usdtBalance'] ?? 0) . PHP_EOL;
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_credit_havale.php"
        with sftp.file(remote, "w") as f:
            f.write(PHP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command(f"php {remote}; rm -f {remote}")
        out = stdout.read().decode().strip()
        err = stderr.read().decode().strip()
        if out:
            print(out)
        if err:
            print(err, file=sys.stderr)
        return stdout.channel.recv_exit_status()
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
