"""Diagnose live login / Google OAuth without printing secrets."""
from __future__ import annotations

import os
import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out if out.strip() else err

    print("=== php lint ===")
    print(
        run(
            "php -l /www/wwwroot/zinesh.com/api/auth.php; "
            "php -l /www/wwwroot/zinesh.com/api/config.php; "
            "php -l /www/wwwroot/zinesh.com/api/_bootstrap.php; "
            "php -l /www/wwwroot/zinesh.com/api/google_auth.php; "
            "php -l /www/wwwroot/zinesh.com/api/oauth_lib.php"
        )
    )

    print("=== config.local presence ===")
    print(
        run(
            "ls -la /www/wwwroot/zinesh.com/api/config.local.php "
            "/www/server/zinesh-data/config.local.php 2>&1 | head -20"
        )
    )

    print("=== where does bootstrap load local from? ===")
    print(
        run(
            "grep -n 'config.local\\|data_dir\\|zinesh_config' "
            "/www/wwwroot/zinesh.com/api/_bootstrap.php "
            "/www/wwwroot/zinesh.com/api/config.php | head -40"
        )
    )

    php = r"""<?php
$apiLocal='/www/wwwroot/zinesh.com/api/config.local.php';
$dataLocal='/www/server/zinesh-data/config.local.php';
echo "apiLocal=". (is_readable($apiLocal)?'yes':'no') ."\n";
echo "dataLocal=". (is_readable($dataLocal)?'yes':'no') ."\n";
$base=require '/www/wwwroot/zinesh.com/api/config.php';
$g=$base['google_oauth'] ?? [];
echo "base_enabled=".(!empty($g['enabled'])?'1':'0')."\n";
echo "base_client=".(!empty($g['client_id'])?'set':'missing')."\n";
echo "base_secret=".(!empty($g['client_secret'])?'set':'missing')."\n";
foreach (['api'=>$apiLocal,'data'=>$dataLocal] as $label=>$file) {
  if (!is_readable($file)) continue;
  $l=require $file;
  $gg=$l['google_oauth'] ?? [];
  echo $label."_enabled=".(array_key_exists('enabled',$gg)?json_encode($gg['enabled']):'unset')."\n";
  echo $label."_client=".(!empty($gg['client_id'])?'set':'missing')."\n";
  echo $label."_secret=".(!empty($gg['client_secret'])?'set':'missing')."\n";
  echo $label."_topkeys=".implode(',', array_keys($l))."\n";
}
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
$cfg=zinesh_config();
$gg=$cfg['google_oauth'] ?? [];
echo "merged_enabled=".(!empty($gg['enabled'])?'1':'0')."\n";
echo "merged_client=".(!empty($gg['client_id'])?'set':'missing')."\n";
echo "merged_secret=".(!empty($gg['client_secret'])?'set':'missing')."\n";
echo "data_dir=".($cfg['data_dir'] ?? '')."\n";
$users=rtrim((string)($cfg['data_dir'] ?? ''),'/').'/users.json';
echo "users_path=$users\n";
echo "users_readable=".(is_readable($users)?'yes':'no')."\n";
echo "users_writable=".(is_writable(dirname($users))?'yes':'no')."\n";
"""
from deploy_common import USER, require_deploy_host, require_deploy_pass
    remote = "/tmp/zinesh_diag_login.php"
    sftp = ssh.open_sftp()
    with sftp.file(remote, "w") as f:
        f.write(php)
    sftp.close()
    print("=== merged google oauth (no secrets) ===")
    print(run(f"php {remote}; rm -f {remote}"))

    print("=== google_auth start headers ===")
    print(run("curl -sI 'https://www.zinesh.com/api/google_auth.php?action=start' | head -25"))

    print("=== google_auth start body (fail?) ===")
    print(
        run(
            "curl -sL 'https://www.zinesh.com/api/google_auth.php?action=start' "
            "-o /tmp/ga_out -w 'code=%{http_code} redirect=%{url_effective}\\n' "
            "--max-redirs 0; head -c 500 /tmp/ga_out; echo"
        )
    )

    print("=== nginx/php error tail ===")
    print(
        run(
            "ls -lt /www/wwwlogs 2>/dev/null | head -12; "
            "for f in /www/wwwlogs/zinesh.com.error.log /www/wwwlogs/zinesh.com.log "
            "/www/server/php/v82/var/log/php-fpm.log /var/log/nginx/error.log; do "
            "if [ -f \"$f\" ]; then echo \"---- $f\"; tail -n 30 \"$f\"; fi; done"
        )
    )

    print("=== auth login smoke (wrong password expects 401) ===")
    print(
        run(
            "curl -s -X POST https://www.zinesh.com/api/auth.php "
            "-H 'Content-Type: application/json' "
            "-d '{\"action\":\"login\",\"email\":\"nosuch@zinesh.com\",\"password\":\"x\"}'"
        )
    )

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
