"""Verify auth login success path no longer fatals on zinesh_is_founder."""
from __future__ import annotations

import os
from pathlib import Path

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
PHP = r"""<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
// Same requires as auth.php (without executing auth)
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/kyc_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_profile_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_platform_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';
require_once '/www/wwwroot/zinesh.com/api/password_reset_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_reset_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';

echo 'has_is_founder=' . (function_exists('zinesh_is_founder') ? '1' : '0') . PHP_EOL;
echo 'auth_has_require=' . (str_contains(file_get_contents('/www/wwwroot/zinesh.com/api/auth.php'), "founder_lib.php") ? '1' : '0') . PHP_EOL;

$email = 'hakikatinaslani@gmail.com';
$u = zinesh_find_user_by_email($email);
if (!$u) { echo "missing_user\n"; exit; }
$uid = (string)$u['uid'];
zinesh_ensure_wallet_fields($u);
zinesh_campaign_ensure_user_fields($u);
zinesh_email_sync_legacy_verified($uid);
$u = zinesh_campaign_persist_user_fields($uid) ?? zinesh_find_user_by_uid($uid) ?? $u;
$totpGate = zinesh_founder_login_totp_gate($uid, $u, '');
echo 'totp_ok=' . (!empty($totpGate['ok']) ? '1' : '0') . PHP_EOL;
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
echo 'login_payload_ok=' . ($json !== false ? '1' : '0') . ' len=' . strlen((string)$json) . PHP_EOL;
zinesh_revoke_session($token);
echo "DONE\n";
"""
from deploy_common import USER, require_deploy_host, require_deploy_pass


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_verify_login500.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        "php /tmp/zinesh_verify_login500.php; rm -f /tmp/zinesh_verify_login500.php"
    )
    print(stdout.read().decode("utf-8", errors="replace"))
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("ERR", err)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
