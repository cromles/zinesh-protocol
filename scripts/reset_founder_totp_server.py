"""Find users.json on server and fix data dir permissions for PHP writes."""
from __future__ import annotations

import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass, require_founder_email

FOUNDER_EMAIL = require_founder_email()

PHP = r"""<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/password_reset_lib.php';
require '/www/wwwroot/zinesh.com/api/founder_lib.php';
require '/www/wwwroot/zinesh.com/api/totp_lib.php';
$resolved = zinesh_resolve_data_dir();
$path = zinesh_data_path('users.json');
echo "resolved_dir={$resolved}\n";
echo "users_path={$path}\n";
echo "users_exists=" . (is_file($path) ? 'yes' : 'no') . "\n";
echo "dir_writable=" . (is_writable(dirname($path)) ? 'yes' : 'no') . "\n";
echo "file_writable=" . (is_file($path) && is_writable($path) ? 'yes' : 'no') . "\n";
$u = zinesh_find_user_by_email('""" + FOUNDER_EMAIL + r"""');
if (!$u) { echo "founder=missing\n"; exit(1); }
$uid = (string)($u['uid'] ?? '');
zinesh_update_user($uid, static function (array &$row) {
    unset($row['totpSecret'], $row['totpPendingSecret'], $row['totpEnabled'], $row['totpEnabledAt']);
});
$after = zinesh_find_user_by_uid($uid) ?? [];
echo "founder_uid={$uid}\n";
echo "totp_cleared=" . (empty($after['totpSecret']) && empty($after['totpPendingSecret']) && empty($after['totpEnabled']) ? 'yes' : 'no') . "\n";
$test = zinesh_totp_generate_secret();
$code = zinesh_totp_code($test);
echo "totp_self_verify=" . (zinesh_totp_verify($test, $code) ? 'yes' : 'no') . "\n";
echo "OK\n";
"""


def main() -> int:
    host = require_deploy_host()
    password = require_deploy_pass()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{host} ...")
    ssh.connect(host, username=USER, password=password, timeout=25)
    try:
        find_cmd = (
            "find /www -name users.json 2>/dev/null | head -20; "
            "echo '---'; "
            "php -r \"require '/www/wwwroot/zinesh.com/api/wallet_lib.php'; "
            "echo 'resolve=' . zinesh_resolve_data_dir() . PHP_EOL; "
            "echo 'path=' . zinesh_data_path('users.json') . PHP_EOL;\""
        )
        _, out, _ = ssh.exec_command(find_cmd)
        print(out.read().decode().strip())

        fix_cmd = (
            "php -r \"require '/www/wwwroot/zinesh.com/api/wallet_lib.php'; "
            "\\$d=zinesh_resolve_data_dir(); "
            "\\$p=\\$d.'/users.json'; "
            "if(!is_dir(\\$d)) mkdir(\\$d,0750,true); "
            "if(!is_file(\\$p)) file_put_contents(\\$p,'[]'); "
            "system('chown -R www:www '.escapeshellarg(\\$d).' 2>/dev/null'); "
            "system('chown -R www-data:www-data '.escapeshellarg(\\$d).' 2>/dev/null'); "
            "@chmod(\\$d,0770); if(is_file(\\$p)) @chmod(\\$p,0660); "
            "echo 'fixed:' . \\$p;\""
        )
        _, out, err = ssh.exec_command(fix_cmd)
        print(out.read().decode().strip() or err.read().decode().strip())

        sftp = ssh.open_sftp()
        remote = "/tmp/zinesh_reset_totp.php"
        with sftp.file(remote, "w") as f:
            f.write(PHP)
        sftp.close()

        _, out, err = ssh.exec_command(f"php {remote} && rm -f {remote}")
        result = out.read().decode().strip() or err.read().decode().strip()
        print(result)
        return 0 if "OK" in result else 1
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
