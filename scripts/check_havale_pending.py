#!/usr/bin/env python3
"""Check live havale pending + recent credits."""
import json
import os
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
declare(strict_types=1);
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
require_once '/www/wwwroot/zinesh.com/api/tl_havale_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_lib.php';

echo 'min_try=' . zinesh_havale_min_try() . PHP_EOL;

$pending = zinesh_havale_load_pending();
echo 'pending_count=' . count($pending) . PHP_EOL;
foreach (array_slice(array_reverse($pending), 0, 8) as $row) {
    echo 'pending: ' . json_encode([
        'id' => $row['id'] ?? '',
        'email' => $row['email'] ?? '',
        'amountTry' => $row['amountTry'] ?? 0,
        'reference' => $row['reference'] ?? '',
        'status' => $row['status'] ?? '',
        'createdAt' => $row['createdAt'] ?? '',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$users = zinesh_json_read('users.json');
foreach ($users as $u) {
    $ref = zinesh_havale_user_reference($u);
    if ($ref === 'ZN-SH-DUAL-27920' || stripos($ref, '27920') !== false) {
        zinesh_ensure_wallet_fields($u);
        echo 'user_match: ' . json_encode([
            'email' => $u['email'] ?? '',
            'uid' => $u['uid'] ?? '',
            'reference' => $ref,
            'usdtBalance' => $u['usdtBalance'] ?? 0,
            'isFounder' => zinesh_is_founder($u),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
"""

def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_havale_check.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command("php /tmp/z_havale_check.php; rm -f /tmp/z_havale_check.php")
    print(stdout.read().decode())
    err = stderr.read().decode().strip()
    if err:
        print("ERR:", err)
    ssh.close()

if __name__ == "__main__":
    main()
