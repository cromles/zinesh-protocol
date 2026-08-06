"""Diagnose login for a specific account without printing secrets/password."""
from __future__ import annotations

import json
import os
import urllib.request

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = require_deploy_pass()
EMAIL = "hakikatinaslani@gmail.com"

PHP = r"""<?php
$email = strtolower(trim(getenv('DIAG_EMAIL') ?: ''));
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/campaign_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/security_lib.php';
require_once '/www/wwwroot/zinesh.com/api/totp_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';
require_once '/www/wwwroot/zinesh.com/api/founder_profile_lib.php';

$u = zinesh_find_user_by_email($email);
echo "found=" . ($u ? '1' : '0') . PHP_EOL;
if (!$u) {
  // fuzzy search by prefix
  $n=0;
  foreach (zinesh_load_users() as $row) {
    $e = strtolower((string)($row['email'] ?? ''));
    if (str_contains($e, 'hakikat') || str_contains($e, 'aslani')) {
      echo "similar=" . substr($e,0,4) . '***' . substr($e,-10) . " uid=" . ($row['uid']??'') . PHP_EOL;
      $n++;
    }
  }
  echo "similar_count=$n\n";
  exit;
}
echo "uid=" . ($u['uid'] ?? '') . PHP_EOL;
echo "providers=" . json_encode($u['authProviders'] ?? []) . PHP_EOL;
echo "hasPass=" . (!empty($u['passwordHash']) ? '1' : '0') . PHP_EOL;
echo "emailVerified=" . (!empty($u['emailVerified']) ? '1' : '0') . PHP_EOL;
echo "googleSub=" . (!empty($u['googleSub']) ? 'set' : 'missing') . PHP_EOL;
echo "founder=" . (function_exists('zinesh_is_founder') && zinesh_is_founder($u) ? '1' : '0') . PHP_EOL;
echo "disabled=" . (!empty($u['disabled']) || !empty($u['banned']) ? '1' : '0') . PHP_EOL;
$attempts = zinesh_login_attempts_get($email);
echo "attempts=" . json_encode([
  'failCount' => count($attempts['failTimes'] ?? []),
  'verifyRequired' => !empty($attempts['login_verification_required']),
  'codeExpires' => (int)($attempts['codeExpires'] ?? 0),
  'now' => time(),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
echo "requires_verification=" . (zinesh_login_requires_verification($email) ? '1' : '0') . PHP_EOL;
$lockKey = zinesh_login_lock_key($email);
$locks = zinesh_json_read('login_locks.json');
echo "lock_expires=" . (int)($locks[$lockKey] ?? 0) . " now=" . time() . PHP_EOL;

// Simulate login response packaging (no password verify)
try {
  zinesh_ensure_wallet_fields($u);
  zinesh_campaign_ensure_user_fields($u);
  $uid = (string)$u['uid'];
  $u2 = zinesh_campaign_persist_user_fields($uid) ?? zinesh_find_user_by_uid($uid) ?? $u;
  $progress = zinesh_campaign_user_progress($u2);
  $pub = zinesh_email_sanitize_public_user(array_merge($u2, [
    'sessionToken' => 'probe',
    'campaign' => $progress,
    'isFounder' => function_exists('zinesh_is_founder') ? zinesh_is_founder($u2) : false,
  ]));
  $payload = ['ok' => true, 'user' => $pub];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  echo "payload_ok=" . ($json !== false ? '1' : '0') . " len=" . strlen((string)$json) . " err=" . json_last_error_msg() . PHP_EOL;
} catch (Throwable $e) {
  echo "payload_fail=" . $e->getMessage() . " @" . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
}

// What does auth.php return for wrong vs missing password field?
"""
from deploy_common import USER, require_deploy_host, require_deploy_pass


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    remote = "/tmp/zinesh_user_diag.php"
    sftp = ssh.open_sftp()
    with sftp.file(remote, "w") as f:
        f.write(PHP)
    sftp.close()

    def run(cmd: str) -> str:
        _, stdout, stderr = ssh.exec_command(cmd)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        return out + (("\nERR:\n" + err) if err.strip() else "")

    print("=== user account probe ===")
    print(run(f"DIAG_EMAIL={EMAIL} php {remote}; rm -f {remote}"))

    # Hit auth login via localhost with dummy wrong password to see message shape,
    # and also empty password / malformed.
    print("=== auth response shapes ===")
    for body in [
        {"action": "login", "email": EMAIL, "password": "definitely-wrong-password-xyz"},
        {"action": "login", "email": EMAIL, "password": ""},
        {"action": "login", "email": EMAIL},
    ]:
        payload = json.dumps(body).replace("'", "'\\''")
        cmd = (
            "curl -sk -X POST 'https://127.0.0.1/api/auth.php' "
            "-H 'Host: www.zinesh.com' -H 'Content-Type: application/json' "
            "-H 'Origin: https://www.zinesh.com' "
            "--resolve www.zinesh.com:443:127.0.0.1 "
            f"-d '{payload}' -w '\\nHTTP=%{{http_code}}\\n'"
        )
        print(body, "=>")
        print(run(cmd))

    # Recent audit for this email prefix
    print("=== recent audit for this email ===")
    audit_php = r"""<?php
$email='hakikatinaslani@gmail.com';
$audit=json_decode((string)file_get_contents('/www/server/zinesh-data/audit_log.json'), true);
$n=0;
foreach ((is_array($audit)?$audit:[]) as $r) {
  if (!is_array($r)) continue;
  $meta=$r['meta']??[];
  $e=(string)($meta['email']??'');
  $uid=(string)($meta['uid']??'');
  $action=(string)($r['action']??'');
  if ($e === $email || str_starts_with($e,'hak') || $uid==='') {
    if ($e === $email || (isset($meta['email']) && str_contains(strtolower($e),'hakikat'))) {
      echo ($r['at']??'').' '.$action.' '.substr(json_encode($r, JSON_UNESCAPED_UNICODE),0,220)."\n";
      if (++$n>=20) break;
    }
  }
}
if ($n===0) echo "no_audit_for_email\n";
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_audit_email.php", "w") as f:
        f.write(audit_php)
    sftp.close()
    print(run("php /tmp/zinesh_audit_email.php; rm -f /tmp/zinesh_audit_email.php"))

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
