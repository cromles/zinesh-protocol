"""Fix nginx: apex redirect, rate limits, cache headers, PHP-FPM timeouts."""
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
    return f"""# Cloudflare gerçek istemci IP (rate limit için)
map $http_cf_connecting_ip $zinesh_client_ip {{
    default $binary_remote_addr;
    "~."    $http_cf_connecting_ip;
}}

limit_req_zone $zinesh_client_ip zone=zinesh_api:20m rate=25r/s;
limit_req_zone $zinesh_client_ip zone=zinesh_page:20m rate=40r/s;
limit_conn_zone $zinesh_client_ip zone=zinesh_conn:20m;
limit_req_status 429;

# Apex host — always redirect to www (POST / apex was 405 on static root)
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

    client_body_timeout 12s;
    send_timeout 15s;
    keepalive_timeout 20s;

    location ^~ /api/data/ {{
        deny all;
        return 404;
    }}

    location ~ ^/api/.+\\.php$ {{
        limit_req zone=zinesh_api burst=50 nodelay;
        limit_conn zinesh_conn 30;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{PHP_SOCK};
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_connect_timeout 10s;
        fastcgi_send_timeout 30s;
        fastcgi_read_timeout 30s;
        fastcgi_buffer_size 32k;
        fastcgi_buffers 8 32k;
        add_header Cache-Control "no-store" always;
    }}

    location = /robots.txt {{
        limit_req zone=zinesh_page burst=20 nodelay;
        try_files $uri =404;
        expires 1d;
        add_header Cache-Control "public, max-age=86400" always;
    }}

    location = /llms.txt {{
        limit_req zone=zinesh_page burst=20 nodelay;
        try_files $uri =404;
        expires 1d;
        add_header Cache-Control "public, max-age=86400" always;
    }}

    location = /sitemap.xml {{
        limit_req zone=zinesh_page burst=20 nodelay;
        try_files $uri =404;
        expires 1h;
        add_header Cache-Control "public, max-age=3600" always;
    }}

    location = /index.html {{
        limit_req zone=zinesh_page burst=60 nodelay;
        try_files $uri =404;
        add_header Cache-Control "public, max-age=300, stale-while-revalidate=600" always;
        add_header X-Robots-Tag "index, follow" always;
    }}

    location / {{
        limit_req zone=zinesh_page burst=80 nodelay;
        limit_conn zinesh_conn 40;
        try_files $uri $uri/ /index.html;
        add_header X-Robots-Tag "index, follow" always;
    }}

    location ~* \\.(js|css|png|jpg|jpeg|webp|svg|ico|woff2?|webmanifest)$ {{
        limit_req zone=zinesh_page burst=120 nodelay;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000, immutable" always;
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
            "curl -s -o /dev/null -w 'www_get=%{http_code}\\n' http://127.0.0.1/ -H 'Host: www.zinesh.com'",
            "curl -s -o /dev/null -w 'api_post=%{http_code}\\n' -X POST http://127.0.0.1/api/auth.php -H 'Host: www.zinesh.com' -H 'Content-Type: application/json' -d '{\"action\":\"oauth_config\"}'",
        ]
        print("=== Probes ===")
        for cmd in probes:
            _, out = run(ssh, cmd)
            print(out)

        print("Apex nginx (rate limit + cache) OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
