"""Check newest audit + lock details + rate limits."""
from __future__ import annotations

import os

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
$audit = json_decode((string)file_get_contents('/www/server/zinesh-data/audit_log.json'), true);
$locks = json_decode((string)file_get_contents('/www/server/zinesh-data/login_locks.json'), true);
$rates = json_decode((string)file_get_contents('/www/server/zinesh-data/rate_limits.json'), true);
echo "audit_count=".count(is_array($audit)?$audit:[])."\n";
$rows = is_array($audit) ? array_slice($audit, 0, 40) : [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;
  $ev = $r['action'] ?? '';
  if (!preg_match('/login|oauth|google|register|rate|session|auth/i', $ev)) continue;
  $safe = $r;
  if (isset($safe['meta']['email'])) $safe['meta']['email']=substr((string)$safe['meta']['email'],0,3).'***';
  echo ($r['at']??'').' '.$ev.' '.substr(json_encode($safe, JSON_UNESCAPED_UNICODE),0,260)."\n";
}
echo "---LOCKS---\n";
foreach ((is_array($locks)?$locks:[]) as $k=>$v) {
  echo substr($k,0,24).'='.substr(json_encode($v),0,200)."\n";
}
echo "---RATE auth buckets---\n";
$n=0;
foreach ((is_array($rates)?$rates:[]) as $k=>$v) {
  if (strpos($k,'auth:')!==0 && strpos($k,'email_')!==0) continue;
  echo $k.'='.json_encode($v)."\n";
  if (++$n>25) break;
}
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    remote = "/tmp/zinesh_auth_inspect2.php"
    sftp = ssh.open_sftp()
    with sftp.file(remote, "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(f"php {remote}; rm -f {remote}")
    print(stdout.read().decode("utf-8", errors="replace"))
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("ERR", err)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
