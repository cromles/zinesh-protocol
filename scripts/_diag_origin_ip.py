"""Check which origin IP serves www/app/api — old vs new VPS."""
from __future__ import annotations

import json
import os
import socket

import paramiko

NEW_IP = "193.164.6.95"
HOSTS = ["www.zinesh.com", "zinesh.com", "app.zinesh.com"]


def dns_lookup(hostname: str) -> list[str]:
    try:
        infos = socket.getaddrinfo(hostname, 443, type=socket.SOCK_STREAM)
        ips = sorted({i[4][0] for i in infos})
        return ips
    except Exception as e:
        return [f"err:{e}"]


def main() -> int:
    print("=== Local DNS resolution ===")
    for h in HOSTS:
        print(f"{h} -> {', '.join(dns_lookup(h))}")

    password = os.environ.get("ZINESH_DEPLOY_PASS", "")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(NEW_IP, username="root", password=password, timeout=25)

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out if out.strip() else err

    print("\n=== New VPS self identity ===")
    print(run("hostname; hostname -I; curl -sk https://ifconfig.me; echo; date -u"))

    print("\n=== What IP does this VPS see for www/api ===")
    print(
        run(
            "getent ahostsv4 www.zinesh.com | head -5; "
            "getent ahostsv4 zinesh.com | head -5; "
            "getent ahostsv4 app.zinesh.com | head -5"
        )
    )

    print("\n=== Origin marks (unique file on NEW VPS only) ===")
    marker = f"zinesh-origin-{NEW_IP}"
    php = f"""<?php
header('Content-Type: application/json');
header('X-Zinesh-Origin: {NEW_IP}');
echo json_encode([
  'ok' => true,
  'origin' => '{NEW_IP}',
  'server_addr' => $_SERVER['SERVER_ADDR'] ?? null,
  'host' => $_SERVER['HTTP_HOST'] ?? null,
  'marker' => '{marker}',
  'time' => date('c'),
], JSON_UNESCAPED_UNICODE);
"""
    sftp = ssh.open_sftp()
    with sftp.file("/www/wwwroot/zinesh.com/api/origin_probe.php", "w") as f:
        f.write(php)
    sftp.close()
    print(run("ls -la /www/wwwroot/zinesh.com/api/origin_probe.php"))

    print("\n=== Probe origin via localhost Host headers ===")
    for host in ("www.zinesh.com", "zinesh.com"):
        print(f"-- local {host}")
        print(
            run(
                f"curl -sk 'https://127.0.0.1/api/origin_probe.php' "
                f"-H 'Host: {host}' --resolve {host}:443:127.0.0.1"
            )
        )

    print("\n=== Probe origin via PUBLIC URLs (Cloudflare edge) ===")
    for url in (
        "https://www.zinesh.com/api/origin_probe.php",
        "https://zinesh.com/api/origin_probe.php",
        "https://app.zinesh.com/api/origin_probe.php",
    ):
        print(f"-- {url}")
        print(
            run(
                f"curl -sk -D - -o /tmp/op.out '{url}' --max-redirs 5 "
                f"-w '\\nfinal=%{{url_effective}} http=%{{http_code}}\\n' | head -30; "
                f"echo BODY:; cat /tmp/op.out; echo"
            )
        )

    print("\n=== Cloudflare / nginx upstream clues ===")
    print(
        run(
            "grep -n 'server_name\\|proxy_pass\\|193\\.|root ' /etc/nginx/sites-enabled/zinesh.com | head -40"
        )
    )
    print(
        run(
            "ls -la /etc/nginx/sites-enabled/; "
            "grep -RIl '193\\.' /etc/nginx 2>/dev/null | head -20; "
            "grep -RIl 'proxy_pass' /etc/nginx/sites-enabled 2>/dev/null | head -20"
        )
    )

    print("\n=== auth.php file mtime on NEW VPS ===")
    print(
        run(
            "stat -c '%y %n' /www/wwwroot/zinesh.com/api/auth.php "
            "/www/wwwroot/zinesh.com/api/config.local.php "
            "/www/wwwroot/zinesh.com/assets/main-*.js 2>/dev/null | tail -20"
        )
    )

    print("\n=== recent real user logins (IPs) ===")
    print(
        run(
            r"""php -r '
$a=json_decode(file_get_contents("/www/server/zinesh-data/audit_log.json"), true);
$n=0;
foreach ((is_array($a)?$a:[]) as $r) {
  $act=$r["action"]??"";
  if ($act!=="login" && $act!=="login_failed" && $act!=="login_google") continue;
  echo ($r["at"]??"")." ".$act." ip=".($r["ip"]??"")."\n";
  if (++$n>=15) break;
}'
"""
        )
    )

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
