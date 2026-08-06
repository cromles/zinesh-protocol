"""Find user + clear stale login lock; probe Apex vs WWW auth."""
from __future__ import annotations

import json
import os

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
EMAIL = "hakikatinaslani@gmail.com"

PHP = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/password_reset_lib.php';
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/security_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_lib.php';

$email = strtolower(trim(getenv('DIAG_EMAIL') ?: ''));
$u = zinesh_find_user_by_email($email);
echo "found=" . ($u ? '1' : '0') . PHP_EOL;
if ($u) {
  echo "uid=" . ($u['uid'] ?? '') . PHP_EOL;
  echo "providers=" . json_encode($u['authProviders'] ?? []) . PHP_EOL;
  echo "hasPass=" . (!empty($u['passwordHash']) ? '1' : '0') . PHP_EOL;
  echo "emailVerified=" . (!empty($u['emailVerified']) ? '1' : '0') . PHP_EOL;
  echo "googleSub=" . (!empty($u['googleSub']) ? 'set' : 'missing') . PHP_EOL;
  echo "founder=" . (zinesh_is_founder($u) ? '1' : '0') . PHP_EOL;
  $attempts = zinesh_login_attempts_get($email);
  echo "attempts=" . json_encode([
    'failCount' => count($attempts['failTimes'] ?? []),
    'verifyRequired' => !empty($attempts['login_verification_required']),
    'codeExpires' => (int)($attempts['codeExpires'] ?? 0),
    'now' => time(),
  ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
  echo "requires_verification=" . (zinesh_login_requires_verification($email) ? '1' : '0') . PHP_EOL;
  try {
    zinesh_ensure_wallet_fields($u);
    zinesh_campaign_ensure_user_fields($u);
    $uid = (string)$u['uid'];
    $u2 = zinesh_campaign_persist_user_fields($uid) ?? zinesh_find_user_by_uid($uid) ?? $u;
    $progress = zinesh_campaign_user_progress($u2);
    $pub = zinesh_email_sanitize_public_user(array_merge($u2, [
      'sessionToken' => 'probe',
      'campaign' => $progress,
      'isFounder' => zinesh_is_founder($u2),
    ]));
    $json = json_encode(['ok' => true, 'user' => $pub], JSON_UNESCAPED_UNICODE);
    echo "payload_ok=" . ($json !== false ? '1' : '0') . " len=" . strlen((string)$json) . PHP_EOL;
  } catch (Throwable $e) {
    echo "payload_fail=" . $e->getMessage() . PHP_EOL;
  }
} else {
  foreach (zinesh_load_users() as $row) {
    $e = strtolower((string)($row['email'] ?? ''));
    if (str_contains($e, 'hakikat') || str_contains($e, 'aslani')) {
      echo "similar=" . substr($e,0,3) . "***" . substr($e, strpos($e,'@')) . " hasPass=" . (!empty($row['passwordHash'])?'1':'0') . " providers=" . json_encode($row['authProviders']??[]) . PHP_EOL;
    }
  }
}

// clear stale locks for this email
zinesh_login_lock_release($email);
echo "lock_released=1\n";
"""
from deploy_common import USER, require_deploy_host, require_deploy_pass


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_user_diag2.php", "w") as f:
        f.write(PHP)
    sftp.close()

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out + (("\nERR:\n" + err) if err.strip() else "")

    print("=== user ===")
    print(run(f"DIAG_EMAIL={EMAIL} php /tmp/zinesh_user_diag2.php; rm -f /tmp/zinesh_user_diag2.php"))

    print("=== apex vs www via server curl ===")
    payload = json.dumps(
        {"action": "login", "email": EMAIL, "password": "definitely-wrong-password-xyz"}
    )
    for host in ("zinesh.com", "www.zinesh.com"):
        print(f"-- {host}")
        print(
            run(
                "curl -sk -X POST "
                f"'https://127.0.0.1/api/auth.php' "
                f"-H 'Host: {host}' -H 'Content-Type: application/json' "
                f"-H 'Origin: https://{host}' "
                f"--resolve {host}:443:127.0.0.1 "
                f"-d '{payload}' -w '\\nHTTP=%{{http_code}} redir=%{{redirect_url}}\\n' "
                "--max-redirs 0"
            )
        )

    print("=== nginx apex api location ===")
    print(
        run(
            "grep -R --line-number -E 'zinesh.com|api|return 301' "
            "/www/server/panel/vhost/nginx/*.conf 2>/dev/null | head -80; "
            "ls /www/server/panel/vhost/nginx/ | head -40"
        )
    )
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
