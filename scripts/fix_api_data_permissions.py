"""Fix api/data file permissions for PHP-FPM (www-data)."""
from __future__ import annotations

import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass

REMOTE_API_DATA = "/www/wwwroot/zinesh.com/api/data"


def run(ssh: paramiko.SSHClient, cmd: str) -> str:
    _, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return (out or err).strip()


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{require_deploy_host()} ...")
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    try:
        cmds = [
            f"ls -la {REMOTE_API_DATA} | head -25",
            # PHP-FPM (www-data) must read/write JSON data files
            f"chown -R www-data:www-data {REMOTE_API_DATA}",
            f"chmod 775 {REMOTE_API_DATA}",
            f"chmod 664 {REMOTE_API_DATA}/*.json",
            f"ls -la {REMOTE_API_DATA} | head -25",
            # smoke: write as www-data
            f"sudo -u www-data php -r \"require '{REMOTE_API_DATA}/../wallet_lib.php'; "
            "zinesh_json_atomic('escrow_rooms.json', static function(array &\\$r): bool { return false; });"
            "echo 'www-data_write_ok';\"",
            "systemctl reload php8.3-fpm 2>/dev/null || systemctl reload php*-fpm 2>/dev/null || true",
        ]
        for cmd in cmds:
            print(f"$ {cmd}")
            print(run(ssh, cmd))
            print()
        print("api/data permissions fixed")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
