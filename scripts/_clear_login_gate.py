"""Inspect mail config (no secrets) and clear stuck verification for one email."""
from __future__ import annotations

import os

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")
EMAIL = "hakikatinaslani@gmail.com"

PHP = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/security_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';

$cfg = zinesh_config()['mail'] ?? [];
$smtp = $cfg['smtp'] ?? [];
echo "from=" . ($cfg['from_email'] ?? '') . PHP_EOL;
echo "smtp_enabled=" . (!empty($smtp['enabled']) ? '1' : '0') . PHP_EOL;
echo "smtp_host_set=" . (!empty($smtp['host']) ? '1' : '0') . PHP_EOL;
echo "smtp_user_set=" . (!empty($smtp['username']) ? '1' : '0') . PHP_EOL;
echo "smtp_pass_set=" . (!empty($smtp['password']) ? '1' : '0') . PHP_EOL;
echo "smtp_port=" . ($smtp['port'] ?? '') . PHP_EOL;

$email = strtolower(trim(getenv('DIAG_EMAIL') ?: ''));
echo "requires_before=" . (zinesh_login_requires_verification($email) ? '1' : '0') . PHP_EOL;
echo "attempts_before=" . json_encode(zinesh_login_attempts_get($email), JSON_UNESCAPED_UNICODE) . PHP_EOL;

// Clear failures so correct password can login without mail gate
zinesh_login_clear_failures($email);
zinesh_login_lock_release($email);
echo "cleared=1\n";
echo "requires_after=" . (zinesh_login_requires_verification($email) ? '1' : '0') . PHP_EOL;
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_mail_clear.php", "w") as f:
        f.write(PHP)
    sftp.close()

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out + (("\nERR:\n" + err) if err.strip() else "")

    print(run(f"DIAG_EMAIL={EMAIL} php /tmp/zinesh_mail_clear.php; rm -f /tmp/zinesh_mail_clear.php"))

    print("=== wrong password response after clear ===")
    print(
        run(
            "curl -sk -X POST 'https://127.0.0.1/api/auth.php' "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
            "-H 'Origin: https://www.zinesh.com' "
            "--resolve www.zinesh.com:443:127.0.0.1 "
            "-d '{\"action\":\"login\",\"email\":\"hakikatinaslani@gmail.com\",\"password\":\"wrong\"}'"
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
