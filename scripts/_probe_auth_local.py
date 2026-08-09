"""Test auth endpoints on server localhost."""
from __future__ import annotations

import json
import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass


def curl_auth(ssh: paramiko.SSHClient, body: dict) -> str:
    payload = json.dumps(body, ensure_ascii=False)
    cmd = (
        "curl -s -w '\\nhttp=%{http_code}' -X POST http://127.0.0.1/api/auth.php "
        "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
        f"-d {json.dumps(payload)}"
    )
    _, out, err = ssh.exec_command(cmd)
    return (out.read().decode("utf-8", errors="replace") + err.read().decode("utf-8", errors="replace")).strip()


def main() -> int:
    host = require_deploy_host()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=USER, password=require_deploy_pass(), timeout=25)
    tests = [
        ("forgot_send", {"action": "forgot_password_send", "email": "yasinkarademir147@gmail.com"}),
        ("login", {"action": "login", "email": "yasinkarademir147@gmail.com", "password": "qUJqwMK9@Snw8U"}),
    ]
    for name, body in tests:
        print(f"=== {name} ===")
        print(curl_auth(ssh, body))
        print()
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
