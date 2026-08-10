"""Test SSH connectivity using secrets/deploy.local.env (no secrets printed)."""
from __future__ import annotations

from deploy_common import USER, connect_deploy_ssh, require_deploy_host, resolve_deploy_auth_method


def main() -> None:
    host = require_deploy_host()
    auth = resolve_deploy_auth_method()
    print(f"Connecting {USER}@{host} (auth={auth}) ...")
    ssh = connect_deploy_ssh(timeout=25)
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
    print(f"SSH OK (auth={auth})")


if __name__ == "__main__":
    main()
