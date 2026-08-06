from deploy_common import USER, require_deploy_host, require_deploy_pass
#!/usr/bin/env python3
"""Verify escrow_create response path no longer triggers open_basedir spam."""
from __future__ import annotations

import os
import sys

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = require_deploy_pass()
PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);
require_once $api . '/wallet_lib.php';
require_once $api . '/withdraw_executor.php';
require_once $api . '/campaign_lib.php';
require_once $api . '/email_lib.php';
require_once $api . '/founder_lib.php';
require_once $api . '/founder_profile_lib.php';
require_once $api . '/tl_mode_lib.php';
require_once $api . '/escrow_jobs_lib.php';

$uid = '1b9b932370787e42f66cddc693a1c14a';
$user = zinesh_find_user_by_uid($uid);
$token = zinesh_create_session($uid);

// Capture warnings
$warnings = 0;
set_error_handler(function ($errno, $errstr) use (&$warnings) {
    if (str_contains($errstr, 'open_basedir') || str_contains($errstr, 'file_exists')) {
        $warnings++;
    }
    return true;
});

$input = ['sessionToken' => $token];
$user = zinesh_require_auth($input);
$jobResult = zinesh_escrow_job_create($uid, 1.0, 'Verify fix', 'ok', '', '', '', 'Emanet', true);
$user = zinesh_find_user_by_uid($uid) ?? $user;
$payload = [
  'ok' => true,
  'wallet' => zinesh_wallet_state($user),
  'user' => zinesh_public_user($user),
  'job' => zinesh_escrow_job_public_row($jobResult['job'], $uid),
  'matchCode' => (string)($jobResult['job']['matchCode'] ?? ''),
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
restore_error_handler();

echo 'warnings=' . $warnings . PHP_EOL;
echo 'create_ok=' . (!empty($jobResult['ok']) ? '1' : '0') . PHP_EOL;
echo 'match=' . ($payload['matchCode'] ?? '') . PHP_EOL;
echo 'json_ok=' . ($json !== false ? '1' : '0') . ' len=' . strlen((string)$json) . PHP_EOL;
echo 'node_bin=' . zinesh_node_binary() . PHP_EOL;

$jid = $jobResult['job']['id'] ?? '';
if ($jid !== '') {
  zinesh_json_atomic('escrow_jobs.json', function(array &$jobs) use ($jid) {
    $jobs = array_values(array_filter($jobs, static fn($j)=>($j['id']??'')!==$jid));
    return true;
  });
}
zinesh_json_atomic('sessions.json', function(array &$s) use ($token) {
  unset($s[$token]);
  return true;
});
"""

def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_verify_fix.php", "w") as f:
            f.write(PHP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command("php /tmp/z_verify_fix.php; rm -f /tmp/z_verify_fix.php")
        print(stdout.read().decode("utf-8", errors="replace"))
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("ERR:", err[:1000])
        return 0
    finally:
        ssh.close()

if __name__ == "__main__":
    raise SystemExit(main())
