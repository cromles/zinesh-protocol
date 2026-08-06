from deploy_common import USER, require_deploy_host, require_deploy_pass
"""Deeper auth failure diagnosis on live VPS."""
from __future__ import annotations

import json
import os
import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out if out.strip() else err

    print("=== google_auth GET start (follow no) ===")
    print(
        run(
            "curl -s -D - -o /tmp/ga_body 'https://127.0.0.1/api/google_auth.php?action=start' "
            "-H 'Host: www.zinesh.com' --resolve www.zinesh.com:443:127.0.0.1 -k "
            "--max-redirs 0 | head -30; echo BODY:; head -c 300 /tmp/ga_body; echo"
        )
    )

    print("=== can PHP reach Google userinfo? ===")
    print(
        run(
            "php -r \"$c=stream_context_create(['http'=>['timeout'=>8,'ignore_errors'=>true]]);"
            "$r=@file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo',false,$c);"
            "echo 'reachable=' . ($r===false?'no':'yes') . ' len=' . strlen((string)$r) . PHP_EOL;"
            "echo substr((string)$r,0,120), PHP_EOL;\""
        )
    )

    print("=== oauth_google_access with bogus token (via localhost) ===")
    print(
        run(
            "curl -sk -X POST 'https://127.0.0.1/api/auth.php' -H 'Host: www.zinesh.com' "
            "-H 'Content-Type: application/json' "
            "--resolve www.zinesh.com:443:127.0.0.1 "
            "-d '{\"action\":\"oauth_google_access\",\"accessToken\":\"ya29.invalid\"}'"
        )
    )

    print("=== recent auth.php access (last 40) ===")
    print(
        run(
            "for f in /www/wwwlogs/zinesh.com.log /www/wwwlogs/access.log "
            "/www/wwwroot/zinesh.com/logs/*.log; do "
            "[ -f \"$f\" ] && echo FILE:$f && grep -a 'auth.php' \"$f\" | tail -n 30 && echo; "
            "done; "
            "find /www/wwwlogs -type f -name '*zinesh*' 2>/dev/null | head -20"
        )
    )

    print("=== audit log tail ===")
    print(
        run(
            "tail -n 40 /www/server/zinesh-data/audit.log 2>/dev/null; "
            "ls -lt /www/server/zinesh-data | head -25"
        )
    )

    print("=== bootstrap require in wallet_lib vs auth ===")
    print(
        run(
            "head -40 /www/wwwroot/zinesh.com/api/auth.php; echo '---'; "
            "grep -n \"require\|__bootstrap\\|zinesh_config\\|wallet_lib\" "
            "/www/wwwroot/zinesh.com/api/auth.php /www/wwwroot/zinesh.com/api/wallet_lib.php | head -40"
        )
    )

    print("=== CORS preflight from app origin ===")
    print(
        run(
            "curl -sk -D - -o /tmp/cors -X OPTIONS 'https://127.0.0.1/api/auth.php' "
            "-H 'Host: www.zinesh.com' --resolve www.zinesh.com:443:127.0.0.1 "
            "-H 'Origin: https://app.zinesh.com' "
            "-H 'Access-Control-Request-Method: POST' "
            "-H 'Access-Control-Request-Headers: content-type' | head -25; "
            "echo BODY:; cat /tmp/cors"
        )
    )

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
