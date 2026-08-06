"""Inspect login locks/audit without printing full emails."""
from __future__ import annotations

import os
from pathlib import Path

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
PHP = r"""<?php
$locks = json_decode((string)file_get_contents('/www/server/zinesh-data/login_locks.json'), true);
$attempts = json_decode((string)file_get_contents('/www/server/zinesh-data/login_attempts.json'), true);
$audit = json_decode((string)file_get_contents('/www/server/zinesh-data/audit_log.json'), true);
echo "locks=".count(is_array($locks)?$locks:[])."\n";
foreach ((is_array($locks)?$locks:[]) as $k=>$v) {
  if (!is_array($v)) continue;
  echo "LOCK until=".($v['until']??$v['lockedUntil']??json_encode($v))."\n";
}
echo "attempts=".count(is_array($attempts)?$attempts:[])."\n";
$i=0;
foreach ((is_array($attempts)?$attempts:[]) as $k=>$v) {
  if ($i++>8) break;
  echo "ATT ".substr((string)$k,0,12)."=".substr(json_encode($v),0,180)."\n";
}
$rows = is_array($audit) ? array_slice($audit, -25) : [];
foreach ($rows as $r) {
  if (!is_array($r)) continue;
  $ev = $r['event'] ?? $r['action'] ?? $r['type'] ?? '';
  $ts = $r['ts'] ?? $r['time'] ?? $r['at'] ?? '';
  $safe = $r;
  if (isset($safe['email']) && is_string($safe['email'])) {
    $safe['email'] = substr($safe['email'],0,3).'***';
  }
  if (isset($safe['data']['email']) && is_string($safe['data']['email'])) {
    $safe['data']['email'] = substr($safe['data']['email'],0,3).'***';
  }
  echo $ts.' '.$ev.' '.substr(json_encode($safe, JSON_UNESCAPED_UNICODE),0,240)."\n";
}

$ctx=stream_context_create(['http'=>['timeout'=>8,'ignore_errors'=>true]]);
$r=@file_get_contents('https://oauth2.googleapis.com/tokeninfo?access_token=x', false, $ctx);
echo "google_outbound=".($r===false?'fail':'ok')." body=".substr((string)$r,0,100)."\n";
"""
from deploy_common import USER, require_deploy_host, require_deploy_pass


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    remote = "/tmp/zinesh_auth_inspect.php"
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
