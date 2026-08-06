"""Fresh VPS bootstrap: PHP-FPM, nginx vhosts, full Zinesh upload."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

PASSWORD = require_deploy_pass()
DEPLOY_HOST = require_deploy_host()
REMOTE_WWW = os.environ.get("ZINESH_WWW_ROOT", "/www/wwwroot/zinesh.com")
REMOTE_APP = os.environ.get("ZINESH_APP_ROOT", "/www/wwwroot/app.zinesh.com")
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", f"{REMOTE_WWW}/api")

ROOT = Path(__file__).resolve().parents[1]
LOCAL_DIST = ROOT / "dist"
LOCAL_API = ROOT / "api"


def run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 600) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
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
            parent = remote_path.rsplit("/", 1)[0]
            if parent and parent != remote_dir:
                try:
                    sftp.mkdir(parent)
                except OSError:
                    pass
            sftp.put(str(path), remote_path)


def nginx_www_conf(php_sock: str) -> str:
    return f"""server {{
    listen 80;
    listen [::]:80;
    server_name zinesh.com;
    return 301 https://www.zinesh.com$request_uri;
}}

server {{
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name www.zinesh.com {DEPLOY_HOST};
    root {REMOTE_WWW};
    index index.html;

    location ^~ /api/data/ {{
        deny all;
        return 404;
    }}

    location ~ ^/api/.+\\.php$ {{
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{php_sock};
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


def nginx_app_conf() -> str:
    return f"""server {{
    listen 80;
    listen [::]:80;
    server_name app.zinesh.com;
    root {REMOTE_APP};
    index index.html;

    location / {{
        try_files $uri $uri/ /index.html;
    }}

    location ~* \\.(js|css|png|jpg|jpeg|webp|svg|ico|woff2?|webmanifest)$ {{
        expires 7d;
        add_header Cache-Control "public, max-age=604800";
    }}
}}
"""


def main() -> int:
    if not LOCAL_DIST.is_dir():
        print("dist/ missing — run npm run build")
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{DEPLOY_HOST} ...")
    ssh.connect(DEPLOY_HOST, username=USER, password=PASSWORD, timeout=25)

    try:
        print("=== Install PHP + FPM ===")
        code, out, err = run(
            ssh,
            "export DEBIAN_FRONTEND=noninteractive; "
            "apt-get update -qq && "
            "apt-get install -y -qq nginx php-fpm php-cli php-mbstring php-xml php-curl php-json php-zip 2>&1 | tail -5",
        )
        print((out or err).strip())

        _, out, _ = run(ssh, "ls /run/php/php*-fpm.sock 2>/dev/null | head -1")
        php_sock = out.strip() or "/run/php/php8.3-fpm.sock"
        print("PHP socket:", php_sock)

        print("=== Directories ===")
        run(ssh, f"mkdir -p {REMOTE_WWW} {REMOTE_APP} {REMOTE_API}/data {REMOTE_API}/scripts")

        sftp = ssh.open_sftp()

        print("=== Upload API ===")
        skip_names = {".bak", ".tmp"}
        for path in sorted(LOCAL_API.rglob("*")):
            if path.is_dir():
                if path.name == "data":
                    continue
                rel = path.relative_to(LOCAL_API).as_posix()
                remote = f"{REMOTE_API}/{rel}"
                try:
                    sftp.mkdir(remote)
                except OSError:
                    pass
                continue
            if path.suffix == ".php" or path.name.endswith(".example.php"):
                rel = path.relative_to(LOCAL_API).as_posix()
                remote = f"{REMOTE_API}/{rel}"
                parent = remote.rsplit("/", 1)[0]
                try:
                    sftp.mkdir(parent)
                except OSError:
                    pass
                sftp.put(str(path), remote)
                print(" ", rel)

        print("=== Upload dist -> WWW + APP ===")
        upload_tree(sftp, LOCAL_DIST, REMOTE_WWW)
        upload_tree(sftp, LOCAL_DIST, REMOTE_APP)

        print("=== Nginx vhosts ===")
        with sftp.file("/etc/nginx/sites-available/zinesh.com.conf", "w") as f:
            f.write(nginx_www_conf(php_sock))
        with sftp.file("/etc/nginx/sites-available/app.zinesh.com.conf", "w") as f:
            f.write(nginx_app_conf())
        sftp.close()

        cmds = [
            f"test -f {REMOTE_API}/config.local.php || cp {REMOTE_API}/config.local.example.php {REMOTE_API}/config.local.php",
            f"chown -R www-data:www-data {REMOTE_WWW} {REMOTE_APP} {REMOTE_API}/data 2>/dev/null || true",
            f"chmod -R 775 {REMOTE_API}/data",
            "rm -f /etc/nginx/sites-enabled/default",
            "ln -sf /etc/nginx/sites-available/zinesh.com.conf /etc/nginx/sites-enabled/00-zinesh.com.conf",
            "ln -sf /etc/nginx/sites-available/app.zinesh.com.conf /etc/nginx/sites-enabled/01-app.zinesh.com.conf",
            "nginx -t",
            "systemctl enable php*-fpm nginx 2>/dev/null; systemctl restart php*-fpm nginx 2>/dev/null || systemctl restart nginx",
            f"curl -s -o /dev/null -w 'ip_www=%{{http_code}}\\n' -H 'Host: www.zinesh.com' http://127.0.0.1/",
            (
                f"curl -s -o /dev/null -w 'api=%{{http_code}}\\n' -H 'Host: www.zinesh.com' "
                f"-H 'Content-Type: application/json' -X POST http://127.0.0.1/api/wallet.php "
                "-d '{{\"action\":\"state\",\"sessionToken\":\"x\"}}'"
            ),
        ]
        print("=== Configure & probe ===")
        for cmd in cmds:
            code, out, err = run(ssh, cmd)
            line = (out or err).strip()
            if line:
                print(line)

        print("Bootstrap OK — DNS A kayıtlarını sunucu IP'nize yönlendirin (www + app).")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
