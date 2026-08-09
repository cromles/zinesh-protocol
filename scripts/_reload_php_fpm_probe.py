"""Reload PHP-FPM after deploy and probe services."""
from __future__ import annotations

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass


def run(ssh: paramiko.SSHClient, cmd: str) -> str:
    _, stdout, stderr = ssh.exec_command(cmd)
    return (stdout.read() or stderr.read()).decode("utf-8", errors="replace").strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    try:
        for cmd in (
            "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php-fpm",
            "systemctl is-active nginx",
            "systemctl is-active php8.3-fpm 2>/dev/null || systemctl is-active php-fpm",
            "curl -s -o /dev/null -w 'api_post=%{http_code}\\n' -X POST http://127.0.0.1/api/auth.php "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' -d '{\"action\":\"oauth_config\"}'",
            "curl -sI -H 'Host: www.zinesh.com' http://127.0.0.1/robots.txt | grep -i cache-control",
        ):
            print(f"$ {cmd[:70]}...")
            print(run(ssh, cmd) or "(ok)")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
