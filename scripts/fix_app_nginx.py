"""Restore app.zinesh.com vhost and remove hostname from axium config."""
from __future__ import annotations

import re
import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

HOST = require_deploy_host()
PASSWORD = require_deploy_pass()
REMOTE_ROOT = "/www/wwwroot/app.zinesh.com"

APP_CONF = f"""server {{
    listen 80;
    listen [::]:80;
    server_name app.zinesh.com;
    root {REMOTE_ROOT};
    index index.html;

    location / {{
        try_files $uri $uri/ /index.html;
    }}

    location ~* \\.(js|css|png|jpg|jpeg|webp|svg|ico|woff2?|webmanifest)$ {{
        expires 1y;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }}
}}
"""


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str, str]:
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def patch_axium_server_names(text: str) -> str:
    def repl(match: re.Match[str]) -> str:
        names = [n.strip() for n in match.group(1).split() if n.strip()]
        names = [n for n in names if n != "app.zinesh.com"]
        return "server_name " + " ".join(names) + ";"

    return re.sub(r"server_name\s+([^;]+);", repl, text)


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/etc/nginx/sites-available/app.zinesh.com.conf", "w") as f:
            f.write(APP_CONF)

        for path in ("/etc/nginx/sites-available/axium.com.tr", "/etc/nginx/sites-enabled/axium.com.tr"):
            try:
                with sftp.file(path, "r") as f:
                    text = f.read().decode("utf-8", errors="replace")
                new = patch_axium_server_names(text)
                if new != text:
                    with sftp.file(path, "w") as wf:
                        wf.write(new)
                    print(f"patched {path}")
            except FileNotFoundError:
                pass
        sftp.close()

        for cmd in (
            "rm -f /etc/nginx/sites-enabled/app.zinesh.com.conf",
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

        code, out, err = run(ssh, "grep -R \"app.zinesh.com\" -n /etc/nginx/sites-available /etc/nginx/sites-enabled || true")
        print(out)

        code, out, err = run(ssh, "curl -s -H 'Host: app.zinesh.com' http://127.0.0.1/ | head -n 6")
        print(out)
        if "Zinesh" not in out:
            return 2
        print("RESTORE OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
