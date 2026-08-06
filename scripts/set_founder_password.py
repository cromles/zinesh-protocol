"""Set founder account password on production (CLI only)."""
from __future__ import annotations

import secrets
import string
import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass, require_founder_email

FOUNDER_EMAIL = require_founder_email()


def make_temp_password(length: int = 14) -> str:
    alphabet = string.ascii_letters + string.digits + "!@#"
    while True:
        pwd = "".join(secrets.choice(alphabet) for _ in range(length))
        if (
            any(c.islower() for c in pwd)
            and any(c.isupper() for c in pwd)
            and any(c.isdigit() for c in pwd)
        ):
            return pwd


def build_php(email: str, password: str) -> str:
    email_json = json_escape(email)
    pass_json = json_escape(password)
    return f"""<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/password_reset_lib.php';
require '/www/wwwroot/zinesh.com/api/founder_lib.php';
$email = {email_json};
$password = {pass_json};
$user = zinesh_find_user_by_email($email);
if (!$user) {{
    fwrite(STDERR, "founder_missing\\n");
    exit(1);
}}
$uid = (string)($user['uid'] ?? '');
$hash = password_hash($password, PASSWORD_DEFAULT);
zinesh_update_user($uid, static function (array &$u) use ($hash) {{
    $u['passwordHash'] = $hash;
    unset($u['totpSecret'], $u['totpPendingSecret'], $u['totpEnabled'], $u['totpEnabledAt']);
    $u['emailVerified'] = true;
}});
$check = zinesh_find_user_by_uid($uid) ?? [];
$ok = password_verify($password, (string)($check['passwordHash'] ?? ''));
echo $ok ? "OK\\n" : "VERIFY_FAIL\\n";
"""


def json_escape(value: str) -> str:
    import json

    return json.dumps(value, ensure_ascii=False)


def main() -> int:
    new_password = sys.argv[1] if len(sys.argv) > 1 else make_temp_password()
    host = require_deploy_host()
    ssh_pass = require_deploy_pass()

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{host} ...")
    ssh.connect(host, username=USER, password=ssh_pass, timeout=25)
    try:
        php = build_php(FOUNDER_EMAIL, new_password)
        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_set_founder_pass.php"
        with sftp.file(remote, "w") as f:
            f.write(php)
        sftp.close()

        _, out, err = ssh.exec_command(f"php {remote} && rm -f {remote}")
        result = out.read().decode().strip() or err.read().decode().strip()
        if "OK" not in result:
            print(result)
            return 1

        probe = (
            "curl -s -o /tmp/login_ok.json -w 'http=%{http_code}' "
            "-X POST http://127.0.0.1/api/auth.php "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
            f"-d '{{\"action\":\"login\",\"email\":\"{FOUNDER_EMAIL}\",\"password\":\"{new_password}\"}}'; "
            "echo; head -c 300 /tmp/login_ok.json; echo"
        )
        _, out, _ = ssh.exec_command(probe)
        probe_result = out.read().decode().strip()
        print(probe_result)
        if "needsTotpSetup" in probe_result or "needsTotp" in probe_result:
            print("LOGIN_STEP=totp_setup_expected")
        elif '"ok":true' in probe_result or '"sessionToken"' in probe_result:
            print("LOGIN_STEP=password_ok")

        print(f"\nFOUNDER_EMAIL={FOUNDER_EMAIL}")
        print(f"TEMP_PASSWORD={new_password}")
        print("TOTP was cleared — after login you will set Google Authenticator again.")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
