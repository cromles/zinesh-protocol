"""Upload and run PHP probes with safe quoting."""
from __future__ import annotations

import os
from pathlib import Path

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")
ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    local = ROOT / "scripts" / "_auth_sanitize_probe.php"
    sftp.put(str(local), "/tmp/zinesh_auth_sanitize.php")
    sftp.close()

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return (out + (("\nERR:\n" + err) if err.strip() else "")).strip()

    print("=== sanitize probe ===")
    print(run("php /tmp/zinesh_auth_sanitize.php; rm -f /tmp/zinesh_auth_sanitize.php"))

    print("=== campaign deps on live ===")
    print(
        run(
            "grep -n 'function zinesh_platform_fizi\\|require_once' "
            "/www/wwwroot/zinesh.com/api/campaign_lib.php | head -40"
        )
    )

    print("=== founder/totp gate on founder ===")
    php = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';
$u = zinesh_find_user_by_uid('3d92373bde77ef56b7c390e586a31d82');
echo 'founder_found=' . ($u ? '1' : '0') . PHP_EOL;
if ($u) {
  $gate = zinesh_founder_login_totp_gate('3d92373bde77ef56b7c390e586a31d82', $u, '');
  echo 'totp_gate=' . json_encode($gate, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
try {
  $pub = zinesh_campaign_public_status();
  echo 'campaign_public_ok=1 slots=' . ($pub['slotsRemaining'] ?? '?') . PHP_EOL;
} catch (Throwable $e) {
  echo 'campaign_public_fail ' . $e->getMessage() . PHP_EOL;
}
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_totp_probe.php", "w") as f:
        f.write(php)
    sftp.close()
    print(run("php /tmp/zinesh_totp_probe.php; rm -f /tmp/zinesh_totp_probe.php"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
