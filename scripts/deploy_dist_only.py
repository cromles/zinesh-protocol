#!/usr/bin/env python3
"""Deploy dist/ to www + app without full deploy_live gate."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

from deploy_common import connect_deploy_ssh

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / "dist"
REMOTE_WWW = os.environ.get("ZINESH_WWW_ROOT", "/www/wwwroot/zinesh.com")
REMOTE_APP = os.environ.get("ZINESH_APP_ROOT", "/www/wwwroot/app.zinesh.com")


def upload_tree(sftp: paramiko.SFTPClient, local: Path, remote: str) -> int:
    count = 0
    for path in local.rglob("*"):
        if path.is_dir():
            continue
        rel = path.relative_to(local).as_posix()
        remote_path = f"{remote}/{rel}"
        remote_dir = os.path.dirname(remote_path)
        parts = remote_dir.split("/")
        cur = ""
        for part in parts:
            if not part:
                continue
            cur += f"/{part}"
            try:
                sftp.stat(cur)
            except OSError:
                try:
                    sftp.mkdir(cur)
                except OSError:
                    pass
        sftp.put(str(path), remote_path)
        count += 1
    return count


def main() -> int:
    if not DIST.is_dir():
        print("dist/ missing — run npm run build first")
        return 1
    ssh = connect_deploy_ssh(timeout=60)
    sftp = ssh.open_sftp()
    n1 = upload_tree(sftp, DIST, REMOTE_WWW)
    n2 = upload_tree(sftp, DIST, REMOTE_APP)
    sftp.close()
    ssh.close()
    print(f"uploaded {n1} files -> {REMOTE_WWW}")
    print(f"uploaded {n2} files -> {REMOTE_APP}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
