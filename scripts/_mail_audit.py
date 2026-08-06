"""Inspect recent mail failure reasons (no secrets)."""
from __future__ import annotations

import os

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
$audit = json_decode((string)file_get_contents('/www/server/zinesh-data/audit_log.json'), true);
$n = 0;
foreach ((is_array($audit) ? $audit : []) as $r) {
  if (!is_array($r)) continue;
  $a = (string)($r['action'] ?? '');
  if (!preg_match('/mail_|login_verification|login_failed|login$/i', $a)) continue;
  $safe = $r;
  if (isset($safe['meta']['email'])) $safe['meta']['email'] = substr((string)$safe['meta']['email'],0,3).'***';
  if (isset($safe['meta']['to'])) $safe['meta']['to'] = substr((string)$safe['meta']['to'],0,3).'***';
  echo ($r['at'] ?? '') . ' ' . $a . ' ' . substr(json_encode($safe, JSON_UNESCAPED_UNICODE), 0, 280) . "\n";
  if (++$n >= 25) break;
}
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_mail_audit.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command("php /tmp/zinesh_mail_audit.php; rm -f /tmp/zinesh_mail_audit.php")
    print(stdout.read().decode("utf-8", errors="replace"))
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("ERR", err)
    # ensure security_lib softfail present on server
    _, stdout2, _ = ssh.exec_command(
        "grep -n 'login_verification_mail_failed\\|requiresVerification.*= false' "
        "/www/wwwroot/zinesh.com/api/security_lib.php | head -10"
    )
    print("softfail markers:", stdout2.read().decode())
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
