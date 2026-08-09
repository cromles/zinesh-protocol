#!/usr/bin/env python3
"""P0: verification_lib + escrow_room_lib hotfix deploy."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

ROOT = Path(__file__).resolve().parents[1]
FILES = [
    ROOT / "api" / "verification_lib.php",
    ROOT / "api" / "escrow_room_lib.php",
]


def main() -> int:
    host = require_deploy_host()
    pw = require_deploy_pass()
    remote_api = "/www/wwwroot/zinesh.com/api"

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=USER, password=pw, timeout=30)
    sftp = ssh.open_sftp()
    for path in FILES:
        if not path.is_file():
            print(f"MISSING {path}")
            return 1
        remote = f"{remote_api}/{path.name}"
        print(f"upload {path.name} -> {remote}")
        sftp.put(str(path), remote)
    sftp.close()

    verify_php = r"""<?php
require '/www/wwwroot/zinesh.com/api/verification_lib.php';
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
$u = zinesh_find_user_by_ticket('22595');
$v = zinesh_contract_verification_status($u ?? []);
echo 'kyc_gate=' . (zinesh_contract_kyc_gate_enabled() ? '1' : '0') . PHP_EOL;
echo 'verification_ok=' . ($v['ok'] ? '1' : '0') . PHP_EOL;
echo 'missing=' . implode(',', $v['missing']) . PHP_EOL;
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_verify_deploy.php", "w") as f:
        f.write(verify_php)
    sftp.close()
    _, stdout, _ = ssh.exec_command("php /tmp/z_verify_deploy.php 2>&1; rm -f /tmp/z_verify_deploy.php")
    print(stdout.read().decode("utf-8", errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
