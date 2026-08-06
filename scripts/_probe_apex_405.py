"""Probe apex vs www HTTP responses on VPS."""
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
            "curl -sI https://zinesh.com/ | head -20",
            "curl -sI https://www.zinesh.com/ | head -20",
            "curl -sI http://zinesh.com/ | head -20",
            "curl -sI http://127.0.0.1/ -H 'Host: zinesh.com' | head -20",
            "curl -sI http://127.0.0.1/ -H 'Host: www.zinesh.com' | head -20",
            "ls -la /www/server/panel/vhost/nginx/ 2>/dev/null | head -20 || ls -la /etc/nginx/sites-enabled/ 2>/dev/null | head -20",
            "grep -R \"server_name\" /www/server/panel/vhost/nginx/*zinesh* 2>/dev/null | head -30",
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
