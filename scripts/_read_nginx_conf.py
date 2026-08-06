from __future__ import annotations

import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass


def run(ssh, cmd: str) -> str:
    _, stdout, stderr = ssh.exec_command(cmd)
    return stdout.read().decode("utf-8", errors="replace") or stderr.read().decode("utf-8", errors="replace")


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    try:
        for f in (
            "/etc/nginx/sites-available/zinesh.com.conf",
            "/etc/nginx/sites-available/app.zinesh.com.conf",
        ):
            print("===", f, "===")
            print(run(ssh, f"cat {f}"))
        print("=== simulate 405 paths ===")
        tests = [
            "curl -s -o /dev/null -w 'GET_apex=%{http_code}\\n' http://127.0.0.1/ -H 'Host: zinesh.com'",
            "curl -s -o /dev/null -w 'POST_apex=%{http_code}\\n' -X POST http://127.0.0.1/ -H 'Host: zinesh.com'",
            "curl -s -o /dev/null -w 'GET_www=%{http_code}\\n' http://127.0.0.1/ -H 'Host: www.zinesh.com'",
            "curl -s -o /dev/null -w 'POST_api_apex=%{http_code}\\n' -X POST http://127.0.0.1/api/auth.php -H 'Host: zinesh.com' -H 'Content-Type: application/json' -d '{\"action\":\"status\"}'",
            "curl -s -o /dev/null -w 'POST_api_www=%{http_code}\\n' -X POST http://127.0.0.1/api/auth.php -H 'Host: www.zinesh.com' -H 'Content-Type: application/json' -d '{\"action\":\"status\"}'",
        ]
        for t in tests:
            print(run(ssh, t).strip())
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
