"""Fix nginx: zinesh.com (apex) → 301 www.zinesh.com for all requests."""
from __future__ import annotations

import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

HOST = require_deploy_host()
PASSWORD = require_deploy_pass()
REMOTE_WWW = "/www/wwwroot/zinesh.com"
CONF_PATH = "/etc/nginx/sites-available/zinesh.com.conf"
PHP_SOCK = "/run/php/php8.3-fpm.sock"


def nginx_conf() -> str:
    return f"""# Apex host — always redirect to www (POST / apex was 405 on static root)
server {{
    listen 80;
    listen [::]:80;
    server_name zinesh.com;
    return 301 https://www.zinesh.com$request_uri;
}}

# Canonical site + API
server {{
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name www.zinesh.com {HOST};
    root {REMOTE_WWW};
    index index.html;

    location ^~ /api/data/ {{
        deny all;
        return 404;
    }}

    location ~ ^/api/.+\\.php$ {{
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }}

    location / {{
        try_files $uri $uri/ /index.html;
    }}

    location ~* \\.(js|css|png|jpg|jpeg|webp|svg|ico|woff2?|webmanifest)$ {{
        expires 7d;
        add_header Cache-Control "public, max-age=604800";
    }}
}}
"""


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str]:
    _, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, (out or err).strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{HOST} ...")
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file(CONF_PATH, "w") as f:
            f.write(nginx_conf())
        sftp.close()
        print(f"Wrote {CONF_PATH}")

        for cmd in (
            "nginx -t",
            "systemctl reload nginx",
        ):
            code, out = run(ssh, cmd)
            print(f"$ {cmd}\n{out}")
            if code != 0:
                print(f"FAILED ({code})")
                return code

        probes = [
            "curl -s -o /dev/null -w 'apex_get=%{http_code} loc=%{redirect_url}\\n' http://127.0.0.1/ -H 'Host: zinesh.com'",
            "curl -s -o /dev/null -w 'apex_post=%{http_code} loc=%{redirect_url}\\n' -X POST http://127.0.0.1/ -H 'Host: zinesh.com'",
            "curl -s -o /dev/null -w 'www_get=%{http_code}\\n' http://127.0.0.1/ -H 'Host: www.zinesh.com'",
            "curl -s -o /dev/null -w 'apex_api_post=%{http_code}\\n' -X POST http://127.0.0.1/api/auth.php -H 'Host: zinesh.com' -H 'Content-Type: application/json' -d '{\"action\":\"status\"}'",
        ]
        print("=== Probes ===")
        for cmd in probes:
            _, out = run(ssh, cmd)
            print(out)

        print("Apex nginx redirect OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
