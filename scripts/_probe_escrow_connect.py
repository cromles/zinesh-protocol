"""Probe escrow_room connect on live server."""
from __future__ import annotations

import json
import sys

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass


def run(ssh, cmd: str) -> str:
    _, stdout, stderr = ssh.exec_command(cmd)
    return (stdout.read().decode("utf-8", errors="replace") or stderr.read().decode("utf-8", errors="replace")).strip()


def curl_post(ssh, body: dict, host: str = "www.zinesh.com") -> str:
    payload = json.dumps(body, ensure_ascii=False)
    payload = payload.replace("'", "'\\''")
    return run(
        ssh,
        f"curl -sk -X POST 'http://127.0.0.1/api/escrow_room.php' "
        f"-H 'Host: {host}' -H 'Content-Type: application/json' "
        f"-d '{payload}' -w '\\nHTTP=%{{http_code}}'",
    )


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    try:
        print("=== users with tickets (sample) ===")
        print(
            run(
                ssh,
                "php -r \""
                "require '/www/wwwroot/zinesh.com/api/wallet_lib.php';"
                "$u=zinesh_load_users();"
                "foreach(array_slice($u,0,8) as $x){"
                "echo ($x['email']??'?').' ticket='.($x['ticketNumber']??'').' kyc='.($x['kycStatus']??'none').PHP_EOL;"
                "}\"",
            )
        )

        print("\n=== connect without auth ===")
        print(
            curl_post(
                ssh,
                {"action": "connect", "peerTicket": "22595", "myRole": "employer"},
            )
        )

        print("\n=== list users tickets count ===")
        print(
            run(
                ssh,
                "php -r \""
                "require '/www/wwwroot/zinesh.com/api/wallet_lib.php';"
                "zinesh_backfill_missing_user_tickets();"
                "$n=0;$empty=0;foreach(zinesh_load_users() as $u){$n++;if(empty($u['ticketNumber']))$empty++;}"
                "echo 'users='.$n.' empty_tickets='.$empty.PHP_EOL;\"",
            )
        )

        print("\n=== escrow_rooms file ===")
        print(run(ssh, "ls -la /www/wwwroot/zinesh.com/api/data/escrow_rooms.json 2>&1; wc -c /www/wwwroot/zinesh.com/api/data/escrow_rooms.json 2>&1"))

        print("\n=== php lint escrow_room ===")
        print(run(ssh, "php -l /www/wwwroot/zinesh.com/api/escrow_room.php; php -l /www/wwwroot/zinesh.com/api/escrow_room_lib.php"))

        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
