#!/usr/bin/env python3
"""Reproduce escrow_create on live for the test user."""
from __future__ import annotations

import json
import os
import sys

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")
REMOTE_API = "/www/wwwroot/zinesh.com/api"
UID = "1b9b932370787e42f66cddc693a1c14a"

PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);
require_once $api . '/wallet_lib.php';
require_once $api . '/tl_mode_lib.php';
require_once $api . '/escrow_jobs_lib.php';
require_once $api . '/protocol_constants.php';

$uid = '1b9b932370787e42f66cddc693a1c14a';
$user = zinesh_find_user_by_uid($uid);
if (!$user) { echo "user_missing\n"; exit(1); }
zinesh_ensure_wallet_fields($user);
echo 'tl_mode=' . (zinesh_tl_mode_enabled() ? '1' : '0') . PHP_EOL;
echo 'escrow_tl=' . (zinesh_escrow_uses_tl() ? '1' : '0') . PHP_EOL;
echo 'site_fizi=' . (zinesh_site_fizi_ledger_enabled() ? '1' : '0') . PHP_EOL;
echo 'balance=' . ($user['usdtBalance'] ?? 0) . PHP_EOL;
echo 'escrow=' . ($user['escrowBalance'] ?? 0) . PHP_EOL;

$dataDir = zinesh_resolve_data_dir();
echo 'data_dir=' . $dataDir . PHP_EOL;
$path = rtrim($dataDir, '/') . '/escrow_jobs.json';
echo 'escrow_file=' . $path . PHP_EOL;
echo 'escrow_file_exists=' . (is_file($path) ? '1' : '0') . PHP_EOL;
echo 'escrow_writable=' . (is_writable(dirname($path)) ? '1' : '0') . PHP_EOL;
if (is_file($path)) {
    echo 'escrow_file_writable=' . (is_writable($path) ? '1' : '0') . PHP_EOL;
    echo 'escrow_size=' . filesize($path) . PHP_EOL;
}

try {
    $result = zinesh_escrow_job_create($uid, 1.0, 'Test emanet', 'probe', '', '', '', 'Emanet', true);
    echo 'create_ok=' . (!empty($result['ok']) ? '1' : '0') . PHP_EOL;
    echo 'create_msg=' . ($result['message'] ?? '') . PHP_EOL;
    if (!empty($result['job']['id'])) {
        echo 'job_id=' . $result['job']['id'] . PHP_EOL;
        echo 'match=' . ($result['job']['matchCode'] ?? '') . PHP_EOL;
        // cleanup test job
        zinesh_json_atomic('escrow_jobs.json', function (array &$jobs) use ($result) {
            $id = $result['job']['id'] ?? '';
            $jobs = array_values(array_filter($jobs, static fn($j) => ($j['id'] ?? '') !== $id));
            return true;
        });
        echo "cleaned=1\n";
    }
} catch (Throwable $e) {
    echo 'EXCEPTION: ' . $e->getMessage() . PHP_EOL;
    echo $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_escrow_probe.php"
        with sftp.file(remote, "w") as f:
            f.write(PHP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command(f"php {remote}; rm -f {remote}")
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        print(out)
        if err.strip():
            print("ERR:", err, file=sys.stderr)
        return stdout.channel.recv_exit_status()
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
