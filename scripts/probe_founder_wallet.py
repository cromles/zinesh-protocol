#!/usr/bin/env python3
import os
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);
require_once $api . '/wallet_lib.php';
require_once $api . '/campaign_lib.php';
require_once $api . '/founder_lib.php';
require_once $api . '/founder_profile_lib.php';
require_once $api . '/founder_platform_lib.php';

$users = zinesh_json_read('users.json');
$u = null;
foreach ($users as $row) {
    if (!empty($row['isFounder']) || !empty($row['founder'])) { $u = $row; break; }
}
if (!$u) { echo "no founder\n"; exit(1); }

echo 'is_founder=' . (zinesh_is_founder($u) ? '1' : '0') . PHP_EOL;
try {
    $bundle = zinesh_founder_wallet_bundle($u, 50);
    $w = $bundle['wallet'];
    echo 'bundle_tlHavale=' . (isset($w['tlHavale']) ? '1' : '0') . PHP_EOL;
    if (isset($w['tlHavale'])) {
        echo json_encode($w['tlHavale'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    $stats = zinesh_founder_platform_stats();
    echo 'platform_stats_ok=1 keys=' . count($stats) . PHP_EOL;
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    exit(2);
}

// Simulate wallet.php founder JSON payload keys
$payload = [
    'ok' => true,
    'wallet' => $bundle['wallet'],
    'isFounder' => true,
];
$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
echo 'json_len=' . strlen($json) . PHP_EOL;
echo 'json_has_tlHavale=' . (strpos($json, 'tlHavale') !== false ? '1' : '0') . PHP_EOL;
"""

def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_founder_wallet.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command("php /tmp/z_founder_wallet.php; rm -f /tmp/z_founder_wallet.php")
    print(stdout.read().decode())
    err = stderr.read().decode().strip()
    if err:
        print("ERR:", err)
    cmds = [
        "test -f /www/wwwroot/zinesh.com/api/founder_platform_lib.php && echo founder_platform_lib=ok || echo founder_platform_lib=MISSING",
        "test -f /www/wwwroot/zinesh.com/api/founder_profile_lib.php && echo founder_profile_lib=ok || echo founder_profile_lib=MISSING",
    ]
    for cmd in cmds:
        _, stdout, _ = ssh.exec_command(cmd)
        print(stdout.read().decode().strip())
    ssh.close()

if __name__ == "__main__":
    main()
