"""Deep probe apex 405 scenarios on VPS."""
from __future__ import annotations

import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

HOST = require_deploy_host()
PASSWORD = require_deploy_pass()


def run(ssh: paramiko.SSHClient, cmd: str) -> str:
    _, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return (out or err).strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    try:
        cmds = [
            "ls -la /etc/nginx/sites-enabled/",
            "grep -n 'server_name\\|return 301\\|listen 443\\|limit_except' /etc/nginx/sites-enabled/* 2>/dev/null | head -60",
            "curl -skI https://127.0.0.1/ -H 'Host: zinesh.com' | head -15",
            "curl -skI https://127.0.0.1/ -H 'Host: www.zinesh.com' | head -15",
            "curl -sk https://127.0.0.1/ -H 'Host: zinesh.com' | head -5",
            "curl -sI https://zinesh.com/ --resolve zinesh.com:443:127.0.0.1 -k | head -15",
            "curl -s -o /dev/null -w 'apex_origin_https=%{http_code}\\n' https://127.0.0.1/ -H 'Host: zinesh.com' -k",
            "curl -s -o /dev/null -w 'www_origin_https=%{http_code}\\n' https://127.0.0.1/ -H 'Host: www.zinesh.com' -k",
        ]
        for cmd in cmds:
            print("===", cmd, "===")
            print(run(ssh, cmd))
            print()
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
