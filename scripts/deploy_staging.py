"""Deploy frontend dist only to staging.zinesh.com — never touches production www/app."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

PASSWORD = require_deploy_pass()
DEPLOY_HOST = require_deploy_host()
REMOTE_STAGING = "/www/wwwroot/staging.zinesh.com"

ROOT = Path(__file__).resolve().parents[1]
LOCAL_DIST = ROOT / "dist"


def upload_tree(sftp: paramiko.SFTPClient, local_dir: Path, remote_dir: str) -> None:
    for path in local_dir.rglob("*"):
        rel = path.relative_to(local_dir).as_posix()
        remote_path = f"{remote_dir}/{rel}" if rel != "." else remote_dir
        if path.is_dir():
            try:
                sftp.mkdir(remote_path)
            except OSError:
                pass
        else:
            sftp.put(str(path), remote_path)


def main() -> int:
    if not LOCAL_DIST.is_dir():
        print(f"dist/ missing — run: npm run build:staging ({LOCAL_DIST})")
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{DEPLOY_HOST} ...")
    ssh.connect(DEPLOY_HOST, username=USER, password=PASSWORD, timeout=25)

    try:
        _, stdout, _ = ssh.exec_command(f"test -d {REMOTE_STAGING} && echo ok || echo missing")
        if "ok" not in stdout.read().decode():
            print(f"Staging path missing: {REMOTE_STAGING}")
            return 1

        sftp = ssh.open_sftp()
        print(f"=== Staging frontend {LOCAL_DIST} -> {REMOTE_STAGING} ===")
        upload_tree(sftp, LOCAL_DIST, REMOTE_STAGING)
        sftp.close()

        _, stdout, stderr = ssh.exec_command(
            "curl -s -o /dev/null -w 'staging=%{http_code}\\n' https://staging.zinesh.com/"
        )
        print(stdout.read().decode().strip() or stderr.read().decode().strip())
        print("Staging deploy OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
