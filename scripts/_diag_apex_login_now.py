"""Reproduce apex login breakage and check live JS/API."""
from __future__ import annotations

import json
import os
import re

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
EMAIL = "hakikatinaslani@gmail.com"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out if out.strip() else err

    print("=== HTML served for Host:zinesh.com vs www ===")
    for host in ("zinesh.com", "www.zinesh.com"):
        print(f"-- {host}")
        print(
            run(
                f"curl -sk -D - -o /tmp/h_{host}.html 'https://127.0.0.1/' "
                f"-H 'Host: {host}' --resolve {host}:443:127.0.0.1 --max-redirs 0 | head -20; "
                f"echo BODY_HEAD; head -c 200 /tmp/h_{host}.html; echo"
            )
        )

    print("=== through public CF: what does zinesh.com return? ===")
    print(
        run(
            "curl -sk -D - -o /tmp/cf_apex.html 'https://zinesh.com/' --max-redirs 0 | head -25; "
            "echo; head -c 250 /tmp/cf_apex.html; echo; "
            "grep -oE 'main-[A-Za-z0-9_-]+\\.js' /tmp/cf_apex.html | head -3"
        )
    )
    print(
        run(
            "curl -sk -D - -o /tmp/cf_www.html 'https://www.zinesh.com/' --max-redirs 0 | head -15; "
            "grep -oE 'main-[A-Za-z0-9_-]+\\.js' /tmp/cf_www.html | head -3"
        )
    )

    print("=== public apex POST /api/auth.php (no follow) ===")
    payload = json.dumps(
        {"action": "login", "email": EMAIL, "password": "wrong-password-test"}
    )
    print(
        run(
            "curl -sk -D - -o /tmp/apex_post.out -X POST 'https://zinesh.com/api/auth.php' "
            "-H 'Content-Type: application/json' -H 'Origin: https://zinesh.com' "
            f"-d '{payload}' --max-redirs 0 | head -25; echo BODY:; head -c 300 /tmp/apex_post.out; echo"
        )
    )

    print("=== public www POST with Origin apex ===")
    print(
        run(
            "curl -sk -D - -o /tmp/www_post.out -X POST 'https://www.zinesh.com/api/auth.php' "
            "-H 'Content-Type: application/json' -H 'Origin: https://zinesh.com' "
            f"-d '{payload}' | head -25; echo BODY:; cat /tmp/www_post.out; echo"
        )
    )

    print("=== clear verification + recent fails for user ===")
    php = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/security_lib.php';
$email='hakikatinaslani@gmail.com';
echo 'requires='.(zinesh_login_requires_verification($email)?'1':'0').PHP_EOL;
echo 'attempts='.json_encode(zinesh_login_attempts_get($email)).PHP_EOL;
zinesh_login_clear_failures($email);
zinesh_login_lock_release($email);
echo "cleared\n";
$audit=json_decode(file_get_contents('/www/server/zinesh-data/audit_log.json'),true);
$n=0;
foreach ((is_array($audit)?$audit:[]) as $r) {
  if (($r['meta']['email']??'')===$email || (($r['meta']['uid']??'')==='1b9b932370787e42f66cddc693a1c14a')) {
    echo ($r['at']??'').' '.($r['action']??'')."\n";
    if (++$n>=12) break;
  }
}
"""
from deploy_common import USER, require_deploy_host, require_deploy_pass
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_clear_user.php", "w") as f:
        f.write(php)
    sftp.close()
    print(run("php /tmp/zinesh_clear_user.php; rm -f /tmp/zinesh_clear_user.php"))

    print("=== live main bundle apiOrigin snippet ===")
    jsname = run(
        "curl -sk https://www.zinesh.com/ | grep -oE 'main-[A-Za-z0-9_-]+\\.js' | head -1"
    ).strip()
    print("bundle=", jsname)
    if jsname:
        js = run(f"curl -sk https://www.zinesh.com/assets/{jsname}")
        # Find nearby strings
        for needle in (
            "https://www.zinesh.com",
            "zinesh.com",
            "apiOrigin",
            "Giriş tamamlanamadı",
        ):
            print(f"contains[{needle}]=", needle in js)

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
