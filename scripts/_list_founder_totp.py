from __future__ import annotations

import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass

PHP = r"""<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/founder_lib.php';
$cfg = zinesh_config();
echo 'founder_emails=' . json_encode($cfg['founder_emails'] ?? []) . "\n";
foreach (zinesh_load_users() as $u) {
    $e = strtolower((string)($u['email'] ?? ''));
    if ($e === '') continue;
    $totp = !empty($u['totpEnabled']) ? 'on' : (trim((string)($u['totpPendingSecret'] ?? '')) !== '' ? 'pending' : 'off');
    echo $e . ' founder=' . (zinesh_is_founder($u) ? '1' : '0') . ' totp=' . $totp . "\n";
}
"""


def main() -> None:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(require_deploy_host(), username=USER, password=require_deploy_pass(), timeout=25)
    try:
        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_list_founder.php"
        with sftp.file(remote, "w") as f:
            f.write(PHP)
        sftp.close()
        _, out, err = ssh.exec_command(f"php {remote} && rm -f {remote}")
        print(out.read().decode().strip() or err.read().decode().strip())
    finally:
        ssh.close()


if __name__ == "__main__":
    main()
