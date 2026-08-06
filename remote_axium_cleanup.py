from deploy_common import USER, require_deploy_host, require_deploy_pass
import os
import sys

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "").strip()
if not PASSWORD:
    sys.exit("ZINESH_DEPLOY_PASS required")


def run(ssh: paramiko.SSHClient, cmd: str) -> None:
    print(f"\n$ {cmd}")
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    if out:
        print(out, end="" if out.endswith("\n") else "\n")
    if err:
        print(err, end="" if err.endswith("\n") else "\n")
    print(f"[exit={code}]")


def main() -> None:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=20)

    try:
        # Baseline visibility.
        run(ssh, "hostnamectl --static || hostname")
        run(ssh, "ls -l /etc/nginx/sites-enabled")
        run(ssh, "ss -ltnp '( sport = :8787 )' || true")
        run(ssh, "ls -1 /etc/letsencrypt/live || true")
        run(ssh, "ls -1 /etc/letsencrypt/renewal || true")

        # Remove axium nginx site references.
        run(ssh, "bash -lc 'ls -l /etc/nginx/sites-enabled /etc/nginx/sites-available | awk \"/axium|zinesh/\" || true'")
        run(ssh, "bash -lc 'rm -f /etc/nginx/sites-enabled/*axium* /etc/nginx/sites-available/*axium*; true'")

        # Remove any explicit include snippets mentioning axium in nginx conf tree.
        run(ssh, "bash -lc 'rm -f /etc/nginx/conf.d/*axium* /etc/nginx/snippets/*axium*; true'")

        # Remove certbot certs for axium.
        run(ssh, "bash -lc 'rm -rf /etc/letsencrypt/live/axium* /etc/letsencrypt/archive/axium* /etc/letsencrypt/renewal/axium*.conf; true'")

        # Stop/disable services that expose 8787 if they look axium-related.
        run(ssh, "bash -lc 'for s in $(systemctl list-unit-files --type=service --no-legend | awk \"{print $1}\" | awk \"/axium|8787/\"); do systemctl disable --now \"$s\"; echo disabled:$s; done; true'")

        # Kill any remaining listener on 8787.
        run(
            ssh,
            "bash -lc 'if command -v fuser >/dev/null 2>&1; then fuser -k 8787/tcp || true; else "
            "PIDS=$(ss -ltnp \"( sport = :8787 )\" 2>/dev/null | tr \",\" \"\\n\" | awk \"/pid=/{sub(/pid=/,\\\"\\\",$1); print $1}\" | awk \"/^[0-9]+$/\" | sort -u); "
            "if [ -n \"$PIDS\" ]; then ps -fp $PIDS || true; kill -9 $PIDS; echo killed:$PIDS; else echo no_pids; fi; fi'",
        )

        # Validate nginx config and reload safely.
        run(ssh, "nginx -t")
        run(ssh, "systemctl reload nginx")

        # Final verification outputs required by user.
        run(ssh, "ls -l /etc/nginx/sites-enabled")
        run(ssh, "ss -ltnp '( sport = :8787 )' || true")
        run(ssh, "bash -lc \"ls -ld /etc/letsencrypt/live/axium* /etc/letsencrypt/archive/axium* /etc/letsencrypt/renewal/axium*.conf 2>/dev/null || echo axium_cert_not_found\"")
        run(ssh, "curl -k -L -s -o /dev/null -w 'https_root=%{http_code}\\n' -H 'Host: localhost' https://localhost/")
        run(ssh, "curl -k -L -s -o /dev/null -w 'https_events=%{http_code}\\n' -H 'Host: localhost' https://localhost/api/events.php")
        run(ssh, "curl -k -L -s -o /dev/null -w 'zinesh_root=%{http_code}\\n' -H 'Host: zinesh.com' https://localhost/")
        run(ssh, "curl -k -L -s -o /dev/null -w 'zinesh_events=%{http_code}\\n' -H 'Host: zinesh.com' https://localhost/api/events.php")
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
