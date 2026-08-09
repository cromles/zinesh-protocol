#!/usr/bin/env python3
"""Run S1/S1.1 escrow tests on remote server (/tmp copy, production untouched)."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

ROOT = Path(__file__).resolve().parents[1]
API = ROOT / "api"
REMOTE_TMP = "/tmp/zinesh_s1_tests"
TESTS = (
    "e2e-inv-s1-1-employer-settlement-guard-test.php",
    "e2e-inv-a4-settlement-recovery-test.php",
    "e2e-escrow-memory-test.php",
    "e2e-actor-trust-test.php",
    "e2e-ai-context-test.php",
)


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str]:
    _, stdout, stderr = ssh.exec_command(cmd)
    code = stdout.channel.recv_exit_status()
    text = (stdout.read().decode("utf-8", "replace") + stderr.read().decode("utf-8", "replace")).strip()
    return code, text


def main() -> int:
    host = require_deploy_host()
    password = require_deploy_pass()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{host} ...")
    ssh.connect(host, username=USER, password=password, timeout=25)
    try:
        run(ssh, f"rm -rf {REMOTE_TMP} && mkdir -p {REMOTE_TMP}/scripts")
        sftp = ssh.open_sftp()

        def put_tree(local_dir: Path, remote_dir: str) -> None:
            for item in local_dir.iterdir():
                remote_path = f"{remote_dir}/{item.name}"
                if item.is_dir():
                    try:
                        sftp.mkdir(remote_path)
                    except OSError:
                        pass
                    put_tree(item, remote_path)
                elif item.suffix == ".php":
                    sftp.put(str(item), remote_path)

        put_tree(API, REMOTE_TMP)
        sftp.close()

        failed: list[str] = []
        for script in TESTS:
            print(f"\n=== {script} ===")
            code, text = run(ssh, f"php {REMOTE_TMP}/scripts/{script} 2>&1")
            try:
                print(text)
            except UnicodeEncodeError:
                print(text.encode("ascii", errors="replace").decode("ascii"))
            if code != 0:
                failed.append(script)

        if failed:
            print("\nFAILED:", ", ".join(failed))
            return 1
        print("\nAll tests passed.")
        return 0
    finally:
        run(ssh, f"rm -rf {REMOTE_TMP}")
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
