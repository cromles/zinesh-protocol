#!/usr/bin/env python3
"""Install rclone on production, deploy GDrive backup libs, run manual test."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from deploy_common import require_deploy_host, require_deploy_pass  # noqa: E402

API_REMOTE = "/www/wwwroot/zinesh.com/api"
FILES = [
    ("api/backup_gdrive_lib.php", f"{API_REMOTE}/backup_gdrive_lib.php"),
    ("api/backup_lib.php", f"{API_REMOTE}/backup_lib.php"),
    ("api/scripts/backup-gdrive-test.php", f"{API_REMOTE}/scripts/backup-gdrive-test.php"),
]


def run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 300) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def main() -> int:
    host = require_deploy_host()
    password = require_deploy_pass()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username="root", password=password, timeout=30)

    sftp = ssh.open_sftp()
    for local_rel, remote in FILES:
        local = ROOT / local_rel
        sftp.put(str(local), remote)
        print(f"uploaded {local_rel} -> {remote}")
    sftp.close()

    code, out, err = run(
        ssh,
        "command -v rclone >/dev/null 2>&1 || curl -fsSL https://rclone.org/install.sh | bash",
        timeout=600,
    )
    print("=== rclone install ===")
    print(out or err)
    if code != 0 and "already installed" not in (out + err).lower():
        print(f"install exit={code}", file=sys.stderr)

    run(ssh, "mkdir -p /root/.config/rclone && chmod 700 /root/.config/rclone")
    run(ssh, "test -f /root/.config/rclone/rclone.conf && chmod 600 /root/.config/rclone/rclone.conf || true")

    code, out, err = run(ssh, "rclone version 2>&1 | head -1")
    print("=== rclone version ===")
    print(out.strip())

    code, out, err = run(ssh, "test -f /root/.config/rclone/rclone.conf && rclone listremotes --config /root/.config/rclone/rclone.conf 2>&1 || echo NO_CONFIG")
    print("=== listremotes ===")
    print(out.strip() or err.strip())

    code, out, err = run(
        ssh,
        f"php {API_REMOTE}/scripts/backup-gdrive-test.php 2026-08-09_11-14 2>&1",
        timeout=900,
    )
    print("=== backup-gdrive-test ===")
    print(out)
    if err:
        print(err, file=sys.stderr)
    print(f"test exit={code}")

    ssh.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
