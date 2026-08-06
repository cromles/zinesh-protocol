"""Merge tl_havale (IBAN) from config.php into live api/config.local.php."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASSWORD = os.environ.get("ZINESH_DEPLOY_PASS", "")
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", "/www/wwwroot/zinesh.com/api")

ROOT = Path(__file__).resolve().parents[1]

ENSURE_PHP = r"""<?php
declare(strict_types=1);
$apiDir = '/www/wwwroot/zinesh.com/api';
$path = $apiDir . '/config.local.php';
$local = is_readable($path) ? require $path : [];
if (!is_array($local)) {
    $local = [];
}
$base = require $apiDir . '/config.php';
$havale = is_array($base['tl_havale'] ?? null) ? $base['tl_havale'] : [];
if ($havale === [] || trim((string)($havale['iban'] ?? '')) === '') {
    fwrite(STDERR, "config.php has no tl_havale.iban\n");
    exit(1);
}
$local['tl_havale'] = array_replace(
    is_array($local['tl_havale'] ?? null) ? $local['tl_havale'] : [],
    $havale
);
$local['tl_havale']['enabled'] = true;
$export = var_export($local, true);
$php = "<?php\ndeclare(strict_types=1);\n/** Merged by scripts/ensure_havale_config.py */\nreturn {$export};\n";
file_put_contents($path, $php, LOCK_EX);
@chmod($path, 0640);
require_once $apiDir . '/_bootstrap.php';
require_once $apiDir . '/tl_havale_lib.php';
require_once $apiDir . '/tl_mode_lib.php';
echo 'havale_enabled=' . (zinesh_havale_enabled() ? '1' : '0') . "\n";
echo 'iban_len=' . strlen(zinesh_havale_iban_normalized()) . "\n";
"""


def main() -> int:
    local_config = ROOT / "api" / "config.php"
    if not local_config.is_file():
        print(f"Missing {local_config}")
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{HOST} ...")
    ssh.connect(HOST, username=USER, password=PASSWORD, timeout=25)

    try:
        sftp = ssh.open_sftp()
        remote_config = f"{REMOTE_API}/config.php"
        remote_bootstrap = f"{REMOTE_API}/_bootstrap.php"
        print(f"Upload config.php -> {remote_config}")
        sftp.put(str(local_config), remote_config)
        bootstrap = ROOT / "api" / "_bootstrap.php"
        if bootstrap.is_file():
            print(f"Upload _bootstrap.php -> {remote_bootstrap}")
            sftp.put(str(bootstrap), remote_bootstrap)
        for extra in ("protocol_constants.php", "tl_mode_lib.php", "wallet_lib.php", "tl_havale_lib.php"):
            local_extra = ROOT / "api" / extra
            if local_extra.is_file():
                remote_extra = f"{REMOTE_API}/{extra}"
                print(f"Upload {extra} -> {remote_extra}")
                sftp.put(str(local_extra), remote_extra)

        remote_script = "/tmp/zinesh_ensure_havale.php"
        with sftp.file(remote_script, "w") as f:
            f.write(ENSURE_PHP)
        sftp.close()

        _, stdout, stderr = ssh.exec_command(f"php {remote_script}; rm -f {remote_script}")
        out = stdout.read().decode("utf-8", errors="replace").strip()
        err = stderr.read().decode("utf-8", errors="replace").strip()
        if out:
            print(out)
        if err:
            print(err, file=sys.stderr)
        code = stdout.channel.recv_exit_status()
        if code != 0:
            return code
        print("Havale IBAN config OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
