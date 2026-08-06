"""Verify founder password variants on production."""
from __future__ import annotations

import json
import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass

EMAIL = "yasinkarademir147@gmail.com"
CANDIDATES = [
    "5D5vNZWYkNsb",
    "Rg263bxW3ikGDi",
    "Rg263bxW3ikGDI",
]


def main() -> None:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    try:
        for pwd in CANDIDATES:
            probe = json.dumps({"action": "login", "email": EMAIL, "password": pwd})
            cmd = (
                "curl -s -o /tmp/p.json -w 'http=%{http_code}' "
                "-X POST http://127.0.0.1/api/auth.php "
                "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
                f"-d {json.dumps(probe)}; echo; cat /tmp/p.json | head -c 220; echo"
            )
            _, out, _ = ssh.exec_command(cmd)
            print(f"--- password={pwd!r} ---")
            print(out.read().decode().strip())
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
