#!/usr/bin/env python3
"""Run manual GDrive backup test on production and collect sanitized report."""
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
SNAPSHOT = "2026-08-09_11-14"
LOCAL_SNAPSHOT = f"/www/wwwroot/zinesh.com/api/data/backups/{SNAPSHOT}"
HEALTH = f"{API_REMOTE}/data/backup_health.json"
FILES = [
    ("api/backup_gdrive_lib.php", f"{API_REMOTE}/backup_gdrive_lib.php"),
    ("api/backup_lib.php", f"{API_REMOTE}/backup_lib.php"),
    ("api/scripts/backup-gdrive-test.php", f"{API_REMOTE}/scripts/backup-gdrive-test.php"),
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

    code, pre_hash, _ = run(
        ssh,
        f"find {API_REMOTE}/data -type f ! -path '*/backups/*' -exec md5sum {{}} + 2>/dev/null | wc -l",
    )

    code, out, err = run(
        ssh,
        f"php {API_REMOTE}/scripts/backup-gdrive-test.php {SNAPSHOT} 2>&1",
        timeout=1200,
    )
    print("=== TEST OUTPUT ===")
    print(sanitize(out))
    if err:
        print(sanitize(err), file=sys.stderr)

    code2, post_hash, _ = run(
        ssh,
        f"find {API_REMOTE}/data -type f ! -path '*/backups/*' -exec md5sum {{}} + 2>/dev/null | wc -l",
    )

    code3, local_stats, _ = run(
        ssh,
        f"find {LOCAL_SNAPSHOT} -type f | wc -l; "
        f"find {LOCAL_SNAPSHOT} -type f -printf '%s\\n' | awk '{{s+=$1}} END {{print s+0}}'",
    )
    local_lines = local_stats.strip().splitlines()

    code4, health_raw, _ = run(ssh, f"cat {HEALTH} 2>/dev/null")
    health = {}
    try:
        health = json.loads(health_raw)
    except json.JSONDecodeError:
        health = {"parse_error": True}

    safe_health = {
        k: health.get(k)
        for k in (
            "status",
            "localBackupStatus",
            "lastBackup",
            "lastGoogleDriveBackup",
            "googleDriveStatus",
            "googleDrivePath",
            "googleDriveFileCount",
            "googleDriveSizeBytes",
            "googleDriveVerification",
            "googleDriveLastError",
            "restoreTestStatus",
            "gdrive",
            "gdriveConnected",
        )
        if k in health
    }
    print("=== BACKUP HEALTH (sanitized) ===")
    print(json.dumps(safe_health, indent=2, ensure_ascii=False))
    print("=== LOCAL SNAPSHOT STATS ===")
    print(f"files={local_lines[0] if local_lines else '?'}")
    print(f"bytes={local_lines[1] if len(local_lines) > 1 else '?'}")
    print(f"production_live_file_count_unchanged={pre_hash.strip() == post_hash.strip()}")
    print(f"test_exit={code}")

    ssh.close()
    return code


if __name__ == "__main__":
    raise SystemExit(main())
