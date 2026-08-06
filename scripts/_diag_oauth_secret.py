"""Diagnose Google OAuth client_secret without printing the secret."""
from __future__ import annotations

import hashlib
import json
import os
import urllib.parse
import urllib.request

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';
$o = zinesh_google_oauth_config();
$id = (string)$o['client_id'];
$secret = (string)$o['client_secret'];
echo "enabled=" . (!empty($o['enabled']) ? '1' : '0') . PHP_EOL;
echo "redirect_enabled=" . (!empty($o['redirect_enabled']) ? '1' : '0') . PHP_EOL;
echo "client_id=" . $id . PHP_EOL;
echo "client_id_prefix=" . substr($id, 0, 20) . PHP_EOL;
echo "secret_len=" . strlen($secret) . PHP_EOL;
echo "secret_prefix=" . substr($secret, 0, 6) . PHP_EOL;
echo "secret_suffix=" . substr($secret, -4) . PHP_EOL;
echo "secret_sha8=" . substr(hash('sha256', $secret), 0, 8) . PHP_EOL;
echo "redirect_uri=" . zinesh_google_oauth_redirect_uri() . PHP_EOL;

// Inspect local config shapes (no secret dump)
$local = '/www/wwwroot/zinesh.com/api/config.local.php';
if (is_readable($local)) {
  $cfg = require $local;
  $g = $cfg['google_oauth'] ?? [];
  $s = (string)($g['client_secret'] ?? '');
  echo "local_client_id_set=" . (!empty($g['client_id']) ? '1' : '0') . PHP_EOL;
  echo "local_secret_len=" . strlen($s) . PHP_EOL;
  echo "local_secret_prefix=" . substr($s, 0, 6) . PHP_EOL;
  echo "local_secret_has_space=" . (preg_match('/\s/', $s) ? '1' : '0') . PHP_EOL;
  echo "local_secret_has_quotes=" . (preg_match('/["\']/', $s) ? '1' : '0') . PHP_EOL;
}

// Probe token endpoint with intentionally bad code to see if secret is accepted
$post = http_build_query([
  'code' => 'invalid-probe-code',
  'client_id' => $id,
  'client_secret' => $secret,
  'redirect_uri' => zinesh_google_oauth_redirect_uri(),
  'grant_type' => 'authorization_code',
]);
$ctx = stream_context_create([
  'http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
    'content' => $post,
    'timeout' => 15,
    'ignore_errors' => true,
  ],
]);
$raw = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
echo "token_probe_body=" . substr((string)$raw, 0, 300) . PHP_EOL;
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_oauth_secret_diag.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        "php /tmp/zinesh_oauth_secret_diag.php; rm -f /tmp/zinesh_oauth_secret_diag.php"
    )
    print(stdout.read().decode("utf-8", errors="replace"))
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("ERR", err)

    # Recent google oauth audit
    _, stdout2, _ = ssh.exec_command(
        r"""php -r '
$a=json_decode(file_get_contents("/www/server/zinesh-data/audit_log.json"), true);
$n=0;
foreach ((is_array($a)?$a:[]) as $r) {
  $act=$r["action"]??"";
  if (!preg_match("/oauth|google/i", $act)) continue;
  echo ($r["at"]??"")." ".$act." ".substr(json_encode($r, JSON_UNESCAPED_UNICODE),0,260)."\n";
  if (++$n>=15) break;
}
'"""
    )
    print("=== oauth audit ===")
    print(stdout2.read().decode("utf-8", errors="replace"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
