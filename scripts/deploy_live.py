"""Deploy frontend dist + changed API PHP files to production VPS."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass, require_intelligence_regression_pass

PASSWORD = require_deploy_pass()
DEPLOY_HOST = require_deploy_host()
REMOTE_WWW = os.environ.get("ZINESH_WWW_ROOT", "/www/wwwroot/zinesh.com")
REMOTE_APP = os.environ.get("ZINESH_APP_ROOT", "/www/wwwroot/app.zinesh.com")
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", f"{REMOTE_WWW}/api")

ROOT = Path(__file__).resolve().parents[1]
LOCAL_DIST = ROOT / "dist"
API_DIR = ROOT / "api"

API_REL_PATHS = [
    "_bootstrap.php",
    "config.php",
    "security_lib.php",
    "session_auth_lib.php",
    "oauth_lib.php",
    "wallet_lib.php",
    "wallet.php",
    "withdraw_executor.php",
    "auth.php",
    "google_auth.php",
    "notifications_lib.php",
    "notifications.php",
    "trust_profile.php",
    "kyc_lib.php",
    "verification_lib.php",
    "phone_lib.php",
    "firebase_auth_lib.php",
    "password_reset_lib.php",
    "totp_lib.php",
    "totp_reset_lib.php",
    "campaign_lib.php",
    "email_lib.php",
    "early_access_lib.php",
    "admin.php",
    "founder_lib.php",
    "founder_profile_lib.php",
    "founder_platform_lib.php",
    "founder_platform.php",
    "founder_health.php",
    "founder_health_lib.php",
    "escrow_jobs_lib.php",
    "escrow_room_lib.php",
    "escrow_memory_lib.php",
    "zinesh_domain_events_lib.php",
    "contract_versions_lib.php",
    "escrow_room.php",
    "tl_havale_lib.php",
    "tl_mode_lib.php",
    "protocol_constants.php",
    "payment.php",
    "tl_payment_lib.php",
    "ai_chat.php",
    "ai_chat_lib.php",
    "ai_context.php",
    "ai_context_lib.php",
    "trust_intelligence_lib.php",
    "trust_metrics.php",
    "trust_metrics_lib.php",
    "actor_trust_lib.php",
    "actor_trust.php",
    "actor_trust_endpoint_lib.php",
    "veri_modeli.php",
    "scripts/bootstrap-data-files.php",
]


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str, str]:
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def upload_tree(sftp: paramiko.SFTPClient, local_dir: Path, remote_dir: str) -> None:
    for path in local_dir.rglob("*"):
        rel = path.relative_to(local_dir).as_posix()
        remote_path = f"{remote_dir}/{rel}" if rel != "." else remote_dir
        if path.is_dir():
            try:
                sftp.mkdir(remote_path)
            except OSError:
                pass
        else:
            sftp.put(str(path), remote_path)


def main() -> int:
    require_intelligence_regression_pass()

    if not LOCAL_DIST.is_dir():
        print(f"dist/ missing — run: npm run build ({LOCAL_DIST})")
        return 1

    api_files = [(rel, API_DIR / rel) for rel in API_REL_PATHS]
    missing = [rel for rel, path in api_files if not path.is_file()]
    if missing:
        print("Missing API files:", ", ".join(missing))
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{DEPLOY_HOST} ...")
    ssh.connect(DEPLOY_HOST, username=USER, password=PASSWORD, timeout=25)

    try:
        for remote in (REMOTE_WWW, REMOTE_APP, REMOTE_API):
            code, out, err = run(ssh, f"test -d {remote} && echo ok || echo missing")
            if "ok" not in out:
                print(f"Creating remote path: {remote}")
                run(ssh, f"mkdir -p {remote}")
                if remote == REMOTE_API:
                    run(ssh, f"mkdir -p {remote}/data {remote}/scripts")

        sftp = ssh.open_sftp()

        print("=== API PHP ===")
        for rel, local in api_files:
            remote = f"{REMOTE_API}/{rel.replace(chr(92), '/')}"
            parent = remote.rsplit("/", 1)[0]
            try:
                sftp.mkdir(parent)
            except OSError:
                pass
            print(f"  {rel} -> {remote}")
            sftp.put(str(local), remote)

        print(f"=== Frontend WWW {LOCAL_DIST} -> {REMOTE_WWW} ===")
        upload_tree(sftp, LOCAL_DIST, REMOTE_WWW)

        print(f"=== Frontend APP {LOCAL_DIST} -> {REMOTE_APP} ===")
        upload_tree(sftp, LOCAL_DIST, REMOTE_APP)
        sftp.close()

        probes = [
            "curl -s -o /dev/null -w 'www=%{http_code}\\n' -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' https://www.zinesh.com/",
            "curl -s -o /dev/null -w 'app=%{http_code}\\n' -A 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' https://app.zinesh.com/",
        ]
        print("=== Live probes ===")
        for cmd in probes:
            code, out, err = run(ssh, cmd)
            print(out.strip() or err.strip())

        print("Live deploy OK")

        _, out, err = run(ssh, f"php {REMOTE_API}/scripts/bootstrap-data-files.php 2>&1")
        bootstrap = (out or err).strip()
        if bootstrap:
            print("=== Data bootstrap ===")
            print(bootstrap)

        print("=== api/data permissions ===")
        data_dir = f"{REMOTE_API}/data"
        perm_cmds = [
            f"chown -R www-data:www-data {data_dir}",
            f"chmod 775 {data_dir}",
            f"chmod 664 {data_dir}/*.json 2>/dev/null || true",
        ]
        for cmd in perm_cmds:
            run(ssh, cmd)
        print("www-data write access OK")

        print("=== app /api nginx proxy ===")
        fix_script = ROOT / "scripts" / "fix_app_nginx_api.py"
        import subprocess

        proc = subprocess.run([sys.executable, str(fix_script)], cwd=str(ROOT))
        if proc.returncode != 0:
            print("WARN: fix_app_nginx_api.py failed — app API may need manual fix")
        else:
            print("app /api proxy OK")

        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
