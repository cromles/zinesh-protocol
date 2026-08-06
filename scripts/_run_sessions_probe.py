"""Probe sessions and nginx errors on VPS."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    remote = "/tmp/_probe_sessions_errors.php"
    sftp = ssh.open_sftp()
    sftp.put(str(ROOT / "scripts" / "_probe_sessions_errors.php"), remote)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(f"php {remote}")
    print(stdout.read().decode("utf-8", errors="replace"))
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("STDERR:", err)
    ssh.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
