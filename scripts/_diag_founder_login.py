"""Diagnose login issues on production without printing secrets."""
from __future__ import annotations

import json
import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass

FOUNDER_EMAIL = "yasinkarademir147@gmail.com"

PHP = r"""<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/password_reset_lib.php';
require '/www/wwwroot/zinesh.com/api/founder_lib.php';
$cfg = zinesh_config();
echo 'data_dir=' . zinesh_resolve_data_dir() . "\n";
echo 'users_path=' . zinesh_data_path('users.json') . "\n";
$users = zinesh_load_users();
echo 'user_count=' . count($users) . "\n";
echo 'founder_emails_cfg=' . json_encode($cfg['founder_emails'] ?? []) . "\n";
$founder = zinesh_find_user_by_email('""" + FOUNDER_EMAIL + r"""');
if (!$founder) {
    echo "founder_account=missing\n";
    foreach ($users as $u) {
        $e = strtolower((string)($u['email'] ?? ''));
        if ($e !== '') echo "email_listed={$e}\n";
    }
    exit(0);
}
echo 'founder_account=found' . "\n";
echo 'founder_uid=' . ($founder['uid'] ?? '') . "\n";
echo 'has_password_hash=' . (!empty($founder['passwordHash']) ? 'yes' : 'no') . "\n";
echo 'email_verified=' . (!empty($founder['emailVerified']) ? 'yes' : 'no') . "\n";
echo 'is_founder=' . (zinesh_is_founder($founder) ? 'yes' : 'no') . "\n";
echo 'totp_enabled=' . (!empty($founder['totpEnabled']) ? 'yes' : 'no') . "\n";
echo 'totp_pending=' . (trim((string)($founder['totpPendingSecret'] ?? '')) !== '' ? 'yes' : 'no') . "\n";
echo 'hash_algo=' . (is_string($founder['passwordHash'] ?? null) ? substr((string)$founder['passwordHash'], 0, 4) : 'none') . "\n";
"""


def main() -> int:
    host = require_deploy_host()
    password = require_deploy_pass()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{host} ...")
    ssh.connect(host, username=USER, password=password, timeout=25)
    try:
        _, out, _ = ssh.exec_command(
            "find /www -name users.json 2>/dev/null; "
            "echo '---'; "
            "ls -la /www/wwwroot/zinesh.com/api/config.local.php "
            "/www/server/zinesh-data/config.local.php 2>&1"
        )
        print(out.read().decode().strip())

        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_diag_login.php"
        with sftp.file(remote, "w") as f:
            f.write(PHP)
        sftp.close()

        _, out, err = ssh.exec_command(f"php {remote} && rm -f {remote}")
        print(out.read().decode().strip() or err.read().decode().strip())

        # Probe login API shape (wrong password on purpose)
        probe = json.dumps({"action": "login", "email": FOUNDER_EMAIL, "password": "__probe__"})
        _, out, _ = ssh.exec_command(
            "curl -s -o /tmp/login_probe.json -w 'http=%{http_code}' "
            "-X POST http://127.0.0.1/api/auth.php "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
            f"-d '{probe}'; echo; cat /tmp/login_probe.json | head -c 400; echo"
        )
        print("--- login probe ---")
        print(out.read().decode().strip())
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
