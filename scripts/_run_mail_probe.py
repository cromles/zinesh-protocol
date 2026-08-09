"""Probe production mail + user (no secrets printed)."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass

ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    host = require_deploy_host()
    pw = require_deploy_pass()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=USER, password=pw, timeout=25)
    sftp = ssh.open_sftp()
    remote = "/tmp/_probe_mail_status.php"
    sftp.put(str(ROOT / "scripts" / "_probe_mail_status.php"), remote)
    sftp.close()
    email = sys.argv[1] if len(sys.argv) > 1 else ""
    env = f"PROBE_EMAIL={email} " if email else ""
    _, out, err = ssh.exec_command(f"{env}php {remote}; rm -f {remote}")
    print(out.read().decode("utf-8", errors="replace"))
    e = err.read().decode("utf-8", errors="replace")
    if e.strip():
        print("ERR", e)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
