"""Find auth.php HTTP 500 cause on production."""
from __future__ import annotations

import json
import os

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")
EMAIL = "hakikatinaslani@gmail.com"


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out if out.strip() else err

    print("=== recent php/nginx errors ===")
    print(
        run(
            "for f in /var/log/nginx/zinesh.com.error.log /www/wwwlogs/zinesh.com.error.log "
            "/var/log/php8.1-fpm.log /run/php/php8.1-fpm.log "
            "/www/server/php/v82/var/log/php-fpm.log; do "
            "[ -f \"$f\" ] && echo \"---- $f\" && tail -n 40 \"$f\"; done; "
            "ls -lt /var/log/nginx /www/wwwlogs 2>/dev/null | head -30"
        )
    )

    print("=== reproduce login 500 via www ===")
    payload = json.dumps(
        {"action": "login", "email": EMAIL, "password": "wrong-but-triggers-path"}
    )
    print(
        run(
            "curl -sk -D - -o /tmp/login500.out -X POST 'https://127.0.0.1/api/auth.php' "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
            "-H 'Origin: https://www.zinesh.com' "
            "--resolve www.zinesh.com:443:127.0.0.1 "
            f"-d '{payload}'; echo BODY:; cat /tmp/login500.out; echo; "
            "tail -n 20 /var/log/nginx/zinesh.com.error.log 2>/dev/null"
        )
    )

    print("=== php -l auth chain ===")
    print(
        run(
            "for f in auth.php wallet_lib.php campaign_lib.php email_lib.php security_lib.php "
            "totp_lib.php founder_lib.php founder_profile_lib.php oauth_lib.php "
            "password_reset_lib.php kyc_lib.php early_access_lib.php notifications_lib.php "
            "_bootstrap.php; do "
            "php -l /www/wwwroot/zinesh.com/api/$f 2>&1 | tail -1; done"
        )
    )

    print("=== simulate login success path (no password) catching fatals ===")
    php = r"""<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/security_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_profile_lib.php';
require_once '/www/wwwroot/zinesh.com/api/kyc_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';

$email = 'hakikatinaslani@gmail.com';
$users = zinesh_load_users();
$u = null;
foreach ($users as $row) {
  if (strtolower((string)($row['email'] ?? '')) === $email) { $u = $row; break; }
}
echo "found=" . ($u ? '1' : '0') . PHP_EOL;
if (!$u) exit;
$uid = (string)$u['uid'];
try {
  zinesh_login_clear_failures($email);
  zinesh_ensure_wallet_fields($u);
  zinesh_campaign_ensure_user_fields($u);
  zinesh_email_sync_legacy_verified($uid);
  $u = zinesh_campaign_persist_user_fields($uid) ?? zinesh_find_user_by_uid($uid) ?? $u;
  if (empty($u['campaignsClaimed']['founding_signup']) && !empty($u['emailVerified'])) {
    zinesh_campaign_try_grant_signup_reward($uid);
    $u = zinesh_find_user_by_uid($uid) ?? $u;
  }
  $totpGate = zinesh_founder_login_totp_gate($uid, $u, '');
  echo 'totp=' . json_encode($totpGate) . PHP_EOL;
  $token = zinesh_create_session($uid);
  if (zinesh_is_founder($u)) {
    $u = zinesh_sync_founder_treasury_wallets($uid);
  }
  $payload = [
    'ok' => true,
    'user' => zinesh_email_sanitize_public_user(array_merge($u, [
      'sessionToken' => $token,
      'campaign' => zinesh_campaign_user_progress($u),
      'isFounder' => zinesh_is_founder($u),
    ])),
  ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  echo "ok len=" . strlen((string)$json) . PHP_EOL;
  zinesh_revoke_session($token);
} catch (Throwable $e) {
  echo "FAIL " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
}
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_login_path.php", "w") as f:
        f.write(php)
    sftp.close()
    print(run("php /tmp/zinesh_login_path.php; rm -f /tmp/zinesh_login_path.php"))

    # Check if auth.php require_once missing files after migrate
    print("=== auth.php requires exist? ===")
    print(
        run(
            "php -r "
            "'$c=file_get_contents(\"/www/wwwroot/zinesh.com/api/auth.php\"); "
            "preg_match_all(\"/require_once __DIR__ \\. \\'(\\/[^\\']+)\\'/\",$c,$m); "
            "foreach($m[1] as $f){ $p=\"/www/wwwroot/zinesh.com/api$f\"; "
            "echo (file_exists($p)?\"OK\":\"MISSING\").\" $p\\n\"; }'"
        )
    )

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
