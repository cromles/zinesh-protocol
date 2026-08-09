"""Compare mail send as root, www-data, and HTTP."""
from __future__ import annotations

import json
import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass

PROBE = Path(__file__).resolve().parent / "_probe_mail_wwwdata.php"
REMOTE = "/tmp/_probe_mail_wwwdata.php"


def run(ssh: paramiko.SSHClient, label: str, cmd: str) -> None:
    print(f"=== {label} ===")
    _, out, err = ssh.exec_command(cmd)
    print(out.read().decode("utf-8", errors="replace"))
    e = err.read().decode("utf-8", errors="replace").strip()
    if e:
        print("ERR:", e)
    print()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    sftp = ssh.open_sftp()
    sftp.put(str(PROBE), REMOTE)
    sftp.close()

    email = "yasinkarademir147@gmail.com"
    run(ssh, "secrets_ls", "ls -la /www/server/zinesh-data/server_secrets.json 2>/dev/null; ls -la /www/wwwroot/zinesh.com/api/data/server_secrets.json 2>/dev/null")
    run(ssh, "data_path", "php -r \"require '/www/wwwroot/zinesh.com/api/_bootstrap.php'; echo zinesh_data_path('server_secrets.json');\"")
    run(ssh, "root_cli", f"PROBE_EMAIL={email} php {REMOTE}")
    run(ssh, "wwwdata_cli", f"sudo -u www-data PROBE_EMAIL={email} php {REMOTE}")

    body = json.dumps({"action": "forgot_password_send", "email": email}, ensure_ascii=False)
    run(
        ssh,
        "http_curl",
        "curl -s -w '\\nhttp=%{http_code}' -X POST http://127.0.0.1/api/auth.php "
        "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
        f"-d {json.dumps(body)}",
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
