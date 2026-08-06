"""Simulate successful login response for the failing account."""
from __future__ import annotations

import json
import os

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/security_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_profile_lib.php';

$uid = '1b9b932370787e42f66cddc693a1c14a';
$u = zinesh_find_user_by_uid($uid);
if (!$u) { echo "missing\n"; exit; }

try {
  zinesh_ensure_wallet_fields($u);
  zinesh_campaign_ensure_user_fields($u);
  zinesh_email_sync_legacy_verified($uid);
  $u = zinesh_campaign_persist_user_fields($uid) ?? zinesh_find_user_by_uid($uid) ?? $u;
  $totpGate = zinesh_founder_login_totp_gate($uid, $u, '');
  echo 'totp=' . json_encode($totpGate, JSON_UNESCAPED_UNICODE) . PHP_EOL;
  $token = zinesh_create_session($uid);
  if (zinesh_is_founder($u)) {
    $u = zinesh_sync_founder_treasury_wallets($uid);
  }
  $payload = [
    'ok' => true,
    'user' => zinesh_email_sanitize_public_user(array_merge($u, [
      'sessionToken' => $token,
      'campaign' => zinesh_campaign_user_progress($u),
      'isFounder' => zinesh_is_founder($u),
    ])),
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  echo 'json_ok=' . ($json !== false ? '1' : '0') . ' len=' . strlen((string)$json) . ' err=' . json_last_error_msg() . PHP_EOL;
  echo 'user_keys=' . implode(',', array_keys($payload['user'])) . PHP_EOL;
  echo 'has_sessionToken=' . (!empty($payload['user']['sessionToken']) ? '1' : '0') . PHP_EOL;
  echo 'has_uid=' . (!empty($payload['user']['uid']) ? '1' : '0') . PHP_EOL;
  // revoke probe token so we don't leave junk (optional)
  zinesh_revoke_session($token);
  echo "revoked_probe_session\n";
} catch (Throwable $e) {
  echo 'FAIL ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
  echo $e->getTraceAsString() . PHP_EOL;
}
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_login_sim.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        "php /tmp/zinesh_login_sim.php; rm -f /tmp/zinesh_login_sim.php"
    )
    print(stdout.read().decode("utf-8", errors="replace"))
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("ERR", err)

    # Latest audit around 13:25-13:31 with IP
    _, out2, _ = ssh.exec_command(
        r"""php -r '
$a=json_decode(file_get_contents("/www/server/zinesh-data/audit_log.json"), true);
$n=0;
foreach ((is_array($a)?$a:[]) as $r) {
  $uid=$r["meta"]["uid"]??"";
  $email=$r["meta"]["email"]??"";
  if ($uid!=="1b9b932370787e42f66cddc693a1c14a" && $email!=="hakikatinaslani@gmail.com") continue;
  echo ($r["at"]??"")." ".($r["action"]??"")." ip=".($r["ip"]??"")."\n";
  if (++$n>=20) break;
}
'"""
    )
    print(out2.read().decode())
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
