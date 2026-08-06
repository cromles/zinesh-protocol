"""Add HTTPS vhost for app.zinesh.com and obtain certificate."""
from __future__ import annotations

import sys

import paramiko

from deploy_common import USER, require_certbot_email, require_deploy_host, require_deploy_pass

HOST = require_deploy_host()
PASSWORD = require_deploy_pass()
CERTBOT_EMAIL = require_certbot_email()
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


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    try:
        # HTTP-only first for certbot standalone/webroot or nginx plugin
        http_only = APP_CONF.split("server {", 1)[0] + "server {" + APP_CONF.split("server {", 2)[1]
        sftp = ssh.open_sftp()
        with sftp.file("/etc/nginx/sites-available/app.zinesh.com.conf", "w") as f:
            f.write(http_only)
        sftp.close()

        for cmd in (
            "ln -sf /etc/nginx/sites-available/app.zinesh.com.conf /etc/nginx/sites-enabled/00-app.zinesh.com.conf",
            "rm -f /etc/nginx/sites-enabled/app.zinesh.com.conf",
            "nginx -t",
            "systemctl reload nginx",
        ):
            code, out, err = run(ssh, cmd)
            if out:
                print(out, end="" if out.endswith("\n") else "\n")
            if err:
                print(err)
            if code != 0:
                return code

        code, out, err = run(
            ssh,
            f"certbot certonly --nginx -d app.zinesh.com --non-interactive --agree-tos -m {CERTBOT_EMAIL} || certbot certonly --webroot -w "
            + REMOTE_ROOT
            + f" -d app.zinesh.com --non-interactive --agree-tos -m {CERTBOT_EMAIL}",
        )
        print(out)
        if err:
            print(err)
        if code != 0:
            print("certbot failed")
            return code

        sftp = ssh.open_sftp()
        with sftp.file("/etc/nginx/sites-available/app.zinesh.com.conf", "w") as f:
            f.write(APP_CONF)
        sftp.close()

        for cmd in ("nginx -t", "systemctl reload nginx"):
            code, out, err = run(ssh, cmd)
            print(out or err)
            if code != 0:
                return code

        code, out, err = run(
            ssh,
            "curl -sk -H 'Host: app.zinesh.com' https://127.0.0.1/ | head -n 8",
        )
        print(out)
        if "main-" not in out and "Zinesh" not in out:
            return 2
        print("HTTPS APP OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
