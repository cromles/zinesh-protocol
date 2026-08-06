from deploy_common import USER, require_deploy_host, require_deploy_pass
"""Verify apex CORS + live bundle apiOrigin fix."""
from __future__ import annotations

import os
import re

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

    print("=== CORS Origin https://zinesh.com -> www auth ===")
    print(
        run(
            "curl -sk -D /tmp/h -o /tmp/b -X POST 'https://127.0.0.1/api/auth.php' "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
            "-H 'Origin: https://zinesh.com' "
            "--resolve www.zinesh.com:443:127.0.0.1 "
            "-d '{\"action\":\"login\",\"email\":\"hakikatinaslani@gmail.com\",\"password\":\"wrong\"}'; "
            "echo '---HEADERS---'; cat /tmp/h; echo '---BODY---'; cat /tmp/b; echo"
        )
    )

    print("=== nginx apex site ===")
    print(run("cat /etc/nginx/sites-enabled/zinesh.com"))

    print("=== live bundles ===")
    www_html = run("curl -sk https://www.zinesh.com/")
    m = re.search(r"main-[A-Za-z0-9_-]+\.js", www_html)
    print("www_main=", m.group(0) if m else "missing")
    if m:
        js = run(f"curl -sk https://www.zinesh.com/assets/{m.group(0)}")
        # apiOrigin hardcodes www for apex host
        print("has_www_api_force=", "https://www.zinesh.com" in js)
        print("has_apex_host_check=", 'zinesh.com' in js and "app.zinesh.com" in js)

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
