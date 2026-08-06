"""Complete Google OAuth secret token probe (no secret printed)."""
from __future__ import annotations

import os

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require_once '/www/wwwroot/zinesh.com/api/email_lib.php';
require_once '/www/wwwroot/zinesh.com/api/oauth_lib.php';
$o = zinesh_google_oauth_config();
$id = (string)$o['client_id'];
$secret = (string)$o['client_secret'];
$redir = zinesh_google_oauth_redirect_uri();
echo "redirect_uri=$redir\n";
echo "secret_len=" . strlen($secret) . " prefix=" . substr($secret,0,7) . " suffix=" . substr($secret,-4) . "\n";

$post = http_build_query([
  'code' => 'invalid-probe-code',
  'client_id' => $id,
  'client_secret' => $secret,
  'redirect_uri' => $redir,
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
$raw = (string)@file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
echo "token_probe=" . substr($raw, 0, 400) . "\n";

// Also check whether redirect URI alternate is used in Google console historically
$alts = [
  'https://www.zinesh.com/api/google_auth.php?action=callback',
  'https://www.zinesh.com/api/auth.php?action=google_callback',
  'https://www.zinesh.com/api/auth.php?action=oauth_callback',
];
foreach ($alts as $a) echo "alt=$a\n";
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/zinesh_oauth_probe2.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command(
        "php /tmp/zinesh_oauth_probe2.php; rm -f /tmp/zinesh_oauth_probe2.php"
    )
    print(stdout.read().decode("utf-8", errors="replace"))
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("ERR", err)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
