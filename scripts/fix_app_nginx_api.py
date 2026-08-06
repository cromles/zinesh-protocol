"""app.zinesh.com: /api/* → yerel www PHP (Cloudflare çapraz-origin engelini aşar)."""
from __future__ import annotations

import re
import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

PASSWORD = require_deploy_pass()
DEPLOY_HOST = require_deploy_host()
REMOTE_ROOT = "/www/wwwroot/app.zinesh.com"
CONF_PATH = "/etc/nginx/sites-available/app.zinesh.com.conf"

API_LOCATIONS = """
    location ^~ /api/data/ {
        deny all;
        return 404;
    }

    location ^~ /api/ {
        proxy_pass http://127.0.0.1;
        proxy_http_version 1.1;
        proxy_set_header Host www.zinesh.com;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
"""


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str, str]:
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def inject_api_locations(conf: str) -> str:
    if "proxy_set_header Host www.zinesh.com" in conf:
        return conf

    def add_before_location_root(block: str) -> str:
        if "location ^~ /api/" in block:
            return block
        return re.sub(
            r"(\n    location / \{)",
            API_LOCATIONS + r"\1",
            block,
            count=1,
        )

    parts = re.split(r"(?=server \{)", conf)
    patched = [parts[0]]
    for part in parts[1:]:
        if "server_name app.zinesh.com" in part:
            patched.append(add_before_location_root(part))
        else:
            patched.append(part)
    return "".join(patched)


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{DEPLOY_HOST} ...")
    ssh.connect(DEPLOY_HOST, username=USER, password=PASSWORD, timeout=25)
    try:
        sftp = ssh.open_sftp()
        try:
            with sftp.file(CONF_PATH, "r") as f:
                conf = f.read().decode("utf-8", errors="replace")
        except FileNotFoundError:
            print(f"Missing {CONF_PATH}")
            return 1

        new_conf = inject_api_locations(conf)
        if new_conf == conf:
            print("app nginx already has /api proxy")
        else:
            with sftp.file(CONF_PATH, "w") as f:
                f.write(new_conf)
            print(f"Updated {CONF_PATH}")

        sftp.close()

        for cmd in (
            "ln -sf /etc/nginx/sites-available/app.zinesh.com.conf /etc/nginx/sites-enabled/00-app.zinesh.com.conf",
            "nginx -t",
            "systemctl reload nginx",
        ):
            code, out, err = run(ssh, cmd)
            if out:
                print(out, end="" if out.endswith("\n") else "\n")
            if err:
                print(err, end="" if err.endswith("\n") else "\n")
            if code != 0:
                return code

        code, out, err = run(
            ssh,
            "curl -s -o /dev/null -w 'app_api=%{http_code}\\n' "
            "-H 'Host: app.zinesh.com' "
            "-H 'Content-Type: application/json' "
            "-d '{\"action\":\"oauth_config\"}' "
            "http://127.0.0.1/api/auth.php",
        )
        print(out.strip() or err.strip())
        if "app_api=200" not in out and "app_api=400" not in out:
            print("WARN: app /api probe unexpected (expected 200/400 JSON)")
        else:
            print("app /api proxy OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
