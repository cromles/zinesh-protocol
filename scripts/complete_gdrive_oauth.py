#!/usr/bin/env python3
"""Write zinesh-drive token into /root/.config/rclone/rclone.conf (headless, v1.75+)."""
from __future__ import annotations

import io
import json
import os
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from deploy_common import require_deploy_host, require_deploy_pass  # noqa: E402

RCLONE_CONF = "/root/.config/rclone/rclone.conf"


def run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 120) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def load_oauth_token() -> str:
    raw = os.environ.get("ZINESH_RCLONE_OAUTH_TOKEN", "").strip()
    if not raw:
        print(
            "ERROR: ZINESH_RCLONE_OAUTH_TOKEN missing in secrets/deploy.local.env",
            file=sys.stderr,
        )
        sys.exit(1)
    try:
        parsed = json.loads(raw)
    except json.JSONDecodeError as exc:
        print(f"ERROR: invalid JSON: {exc}", file=sys.stderr)
        sys.exit(1)
    if not isinstance(parsed, dict) or "access_token" not in parsed:
        print("ERROR: token JSON must include access_token", file=sys.stderr)
        sys.exit(1)
    return json.dumps(parsed, separators=(",", ":"))


def build_rclone_conf(token_json: str) -> str:
    return (
        "[zinesh-drive]\n"
        "type = drive\n"
        "scope = drive\n"
        f"token = {token_json}\n"
    )


def main() -> int:
    token_json = load_oauth_token()
    host = require_deploy_host()
    password = require_deploy_pass()

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username="root", password=password, timeout=30)

    run(ssh, "mkdir -p /root/.config/rclone && chmod 700 /root/.config/rclone")

    sftp = ssh.open_sftp()
    with sftp.file(RCLONE_CONF, "w") as remote_file:
        remote_file.write(build_rclone_conf(token_json))
    sftp.chmod(RCLONE_CONF, 0o600)
    sftp.close()

    print("=== rclone.conf written (chmod 600) ===")

    code, out, err = run(ssh, f"rclone listremotes --config {RCLONE_CONF}")
    print("=== listremotes ===")
    print(out.strip())

    code, out, err = run(ssh, f"rclone lsd zinesh-drive: --config {RCLONE_CONF}")
    print("=== rclone lsd ===")
    print(out.strip() or "(empty — ok)")
    if code != 0:
        print(err.strip(), file=sys.stderr)
        ssh.close()
        return code

    _, mode_out, _ = run(ssh, f"stat -c '%a' {RCLONE_CONF}")
    print("=== rclone.conf mode ===")
    print(mode_out.strip())

    ssh.close()
    print("OK: zinesh-drive remote ready")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
