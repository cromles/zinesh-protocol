#!/usr/bin/env python3
"""Deploy hourly backup cron + run manual validation on production."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

import paramiko

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "scripts"))
from deploy_common import require_deploy_host, require_deploy_pass  # noqa: E402

API_REMOTE = "/www/wwwroot/zinesh.com/api"
CRON_LINE = f"0 * * * * /usr/bin/php {API_REMOTE}/cron_backup.php >> /var/log/zinesh-backup-cron.log 2>&1"
FILES = [
    ("api/backup_gdrive_lib.php", f"{API_REMOTE}/backup_gdrive_lib.php"),
    ("api/backup_lib.php", f"{API_REMOTE}/backup_lib.php"),
    ("api/cron_backup.php", f"{API_REMOTE}/cron_backup.php"),
]


def run(ssh: paramiko.SSHClient, cmd: str, timeout: int = 900) -> tuple[int, str, str]:
    _, stdout, stderr = ssh.exec_command(cmd, timeout=timeout)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    return code, out, err


def sanitize(text: str) -> str:
    text = re.sub(r'"access_token"\s*:\s*"[^"]*"', '"access_token":"[REDACTED]"', text)
    text = re.sub(r'"refresh_token"\s*:\s*"[^"]*"', '"refresh_token":"[REDACTED]"', text)
    return text


def main() -> int:
    host = require_deploy_host()
    password = require_deploy_pass()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username="root", password=password, timeout=30)

    sftp = ssh.open_sftp()
    for local_rel, remote in FILES:
        sftp.put(str(ROOT / local_rel), remote)
    sftp.close()
    print("=== deployed files ===")
    for _, remote in FILES:
        print(remote)

    code, pre_count, _ = run(
        ssh,
        f"find {API_REMOTE}/data -type f ! -path '*/backups/*' | wc -l",
    )

    code, manual, err = run(ssh, f"php {API_REMOTE}/cron_backup.php 2>&1", timeout=1200)
    print("=== manual cron_backup ===")
    print(sanitize(manual))
    if err:
        print(sanitize(err), file=sys.stderr)
    if code != 0:
        print(f"manual test FAILED exit={code}")
        ssh.close()
        return code

    manual_json = {}
    try:
        manual_json = json.loads(manual)
    except json.JSONDecodeError:
        pass

    local_ok = bool((manual_json.get("local") or {}).get("ok"))
    gdrive_ok = bool((manual_json.get("gdrive") or {}).get("ok"))
    gdrive_skipped = bool((manual_json.get("gdrive") or {}).get("skipped"))

    if not local_ok:
        print("manual test FAILED: local backup not ok")
        ssh.close()
        return 2
    if not gdrive_ok and not gdrive_skipped:
        print("manual test FAILED: gdrive upload not ok")
        ssh.close()
        return 3

    code, cron_before, _ = run(ssh, "crontab -l 2>/dev/null || true")
    if CRON_LINE not in cron_before:
        new_cron = (cron_before.rstrip() + "\n" + CRON_LINE + "\n").lstrip()
        escaped = new_cron.replace("'", "'\"'\"'")
        run(ssh, f"printf '%s' '{escaped}' | crontab -")
        print("=== cron installed ===")
    else:
        print("=== cron already present ===")
    print(CRON_LINE)

    code, cron_after, _ = run(ssh, "crontab -l 2>/dev/null")
    print("=== crontab -l ===")
    print(cron_after.strip())

    code, cron_user, _ = run(ssh, "crontab -l >/dev/null 2>&1 && echo root || echo unknown")
    print("=== cron user ===")
    print(cron_user.strip())

    code, first_cron, _ = run(ssh, f"php {API_REMOTE}/cron_backup.php 2>&1", timeout=1200)
    print("=== first cron-equivalent run ===")
    print(sanitize(first_cron))
    if code != 0:
        print(f"first cron test FAILED exit={code}")
        ssh.close()
        return code

    code, health_raw, _ = run(ssh, f"cat {API_REMOTE}/data/backup_health.json")
    health = json.loads(health_raw) if health_raw.strip() else {}
    safe = {k: health.get(k) for k in (
        "status", "localBackupStatus", "lastBackup", "lastGoogleDriveBackup",
        "googleDriveStatus", "googleDrivePath", "googleDriveFileCount",
        "googleDriveSizeBytes", "googleDriveVerification", "googleDriveLastError",
        "lastGoogleDriveSnapshot", "gdrive", "gdriveConnected",
    ) if k in health}
    print("=== backup_health ===")
    print(json.dumps(safe, indent=2, ensure_ascii=False))

    code, post_count, _ = run(
        ssh,
        f"find {API_REMOTE}/data -type f ! -path '*/backups/*' | wc -l",
    )
    print(f"production_live_files_unchanged={pre_count.strip() == post_count.strip()}")

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
