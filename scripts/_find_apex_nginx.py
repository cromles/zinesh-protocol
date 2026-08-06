from deploy_common import USER, require_deploy_host, require_deploy_pass
"""Locate apex nginx config and optionally add /api proxy without 301."""
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

    print("=== find vhosts ===")
    print(run("find /www /etc/nginx -iname '*zinesh*' 2>/dev/null | head -50"))
    print(run("grep -RIl 'server_name zinesh.com' /www /etc/nginx 2>/dev/null | head -20"))

    print("=== curl follow apex auth ===")
    print(
        run(
            "curl -sk -X POST 'https://zinesh.com/api/auth.php' "
            "-H 'Content-Type: application/json' -H 'Origin: https://zinesh.com' "
            "-d '{\"action\":\"login\",\"email\":\"hakikatinaslani@gmail.com\",\"password\":\"x\"}' "
            "-L -w '\\nHTTP=%{http_code} url=%{url_effective} redirects=%{num_redirects}\\n'"
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
