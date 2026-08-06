from deploy_common import USER, require_deploy_host, require_deploy_pass
#!/usr/bin/env python3
"""Diagnose why escrow_create saves but HTTP response may fail."""
from __future__ import annotations

import os
import sys

import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = require_deploy_pass()
PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);

// Check message string encoding in wallet.php
$src = file_get_contents($api . '/wallet.php');
if (preg_match("/Emanet kodu haz.{1,20}WhatsApp.{1,40}gonderin/u", $src, $m) || preg_match("/Emanet kodu haz.*/", $src, $m)) {
    $line = $m[0];
    echo 'msg_fragment=' . $line . PHP_EOL;
    echo 'msg_valid_utf8=' . (mb_check_encoding($line, 'UTF-8') ? '1' : '0') . PHP_EOL;
    echo 'msg_hex=' . bin2hex(substr($line, 0, 120)) . PHP_EOL;
}

require_once $api . '/wallet_lib.php';
require_once $api . '/campaign_lib.php';
require_once $api . '/email_lib.php';
require_once $api . '/founder_lib.php';
require_once $api . '/founder_profile_lib.php';
require_once $api . '/tl_mode_lib.php';
require_once $api . '/escrow_jobs_lib.php';
// Same requires as wallet.php top (minus withdraw for speed? include it)
require_once $api . '/withdraw_executor.php';

$uid = '1b9b932370787e42f66cddc693a1c14a';
$user = zinesh_find_user_by_uid($uid);

// Use an EXISTING pending job to test response build without creating new
$jobs = zinesh_escrow_jobs_load();
$job = null;
foreach (array_reverse($jobs) as $j) {
    if (($j['buyerUid'] ?? '') === $uid) { $job = $j; break; }
}
if (!$job) { echo "no existing job\n"; exit(1); }
echo 'using_job=' . ($job['id'] ?? '') . ' match=' . ($job['matchCode'] ?? '') . PHP_EOL;

$matchOnly = true;
$agreementTask = null;

$payload = [
    'ok' => true,
    'wallet' => zinesh_wallet_state($user),
    'user' => zinesh_public_user($user),
    'job' => zinesh_escrow_job_public_row($job, $uid),
    'matchCode' => (string)($job['matchCode'] ?? ''),
    'message' => $matchOnly
        ? 'Emanet kodu hazır. WhatsApp’tan karşı tarafa gönderin; katılınca para kilitlenir.'
        : 'Emanet oluşturuldu. Tutar kasada kilitlendi.',
    'campaignTask' => $agreementTask,
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
echo 'encode1=' . ($json !== false ? 'ok' : json_last_error_msg()) . PHP_EOL;

// Extract EXACT message bytes from wallet.php source and test encode
if (preg_match("/\? '(Emanet kodu haz[^']+)'\s*:/s", $src, $mm)) {
    $exact = $mm[1];
    // maybe curly quote broke the regex - try another
}
// Grep lines around message
$lines = explode("\n", $src);
foreach ($lines as $i => $l) {
    if (str_contains($l, 'WhatsApp') || str_contains($l, 'Emanet kodu')) {
        echo 'L' . ($i+1) . ':' . $l . PHP_EOL;
        echo 'L' . ($i+1) . '_utf8=' . (mb_check_encoding($l, 'UTF-8') ? '1' : '0') . PHP_EOL;
        $test = ['message' => trim($l)];
        $j = json_encode($test, JSON_UNESCAPED_UNICODE);
        echo 'L' . ($i+1) . '_json=' . ($j !== false ? 'ok' : json_last_error_msg()) . PHP_EOL;
    }
}

// Simulate POST through PHP-CGI or built-in by including wallet with argv? 
// Better: use curl to unix socket / local php-fpm if available.
$token = zinesh_create_session($uid);
$body = json_encode([
  'action' => 'escrow_create',
  'sessionToken' => $token,
  'amount' => 1,
  'title' => 'HTTP local encode test',
  'description' => 'd',
  'matchOnly' => true,
], JSON_UNESCAPED_UNICODE);
file_put_contents('/tmp/z_body.json', $body);

// Try several local endpoints
$cmds = [
  "curl -sS -m 15 -D - -o /tmp/z_out.txt -X POST 'https://127.0.0.1/api/wallet.php' -H 'Host: www.zinesh.com' -H 'Content-Type: application/json' -k --data-binary @/tmp/z_body.json",
  "curl -sS -m 15 -D - -o /tmp/z_out2.txt -X POST 'http://127.0.0.1:80/api/wallet.php' -H 'Host: www.zinesh.com' -H 'Content-Type: application/json' --data-binary @/tmp/z_body.json -L",
];
foreach ($cmds as $c) {
  echo "CMD $c\n";
  echo shell_exec($c . ' 2>&1') . "\n";
  echo "BODY1=" . substr((string)@file_get_contents('/tmp/z_out.txt'), 0, 500) . "\n";
  echo "BODY2=" . substr((string)@file_get_contents('/tmp/z_out2.txt'), 0, 500) . "\n";
}

echo "ERRLOG:\n";
echo shell_exec('tail -n 30 /var/log/nginx/zinesh.com.error.log 2>/dev/null');
echo shell_exec('tail -n 30 /var/log/nginx/error.log 2>/dev/null');
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_diag.php", "w") as f:
            f.write(PHP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command("php /tmp/z_diag.php; rm -f /tmp/z_diag.php /tmp/z_body.json /tmp/z_out.txt /tmp/z_out2.txt")
        print(stdout.read().decode("utf-8", errors="replace"))
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("ERR:", err[:2000])
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
