from deploy_common import USER, require_deploy_host, require_deploy_pass
#!/usr/bin/env python3
"""Inspect live PHP errors and simulate wallet.php bootstrap for escrow_create."""
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

// Capture fatal/user JSON exits via shutdown if possible
ob_start();

$uid = '1b9b932370787e42f66cddc693a1c14a';
require_once $api . '/wallet_lib.php';
$token = zinesh_create_session($uid);
$user = zinesh_find_user_by_uid($uid);

// Replicate early wallet.php gate
require_once $api . '/tl_mode_lib.php';
require_once $api . '/escrow_jobs_lib.php';
require_once $api . '/campaign_lib.php';
require_once $api . '/founder_lib.php';
require_once $api . '/founder_profile_lib.php';

$action = 'escrow_create';
$siteFiziBlocked = ['swap', 'escrow_lock', 'escrow_release', 'settle_escrow', 'escrow_create', 'escrow_job_cancel', 'escrow_join'];
$tlEscrowAllowed = zinesh_tl_mode_enabled() && in_array($action, ['escrow_lock', 'escrow_release', 'settle_escrow', 'escrow_create', 'escrow_jobs_list', 'escrow_job_cancel', 'escrow_join'], true);
echo 'gate_blocked=' . ((!zinesh_site_fizi_ledger_enabled() && !$tlEscrowAllowed && in_array($action, $siteFiziBlocked, true)) ? '1' : '0') . PHP_EOL;
echo 'tl=' . (zinesh_tl_mode_enabled() ? '1' : '0') . ' fizi=' . (zinesh_site_fizi_ledger_enabled() ? '1' : '0') . PHP_EOL;
echo 'bind_fp=' . (!empty(zinesh_config()['session_bind_fingerprint']) ? '1' : '0') . PHP_EOL;
echo 'idle=' . (int)(zinesh_config()['session_idle_seconds'] ?? 0) . PHP_EOL;

$input = [
  'action' => $action,
  'sessionToken' => $token,
  'amount' => 1,
  'title' => 'CLI wallet path',
  'description' => 'x',
  'supplierEmail' => '',
  'matchOnly' => true,
  'category' => 'Emanet',
];

try {
  $authUser = zinesh_require_auth($input);
  echo "auth_ok=1\n";
  $amount = 1.0;
  $matchOnly = true;
  zinesh_ensure_wallet_fields($authUser);
  $available = round((float)$authUser['usdtBalance'] - (float)$authUser['escrowBalance'], 2);
  echo "available=$available\n";
  $jobResult = zinesh_escrow_job_create((string)$authUser['uid'], $amount, 'CLI wallet path', 'x', '', '', '', 'Emanet', true);
  echo 'create=' . json_encode(['ok'=>$jobResult['ok']??false,'msg'=>$jobResult['message']??'','id'=>$jobResult['job']['id']??null], JSON_UNESCAPED_UNICODE) . PHP_EOL;
  if (!empty($jobResult['job']['id'])) {
    $jid = $jobResult['job']['id'];
    zinesh_json_atomic('escrow_jobs.json', function(array &$jobs) use ($jid) {
      $jobs = array_values(array_filter($jobs, static fn($j)=>($j['id']??'')!==$jid));
      return true;
    });
  }
  // Build response like wallet.php
  $user2 = zinesh_find_user_by_uid((string)$authUser['uid']);
  $payload = [
    'ok' => true,
    'wallet' => zinesh_wallet_state($user2),
    'user' => zinesh_public_user($user2),
    'job' => zinesh_escrow_job_public_row($jobResult['job'], (string)$authUser['uid']),
    'matchCode' => (string)($jobResult['job']['matchCode'] ?? ''),
  ];
  echo 'payload_job_keys=' . implode(',', array_keys($payload['job'])) . PHP_EOL;
  $json = json_encode($payload);
  echo 'json_ok=' . ($json !== false ? '1' : '0') . ' len=' . strlen((string)$json) . PHP_EOL;
} catch (Throwable $e) {
  echo 'EX: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}

// cleanup sessions with probe or this token
zinesh_json_atomic('sessions.json', function(array &$sessions) use ($token) {
  unset($sessions[$token]);
  return true;
});
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_path2.php", "w") as f:
            f.write(PHP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command("php /tmp/z_path2.php; rm -f /tmp/z_path2.php")
        print(stdout.read().decode("utf-8", errors="replace"))
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("ERR:", err)

        cmds = [
            "ls -lt /www/wwwlogs | head -20",
            "find /www/wwwlogs -name '*zinesh*' -o -name '*error*' 2>/dev/null | head -30",
            "tail -n 80 /www/wwwlogs/www.zinesh.com.error.log 2>/dev/null || tail -n 80 /www/wwwlogs/zinesh.com-error_log 2>/dev/null || true",
            "php -r 'echo json_encode(array_slice(json_decode(file_get_contents(\"/www/server/zinesh-data/escrow_jobs.json\"), true) ?: [], -5), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);'",
            "php -r '$c=require \"/www/wwwroot/zinesh.com/api/config.php\"; echo \"idle=\".($c[\"session_idle_seconds\"]??\"?\").\" bind=\".(!empty($c[\"session_bind_fingerprint\"])?1:0).\"\\n\";'",
        ]
        for cmd in cmds:
            print("---", cmd[:100], "---")
            _, stdout, stderr = ssh.exec_command(cmd)
            out = stdout.read().decode("utf-8", errors="replace")
            err = stderr.read().decode("utf-8", errors="replace")
            if out.strip():
                print(out[:3000])
            if err.strip():
                print("ERR:", err[:800])
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
