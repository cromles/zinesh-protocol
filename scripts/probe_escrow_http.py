from deploy_common import USER, require_deploy_host, require_deploy_pass
#!/usr/bin/env python3
"""HTTP-level escrow_create probe with real session."""
from __future__ import annotations

import json
import os
import sys
import urllib.request

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = require_deploy_pass()
UID = "1b9b932370787e42f66cddc693a1c14a"

PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';

$uid = '1b9b932370787e42f66cddc693a1c14a';
$sessions = zinesh_json_read('sessions.json');
$token = '';
$email = '';
$now = time();
foreach ($sessions as $s) {
    if (!is_array($s)) continue;
    if ((string)($s['uid'] ?? '') !== $uid) continue;
    $exp = (int)($s['expiresAt'] ?? 0);
    if ($exp > 0 && $exp < $now) continue;
    $token = (string)($s['token'] ?? $s['sessionToken'] ?? '');
    $email = (string)($s['email'] ?? '');
    if ($token !== '') break;
}
if ($token === '') {
    // mint temporary session for probe then delete after
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) { echo json_encode(['error'=>'no_user']); exit(1); }
    $token = bin2hex(random_bytes(24));
    $email = (string)($user['email'] ?? '');
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
    echo json_encode(['minted'=>true,'token'=>$token,'email'=>$email,'uid'=>$uid], JSON_UNESCAPED_UNICODE);
    exit(0);
}
$user = zinesh_find_user_by_uid($uid);
echo json_encode([
    'minted' => false,
    'token' => $token,
    'email' => $email !== '' ? $email : (string)($user['email'] ?? ''),
    'uid' => $uid,
], JSON_UNESCAPED_UNICODE);
"""


def ssh_php(php: str) -> str:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_sess_probe.php"
        with sftp.file(remote, "w") as f:
            f.write(php)
        sftp.close()
        _, stdout, stderr = ssh.exec_command(f"php {remote}; rm -f {remote}")
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("ERR:", err, file=sys.stderr)
        return out.strip()
    finally:
        ssh.close()


def main() -> int:
    meta_raw = ssh_php(PHP)
    print("SESSION_META:", meta_raw)
    meta = json.loads(meta_raw)
    token = meta["token"]
    email = meta.get("email", "")
    uid = meta["uid"]

    payload = {
        "action": "escrow_create",
        "sessionToken": token,
        "uid": uid,
        "email": email,
        "amount": 1,
        "title": "HTTP probe emanet",
        "description": "debug",
        "supplierEmail": "",
        "supplierName": "",
        "deliveryDate": "",
        "category": "Emanet",
        "matchOnly": True,
    }
    req = urllib.request.Request(
        "https://www.zinesh.com/api/wallet.php",
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as res:
            body = res.read().decode("utf-8", errors="replace")
            print("HTTP", res.status)
            print(body[:2000])
            data = json.loads(body)
            # cleanup created job if any
            job_id = (data.get("job") or {}).get("id")
            if job_id:
                cleanup = f"""<?php
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
zinesh_json_atomic('escrow_jobs.json', function (array &$jobs) {{
    $jobs = array_values(array_filter($jobs, static fn($j) => ($j['id'] ?? '') !== '{job_id}'));
    return true;
}});
echo 'cleaned';
"""
                print("CLEAN:", ssh_php(cleanup))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        print("HTTP", e.code)
        print(body[:2000])
    except Exception as e:
        print("REQ_FAIL:", e)
        return 1

    # also check recent php / nginx error tails
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        for cmd in [
            "tail -n 40 /www/wwwlogs/zinesh.com.error.log 2>/dev/null || true",
            "tail -n 40 /www/server/php/82/var/log/php-fpm.log 2>/dev/null || true",
            "ls -1 /www/wwwlogs 2>/dev/null | head",
        ]:
            _, stdout, _ = ssh.exec_command(cmd)
            out = stdout.read().decode("utf-8", errors="replace").strip()
            if out:
                print(f"--- {cmd} ---")
                print(out)
    finally:
        ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
