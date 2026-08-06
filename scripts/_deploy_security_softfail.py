"""Check Brevo + deploy security_lib soft-fail for mail gate."""
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

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out + (("\nERR:\n" + err) if err.strip() else "")

    php = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
echo 'brevo_fn=' . (function_exists('zinesh_brevo_api_configured') ? '1' : '0') . PHP_EOL;
if (function_exists('zinesh_brevo_api_configured')) {
  echo 'brevo_configured=' . (zinesh_brevo_api_configured() ? '1' : '0') . PHP_EOL;
}
echo 'smtp_configured=' . (function_exists('zinesh_smtp_configured') && zinesh_smtp_configured() ? '1' : '0') . PHP_EOL;
$cfg = zinesh_config();
echo 'local_keys_has_mail=' . (isset($cfg['mail']) ? '1' : '0') . PHP_EOL;
$secrets = '/www/server/zinesh-data/server_secrets.json';
if (is_readable($secrets)) {
  $s = json_decode((string)file_get_contents($secrets), true);
  echo 'secrets_keys=' . implode(',', array_keys(is_array($s)?$s:[])) . PHP_EOL;
  echo 'has_brevo=' . (!empty($s['brevo_api_key']) || !empty($s['BREVO_API_KEY']) || !empty(($s['mail']['brevo_api_key'] ?? null)) ? '1' : '0') . PHP_EOL;
}
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_mail_probe.php", "w") as f:
        f.write(php)
    sftp.put(str(ROOT / "api" / "security_lib.php"), "/www/wwwroot/zinesh.com/api/security_lib.php")
    sftp.close()
    print("=== mail providers ===")
    print(run("php /tmp/zinesh_mail_probe.php; rm -f /tmp/zinesh_mail_probe.php"))
    print("=== php lint security_lib ===")
    print(run("php -l /www/wwwroot/zinesh.com/api/security_lib.php"))

    # clear gate again just in case
    clear = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/security_lib.php';
$email='hakikatinaslani@gmail.com';
zinesh_login_clear_failures($email);
zinesh_login_lock_release($email);
echo "cleared\n";
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_clear2.php", "w") as f:
        f.write(clear)
    sftp.close()
    print(run("php /tmp/zinesh_clear2.php; rm -f /tmp/zinesh_clear2.php"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
