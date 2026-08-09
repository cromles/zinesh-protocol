"""Deploy dist/ to app.zinesh.com vhost on production VPS (HTTP + HTTPS)."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

from deploy_common import (
    USER,
    require_deploy_host,
    require_deploy_pass,
    require_intelligence_regression_pass,
)

HOST = require_deploy_host()
PASSWORD = require_deploy_pass()
REMOTE_ROOT = os.environ.get("ZINESH_APP_ROOT", "/www/wwwroot/app.zinesh.com")
LOCAL_DIST = Path(__file__).resolve().parents[1] / "dist"

NGINX_CONF = f"""server {{
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

server {{
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name app.zinesh.com;

    root {REMOTE_ROOT};
    index index.html;

    ssl_certificate     /etc/letsencrypt/live/app.zinesh.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.zinesh.com/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;

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


def upload_tree(sftp: paramiko.SFTPClient, local_dir: Path, remote_dir: str) -> None:
    for path in local_dir.rglob("*"):
        rel = path.relative_to(local_dir).as_posix()
        remote_path = f"{remote_dir}/{rel}" if rel != "." else remote_dir
        if path.is_dir():
            try:
                sftp.mkdir(remote_path)
            except OSError:
                pass
        else:
            sftp.put(str(path), remote_path)


def main() -> int:
    require_intelligence_regression_pass()

    if not LOCAL_DIST.is_dir():
        print(f"dist/ missing — run: npm run build ({LOCAL_DIST})")
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{HOST} ...")
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    try:
        run(ssh, f"mkdir -p {REMOTE_ROOT}")
        print(f"Uploading {LOCAL_DIST} -> {REMOTE_ROOT}")
        sftp = ssh.open_sftp()
        upload_tree(sftp, LOCAL_DIST, REMOTE_ROOT)
        conf_path = "/etc/nginx/sites-available/app.zinesh.com.conf"
        with sftp.file(conf_path, "w") as f:
            f.write(NGINX_CONF)
        sftp.close()

        for cmd in (
            f"ln -sf {conf_path} /etc/nginx/sites-enabled/00-app.zinesh.com.conf",
            "rm -f /etc/nginx/sites-enabled/app.zinesh.com.conf /etc/nginx/sites-enabled/app.zinesh.com",
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

        for probe in (
            "curl -s -o /dev/null -w 'app_http=%{http_code}\\n' -H 'Host: app.zinesh.com' http://127.0.0.1/",
            "curl -sk -o /dev/null -w 'app_https=%{http_code}\\n' -H 'Host: app.zinesh.com' https://127.0.0.1/",
            "curl -sk -H 'Host: app.zinesh.com' https://127.0.0.1/ | grep -oE 'main-[A-Za-z0-9_-]+\\.js|Open Agent Mesh' | head -3",
        ):
            code, out, err = run(ssh, probe)
            print(out.strip())
            if err.strip():
                print(err.strip())

        print("app.zinesh.com static deploy OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
