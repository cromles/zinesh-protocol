#!/usr/bin/env python3
"""Call wallet.php escrow_create from the VPS itself (bypasses Cloudflare)."""
from __future__ import annotations

import json
import os
import sys

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")
UID = "1b9b932370787e42f66cddc693a1c14a"

SETUP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
$uid = '1b9b932370787e42f66cddc693a1c14a';
$user = zinesh_find_user_by_uid($uid);
$token = bin2hex(random_bytes(24));
$email = (string)($user['email'] ?? '');
$now = time();
zinesh_json_atomic('sessions.json', function (array &$rows) use ($token, $uid, $email, $now) {
    $rows[] = [
        'token' => $token,
        'uid' => $uid,
        'email' => $email,
        'createdAt' => $now,
        'expiresAt' => $now + 600,
        'probe' => true,
    ];
    return true;
});
echo json_encode(['token'=>$token,'email'=>$email,'uid'=>$uid], JSON_UNESCAPED_UNICODE);
"""

# Find how session auth validates tokens
def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_setup.php", "w") as f:
            f.write(SETUP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command("php /tmp/z_setup.php; rm -f /tmp/z_setup.php")
        meta = json.loads(stdout.read().decode())
        print("META", meta)

        payload = json.dumps({
            "action": "escrow_create",
            "sessionToken": meta["token"],
            "uid": meta["uid"],
            "email": meta["email"],
            "amount": 1,
            "title": "Server probe emanet",
            "description": "debug from vps",
            "supplierEmail": "",
            "matchOnly": True,
            "category": "Emanet",
        })
        # Escape for single-quoted bash -c via file
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_escrow_body.json", "w") as f:
            f.write(payload)
        sftp.close()

        cmds = [
            # localhost via php-fpm/nginx if present
            "curl -sS -m 20 -w '\\nHTTP_CODE:%{http_code}\\n' -X POST 'http://127.0.0.1/api/wallet.php' -H 'Host: www.zinesh.com' -H 'Content-Type: application/json' --data-binary @/tmp/z_escrow_body.json",
            "curl -sS -m 20 -w '\\nHTTP_CODE:%{http_code}\\n' -X POST 'https://www.zinesh.com/api/wallet.php' -H 'Content-Type: application/json' -H 'User-Agent: Mozilla/5.0' --data-binary @/tmp/z_escrow_body.json",
            # also check session key shape in sessions.json
            "php -r \"$s=json_decode(file_get_contents('/www/server/zinesh-data/sessions.json'),true); $last=end($s); echo json_encode(array_keys($last?:[]));\"",
            # grep require_auth session field names
            "rg -n \"sessionToken|function zinesh_require_auth|expiresAt|'token'\" /www/wwwroot/zinesh.com/api/session_auth_lib.php /www/wwwroot/zinesh.com/api/_bootstrap.php /www/wwwroot/zinesh.com/api/wallet_lib.php 2>/dev/null | head -60",
            "ls /www/wwwroot/zinesh.com/api/*auth* /www/wwwroot/zinesh.com/api/session* 2>/dev/null",
        ]
        for cmd in cmds:
            print("---", cmd[:90], "---")
            _, stdout, stderr = ssh.exec_command(cmd)
            out = stdout.read().decode("utf-8", errors="replace")
            err = stderr.read().decode("utf-8", errors="replace")
            print(out)
            if err.strip():
                print("ERR:", err[:500])

        # cleanup
        ssh.exec_command("rm -f /tmp/z_escrow_body.json")
        cleanup = f"""<?php
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
$token = {meta['token']!r};
zinesh_json_atomic('sessions.json', function (array &$rows) use ($token) {{
    $rows = array_values(array_filter($rows, static fn($r) => ($r['token'] ?? '') !== $token && empty($r['probe'])));
    return true;
}});
zinesh_json_atomic('escrow_jobs.json', function (array &$jobs) {{
    $jobs = array_values(array_filter($jobs, static fn($j) => ($j['title'] ?? '') !== 'Server probe emanet'));
    return true;
}});
echo 'cleaned';
"""
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_clean.php", "w") as f:
            f.write(cleanup)
        sftp.close()
        _, stdout, _ = ssh.exec_command("php /tmp/z_clean.php; rm -f /tmp/z_clean.php")
        print("CLEAN", stdout.read().decode())
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
