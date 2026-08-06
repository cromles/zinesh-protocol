from deploy_common import USER, require_deploy_host, require_deploy_pass
#!/usr/bin/env python3
"""Compare live wallet.php escrow_create response and PHP/nginx error logs."""
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
require_once $api . '/campaign_lib.php';
require_once $api . '/email_lib.php';
require_once $api . '/founder_lib.php';
require_once $api . '/founder_profile_lib.php';
require_once $api . '/tl_mode_lib.php';
require_once $api . '/escrow_jobs_lib.php';

$uid = '1b9b932370787e42f66cddc693a1c14a';
$token = zinesh_create_session($uid);

// Invoke wallet.php like a web request using include with mocked input is hard.
// Instead mirror the exact response building after create.

$user = zinesh_require_auth(['sessionToken' => $token]);
$amount = 1.0;
$title = 'Response encode test';
$description = 'desc';
$matchOnly = true;
$jobResult = zinesh_escrow_job_create($uid, $amount, $title, $description, '', '', '', 'Emanet', true);
if (!$jobResult['ok']) {
  echo "fail create\n";
  exit(1);
}
$job = $jobResult['job'];
$user = zinesh_find_user_by_uid($uid) ?? $user;

$payload = [
  'ok' => true,
  'wallet' => zinesh_wallet_state($user),
  'user' => zinesh_public_user($user),
  'job' => zinesh_escrow_job_public_row($job, $uid),
  'matchCode' => (string)($job['matchCode'] ?? ''),
  'message' => 'Emanet kodu hazır: ' . ($job['matchCode'] ?? '') . '. WhatsApp’tan karşı tarafa gönderin.',
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
if ($json === false) {
  echo 'JSON_ERROR=' . json_last_error_msg() . PHP_EOL;
} else {
  echo 'JSON_OK len=' . strlen($json) . PHP_EOL;
  // Verify structure frontend needs
  $decoded = json_decode($json, true);
  echo 'has_wallet=' . (isset($decoded['wallet']) ? '1' : '0') . PHP_EOL;
  echo 'has_job=' . (isset($decoded['job']) ? '1' : '0') . PHP_EOL;
  echo 'matchCode=' . ($decoded['matchCode'] ?? '') . PHP_EOL;
}

// cleanup
$jid = $job['id'];
zinesh_json_atomic('escrow_jobs.json', function(array &$jobs) use ($jid) {
  $jobs = array_values(array_filter($jobs, static fn($j)=>($j['id']??'')!==$jid));
  return true;
});
zinesh_json_atomic('sessions.json', function(array &$s) use ($token) {
  unset($s[$token]);
  return true;
});

// Compare deployed wallet.php function presence / excerpt
$src = file_get_contents($api . '/wallet.php');
echo 'wallet_has_matchCode_resp=' . (str_contains($src, "'matchCode'") ? '1' : '0') . PHP_EOL;
echo 'wallet_has_escrow_create=' . (str_contains($src, "escrow_create") ? '1' : '0') . PHP_EOL;
if (preg_match('/if \(\$action === \'escrow_create\'\) \{(.*?)\nif \(\$action ===/s', $src, $m)) {
  echo "ESCROW_CREATE_BLOCK_LEN=" . strlen($m[1]) . PHP_EOL;
  echo substr($m[1], -500) . PHP_EOL;
}
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_resp.php", "w") as f:
            f.write(PHP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command("php /tmp/z_resp.php; rm -f /tmp/z_resp.php")
        print(stdout.read().decode("utf-8", errors="replace"))
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("ERR:", err)

        cmds = [
            "find /var/log /www /www/server -name '*error*.log' 2>/dev/null | head -40",
            "grep -R \"wallet.php\" /www/server/nginx/logs 2>/dev/null | tail -20 || true",
            "ls /www/server/nginx/logs 2>/dev/null | head",
            "tail -n 50 /www/server/nginx/logs/error.log 2>/dev/null || true",
            "tail -n 50 /www/wwwroot/zinesh.com/api/../logs/php_errors.log 2>/dev/null || true",
            # invoke via php built-in? use curl to public file with token created properly
        ]
        for cmd in cmds:
            print("---", cmd[:120], "---")
            _, stdout, stderr = ssh.exec_command(cmd)
            out = stdout.read().decode("utf-8", errors="replace")
            if out.strip():
                print(out[:2500])
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
