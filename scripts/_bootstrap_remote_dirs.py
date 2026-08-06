"""Bootstrap remote web roots on fresh VPS, then upload Zinesh."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

PASSWORD = require_deploy_pass()
DEPLOY_HOST = require_deploy_host()
REMOTE_WWW = os.environ.get("ZINESH_WWW_ROOT", "/www/wwwroot/zinesh.com")
REMOTE_APP = os.environ.get("ZINESH_APP_ROOT", "/www/wwwroot/app.zinesh.com")
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", f"{REMOTE_WWW}/api")

ROOT = Path(__file__).resolve().parents[1]
LOCAL_DIST = ROOT / "dist"


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{DEPLOY_HOST} ...")
    ssh.connect(DEPLOY_HOST, username=USER, password=PASSWORD, timeout=25)
    try:
        cmds = [
            "uname -a",
            "which nginx || which apache2 || echo no_web_server",
            "php -v 2>/dev/null | head -1 || echo no_php",
            f"mkdir -p {REMOTE_WWW} {REMOTE_APP} {REMOTE_API}/data {REMOTE_API}/scripts",
            f"chmod -R 755 {REMOTE_WWW} {REMOTE_APP} {REMOTE_API}",
            f"test -d {REMOTE_WWW} && echo www_ok",
            f"test -d {REMOTE_APP} && echo app_ok",
            f"test -d {REMOTE_API} && echo api_ok",
        ]
        for cmd in cmds:
            code, out, err = run(ssh, cmd)
            line = (out or err).strip()
            if line:
                print(line)
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
