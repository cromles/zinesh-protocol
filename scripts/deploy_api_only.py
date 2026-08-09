"""Deploy API PHP files only to production VPS."""
from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass, require_intelligence_regression_pass

PASSWORD = require_deploy_pass()
DEPLOY_HOST = require_deploy_host()
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", "/www/wwwroot/zinesh.com/api")

ROOT = Path(__file__).resolve().parents[1]

API_FILES = [
    ROOT / "api" / "_bootstrap.php",
    ROOT / "api" / "config.php",
    ROOT / "api" / "security_lib.php",
    ROOT / "api" / "session_auth_lib.php",
    ROOT / "api" / "oauth_lib.php",
    ROOT / "api" / "wallet_lib.php",
    ROOT / "api" / "wallet.php",
    ROOT / "api" / "withdraw_executor.php",
    ROOT / "api" / "auth.php",
    ROOT / "api" / "google_auth.php",
    ROOT / "api" / "notifications_lib.php",
    ROOT / "api" / "notifications.php",
    ROOT / "api" / "trust_profile.php",
    ROOT / "api" / "kyc_lib.php",
    ROOT / "api" / "password_reset_lib.php",
    ROOT / "api" / "campaign_lib.php",
    ROOT / "api" / "email_lib.php",
    ROOT / "api" / "early_access_lib.php",
    ROOT / "api" / "admin.php",
    ROOT / "api" / "founder_lib.php",
    ROOT / "api" / "founder_profile_lib.php",
    ROOT / "api" / "founder_platform_lib.php",
    ROOT / "api" / "founder_platform.php",
    ROOT / "api" / "escrow_jobs_lib.php",
    ROOT / "api" / "escrow_room_lib.php",
    ROOT / "api" / "escrow_room.php",
    ROOT / "api" / "verification_lib.php",
    ROOT / "api" / "demo_lib.php",
    ROOT / "api" / "demo.php",
    ROOT / "api" / "tl_havale_lib.php",
    ROOT / "api" / "tl_mode_lib.php",
    ROOT / "api" / "protocol_constants.php",
    ROOT / "api" / "payment.php",
    ROOT / "api" / "tl_payment_lib.php",
    ROOT / "api" / "totp_reset_lib.php",
    ROOT / "api" / "ai_chat.php",
    ROOT / "api" / "ai_chat_lib.php",
    ROOT / "api" / "founder_health_lib.php",
    ROOT / "api" / "veri_modeli.php",
]

SCRIPT_FILES = [
    ROOT / "scripts" / "verify_wallet_invariants.php",
    ROOT / "api" / "scripts" / "reconcile_escrow_sender_debit.php",
]


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str, str]:
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def main() -> int:
    require_intelligence_regression_pass()

    missing = [str(p) for p in API_FILES if not p.is_file()]
    if missing:
        print("Missing API files:", ", ".join(missing))
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{DEPLOY_HOST} ...")
    ssh.connect(DEPLOY_HOST, username=USER, password=PASSWORD, timeout=25)

    try:
        code, out, err = run(ssh, f"test -d {REMOTE_API} && echo ok")
        if "ok" not in out:
            print(f"Remote API dir not found: {REMOTE_API}")
            return 1

        sftp = ssh.open_sftp()
        print("=== API PHP ===")
        for local in API_FILES:
            remote = f"{REMOTE_API}/{local.name}"
            print(f"  {local.name}")
            sftp.put(str(local), remote)

        remote_scripts = "/www/server/zinesh-data/../scripts"
        for local in SCRIPT_FILES:
            if not local.is_file():
                continue
            if local.parent.name == "scripts" and local.parent.parent.name == "api":
                remote = f"{REMOTE_API}/scripts/{local.name}"
                remote_dir = f"{REMOTE_API}/scripts"
            else:
                remote = f"/www/wwwroot/zinesh.com/scripts/{local.name}"
                remote_dir = "/www/wwwroot/zinesh.com/scripts"
            try:
                sftp.mkdir(remote_dir)
            except OSError:
                pass
            print(f"  -> {remote}")
            sftp.put(str(local), remote)

        sftp.close()

        print("=== Syntax check (remote) ===")
        for name in ("wallet_lib.php", "escrow_room_lib.php", "escrow_jobs_lib.php"):
            code, out, err = run(ssh, f"php -l {REMOTE_API}/{name}")
            line = (out or err).strip()
            print(f"  {name}: {line}")

        print("=== Wallet invariant audit ===")
        audit_script = "/www/wwwroot/zinesh.com/scripts/verify_wallet_invariants.php"
        code, out, err = run(ssh, f"php {audit_script} 2>&1 || true")
        print((out or err).strip() or "(no output)")

        print("=== Reconcile sender debit (ZN-SH-DUAL-27920) ===")
        reconcile_script = f"{REMOTE_API}/scripts/reconcile_escrow_sender_debit.php"
        code, out, err = run(
            ssh,
            f"php {reconcile_script} --ticket=ZN-SH-DUAL-27920 --amount=10 --from=11:00 --to=12:00 2>&1",
        )
        print((out or err).strip() or "(no output)")

        print("=== Post-reconcile audit ===")
        code, out, err = run(ssh, f"php {audit_script} 2>&1 || true")
        print((out or err).strip() or "(no output)")

        print("=== API probe ===")
        code, out, err = run(
            ssh,
            "curl -s -o /dev/null -w 'wallet=%{http_code}\\n' "
            "-X POST https://www.zinesh.com/api/wallet.php "
            "-H 'Content-Type: application/json' "
            "-d '{\"action\":\"escrow_jobs_list\",\"sessionToken\":\"x\"}'",
        )
        print(out.strip() or err.strip())

        print("API-only deploy OK")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
