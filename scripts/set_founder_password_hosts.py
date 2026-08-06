"""Set founder password on a specific deploy host."""
from __future__ import annotations

import json
import secrets
import string
import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass, require_founder_email

FOUNDER_EMAIL = require_founder_email()


def make_password() -> str:
    # Avoid ambiguous chars: 0/O, 1/l/I
    alphabet = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789"
    return "".join(secrets.choice(alphabet) for _ in range(12))


def build_php(email: str, password: str) -> str:
    return f"""<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/password_reset_lib.php';
$email = {json.dumps(email)};
$password = {json.dumps(password)};
$user = zinesh_find_user_by_email($email);
if (!$user) {{ fwrite(STDERR, "founder_missing\\n"); exit(1); }}
$uid = (string)($user['uid'] ?? '');
$hash = password_hash($password, PASSWORD_DEFAULT);
zinesh_update_user($uid, static function (array &$u) use ($hash) {{
    $u['passwordHash'] = $hash;
    unset($u['totpSecret'], $u['totpPendingSecret'], $u['totpEnabled'], $u['totpEnabledAt']);
    $u['emailVerified'] = true;
}});
$check = zinesh_find_user_by_uid($uid) ?? [];
echo password_verify($password, (string)($check['passwordHash'] ?? '')) ? "OK\\n" : "FAIL\\n";
"""


def reset_on_host(host: str, ssh_pass: str, new_password: str) -> bool:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    try:
        print(f"Connecting {USER}@{host} ...")
        ssh.connect(host, username=USER, password=ssh_pass, timeout=20)
        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_set_pass.php"
        with sftp.file(remote, "w") as f:
            f.write(build_php(FOUNDER_EMAIL, new_password))
        sftp.close()
        _, out, err = ssh.exec_command(f"php {remote} 2>&1 && rm -f {remote}")
        result = out.read().decode().strip() or err.read().decode().strip()
        print(f"  {host}: {result}")
        return "OK" in result
    except Exception as exc:
        print(f"  {host}: ERROR {exc}")
        return False
    finally:
        ssh.close()


def main() -> int:
    hosts = sys.argv[1:] or [require_deploy_host()]
    password = make_password()
    ssh_pass = require_deploy_pass()
    ok_any = False
    for host in hosts:
        if reset_on_host(host, ssh_pass, password):
            ok_any = True
    if ok_any:
        print(f"\nEMAIL={FOUNDER_EMAIL}")
        print(f"PASSWORD={password}")
    return 0 if ok_any else 1


if __name__ == "__main__":
    raise SystemExit(main())
