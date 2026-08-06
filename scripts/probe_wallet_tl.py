from deploy_common import USER, require_deploy_host, require_deploy_pass
#!/usr/bin/env python3
"""Probe live server tl_mode + wallet tlHavale."""
import os
import paramiko

HOST = require_deploy_host()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = require_deploy_pass()
PHP = r"""<?php
require_once '/www/wwwroot/zinesh.com/api/_bootstrap.php';
require_once '/www/wwwroot/zinesh.com/api/tl_mode_lib.php';
require_once '/www/wwwroot/zinesh.com/api/tl_havale_lib.php';
require_once '/www/wwwroot/zinesh.com/api/protocol_constants.php';
$p = zinesh_protocol_constants();
echo 'tl_mode=' . (($p['tl_mode'] ?? false) ? '1' : '0') . PHP_EOL;
echo 'tl_mode_fn=' . (zinesh_tl_mode_enabled() ? '1' : '0') . PHP_EOL;
echo 'havale_enabled=' . (zinesh_havale_enabled() ? '1' : '0') . PHP_EOL;
$h = zinesh_config()['tl_havale'] ?? [];
echo 'iban_present=' . (!empty($h['iban']) ? '1' : '0') . PHP_EOL;
echo 'iban_len=' . strlen(trim((string)($h['iban'] ?? ''))) . PHP_EOL;
$users = zinesh_json_read('users.json');
$u = null;
foreach ($users as $row) {
    if (!empty($row['isFounder']) || !empty($row['founder'])) { $u = $row; break; }
}
if (!$u && count($users)) $u = reset($users);
if ($u) {
    require_once '/www/wwwroot/zinesh.com/api/wallet_lib.php';
    $st = zinesh_wallet_state($u);
    echo 'wallet_has_tlHavale=' . (isset($st['tlHavale']) ? '1' : '0') . PHP_EOL;
    if (isset($st['tlHavale'])) {
        echo 'wallet_tlHavale=' . json_encode($st['tlHavale'], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}
"""

def main():
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_probe.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command("php /tmp/z_probe.php; rm -f /tmp/z_probe.php")
    out = stdout.read().decode()
    err = stderr.read().decode()
    print(out)
    if err.strip():
        print("ERR:", err)
    ssh.close()

if __name__ == "__main__":
    main()
