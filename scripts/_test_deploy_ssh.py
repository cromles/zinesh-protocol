"""Test SSH connectivity using secrets/deploy.local.env (no secrets printed)."""
from __future__ import annotations

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass


def main() -> None:
    host = require_deploy_host()
    password = require_deploy_pass()
    print(f"Connecting {USER}@{host} ...")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=USER, password=password, timeout=25)
    try:
        _, stdout, stderr = ssh.exec_command(
            "hostname; "
            "test -d /www/wwwroot && echo wwwroot_ok || echo wwwroot_missing; "
            "test -d /www/wwwroot/zinesh.com && echo zinesh_ok || echo zinesh_missing; "
            "test -d /www/wwwroot/app.zinesh.com && echo app_ok || echo app_missing"
        )
        out = stdout.read().decode(errors="replace").strip()
        err = stderr.read().decode(errors="replace").strip()
        if out:
            print(out)
        if err:
            print("stderr:", err)
    finally:
        ssh.close()
    print("SSH OK")


if __name__ == "__main__":
    main()
