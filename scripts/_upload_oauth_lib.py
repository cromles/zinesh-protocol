from deploy_common import USER, require_deploy_host, require_deploy_pass
"""Upload oauth_lib and confirm oauth_config mode."""
from __future__ import annotations

import os
from pathlib import Path

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
ROOT = Path(__file__).resolve().parents[1]


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    sftp.put(str(ROOT / "api" / "oauth_lib.php"), "/www/wwwroot/zinesh.com/api/oauth_lib.php")
    sftp.close()

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out + (("\nERR:\n" + err) if err.strip() else "")

    print(run("php -l /www/wwwroot/zinesh.com/api/oauth_lib.php"))
    print(
        run(
            "curl -sk -X POST 'https://127.0.0.1/api/auth.php' "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
            "--resolve www.zinesh.com:443:127.0.0.1 "
            "-d '{\"action\":\"oauth_config\"}'"
        )
    )
    print(run("grep -n 'force_redirect\\|mode' /www/wwwroot/zinesh.com/api/oauth_lib.php | head -20"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
