"""Hotfix: upload missing founder_lib requires and verify login no longer 500."""
from __future__ import annotations

import json
import os
from pathlib import Path

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")
ROOT = Path(__file__).resolve().parents[1]
REMOTE_API = "/www/wwwroot/zinesh.com/api"

FILES = [
    "auth.php",
    "wallet.php",
    "oauth_lib.php",
    "founder_lib.php",
    "tl_havale_lib.php",
    "totp_reset_lib.php",
    "ai_chat_lib.php",
]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    for name in FILES:
        local = ROOT / "api" / name
        remote = f"{REMOTE_API}/{name}"
        print(f"upload {name}")
        sftp.put(str(local), remote)
    sftp.close()

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out + (("\nERR:\n" + err) if err.strip() else "")

    print(run("php -l /www/wwwroot/zinesh.com/api/auth.php"))
    print(run("grep -n founder_lib /www/wwwroot/zinesh.com/api/auth.php | head -5"))

    # Confirm function exists when auth chain loads
    php = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/auth.php';
"""
    # Can't require auth.php (it executes). Load same requires:
    php = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/kyc_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_profile_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';
echo 'has_is_founder=' . (function_exists('zinesh_is_founder') ? '1' : '0') . PHP_EOL;
$u = zinesh_find_user_by_email('hakikatinaslani@gmail.com');
$uid = (string)$u['uid'];
zinesh_ensure_wallet_fields($u);
zinesh_campaign_ensure_user_fields($u);
$token = zinesh_create_session($uid);
$payload = [
  'ok' => true,
  'user' => zinesh_email_sanitize_public_user(array_merge($u, [
    'sessionToken' => $token,
    'campaign' => zinesh_campaign_user_progress($u),
    'isFounder' => zinesh_is_founder($u),
  ])),
];
echo 'encode_ok=' . (json_encode($payload) !== false ? '1' : '0') . PHP_EOL;
zinesh_revoke_session($token);
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_founder_ok.php", "w") as f:
        f.write(php)
    sftp.close()
    print(run("php /tmp/zinesh_founder_ok.php; rm -f /tmp/zinesh_founder_ok.php"))

    # Clear lock
    print(
        run(
            "php -r \"require '/www/wwwroot/zinesh.com/api/wallet_lib.php'; "
            "require '/www/wwwroot/zinesh.com/api/security_lib.php'; "
            "zinesh_login_clear_failures('hakikatinaslani@gmail.com'); "
            "zinesh_login_lock_release('hakikatinaslani@gmail.com'); echo 'cleared\\n';\""
        )
    )

    # Wrong password still 401 (not 500); proves bootstrap
    payload = json.dumps(
        {
            "action": "login",
            "email": "hakikatinaslani@gmail.com",
            "password": "definitely-wrong",
        }
    )
    print(
        run(
            "curl -sk -o /tmp/b -w 'HTTP=%{http_code}\\n' -X POST "
            "'https://127.0.0.1/api/auth.php' -H 'Host: www.zinesh.com' "
            "-H 'Content-Type: application/json' -H 'Origin: https://www.zinesh.com' "
            "--resolve www.zinesh.com:443:127.0.0.1 "
            f"-d '{payload}'; cat /tmp/b; echo"
        )
    )
    print(run("tail -n 5 /var/log/nginx/zinesh.com.error.log"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
