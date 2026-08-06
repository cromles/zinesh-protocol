"""Deploy CORS-related API files to production VPS."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", "/www/wwwroot/zinesh.com/api")

ROOT = Path(__file__).resolve().parents[1]
FILES = [
    ROOT / "api" / "_bootstrap.php",
    ROOT / "api" / "config.php",
]


def main() -> int:
    missing = [str(p) for p in FILES if not p.is_file()]
    if missing:
        print("Missing files:", ", ".join(missing))
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{HOST} ...")
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    try:
        stdin, stdout, stderr = ssh.exec_command(f"test -d {REMOTE_API} && echo ok")
        if stdout.read().decode().strip() != "ok":
            print(f"Remote API dir not found: {REMOTE_API}")
            return 1

        sftp = ssh.open_sftp()
        for local in FILES:
            remote = f"{REMOTE_API}/{local.name}"
            print(f"Upload {local.name} -> {remote}")
            sftp.put(str(local), remote)
        sftp.close()

        test_cmd = (
            "curl -s -D - -o /dev/null -X OPTIONS "
            "-H 'Origin: https://app.zinesh.com' "
            "-H 'Access-Control-Request-Method: POST' "
            "https://www.zinesh.com/api/auth.php | tr -d '\\r' | grep -i access-control"
        )
        stdin, stdout, stderr = ssh.exec_command(test_cmd)
        out = stdout.read().decode("utf-8", errors="replace").strip()
        err = stderr.read().decode("utf-8", errors="replace").strip()
        code = stdout.channel.recv_exit_status()
        print("CORS preflight headers:")
        print(out or "(none)")
        if err:
            print(err)
        if code != 0:
            return code
        if "access-control-allow-origin" not in out.lower():
            print("WARNING: Allow-Origin header missing in preflight test")
            return 2
        print("API CORS deploy OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
