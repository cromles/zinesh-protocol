"""Configure Brevo mail on production (reads BREVO_SMTP_KEY from env)."""
from __future__ import annotations

import os
import shlex
import sys
from pathlib import Path

import paramiko

sys.path.insert(0, str(Path(__file__).resolve().parent))
from deploy_common import USER, require_deploy_host, require_deploy_pass

API_ROOT = "/www/wwwroot/zinesh.com/api"
TEST_EMAIL = os.environ.get("ZINESH_MAIL_TEST_TO", "yasinkarademir147@gmail.com")
SMTP_USER = os.environ.get("BREVO_SMTP_USER", "b4a321001@smtp-brevo.com").strip()
MAIL_REPLY_TO = os.environ.get(
    "ZINESH_MAIL_REPLY_TO",
    os.environ.get("ZINESH_FOUNDER_EMAIL", "yasinkarademir147@gmail.com"),
).strip()
MAIL_FROM = os.environ.get("ZINESH_MAIL_FROM", MAIL_REPLY_TO).strip()


def main() -> int:
    key = os.environ.get("BREVO_SMTP_KEY", "").strip()
    if not key:
        print("ERROR: BREVO_SMTP_KEY env var required", file=sys.stderr)
        return 1

    host = require_deploy_host()
    pw = require_deploy_pass()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(host, username=USER, password=pw, timeout=25)

    api_key = os.environ.get("BREVO_API_KEY", "").strip()
    if not api_key and key.startswith("xkeysib-"):
        api_key = key

    parts = [
        f"cd {API_ROOT} && php setup_mail.php",
        "--host smtp-relay.brevo.com --port 587 --encryption tls",
        f"--user {shlex.quote(SMTP_USER)}",
        f"--pass {shlex.quote(key)}",
        f"--from {shlex.quote(MAIL_FROM)}",
        f"--reply-to {shlex.quote(MAIL_REPLY_TO)}",
    ]
    if api_key:
        parts.append(f"--api-key {shlex.quote(api_key)}")
    parts.append(f"--test {shlex.quote(TEST_EMAIL)}")
    cmd = " ".join(parts)
    _, out, err = ssh.exec_command(cmd)
    stdout = out.read().decode("utf-8", errors="replace")
    stderr = err.read().decode("utf-8", errors="replace")
    safe_out = stdout.replace(key, "***")
    safe_err = stderr.replace(key, "***")
    print(safe_out, end="" if safe_out.endswith("\n") else "\n")
    if safe_err.strip():
        print(safe_err.replace(key, "***"), file=sys.stderr)

    verify_php = """<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';
echo 'brevo=' . (zinesh_brevo_api_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp=' . (zinesh_smtp_configured() ? 'yes' : 'no') . PHP_EOL;
"""
    sftp = ssh.open_sftp()
    remote = "/tmp/_mail_verify.php"
    with sftp.file(remote, "w") as f:
        f.write(verify_php)
    sftp.close()
    _, out2, _ = ssh.exec_command(f"php {remote}; rm -f {remote}")
    verify_out = out2.read().decode("utf-8", errors="replace")
    print(verify_out)

    ssh.close()
    if "Test e-postası gönderildi" in stdout or "Test e-postası gönderildi" in safe_out:
        return 0
    if "535" in safe_out or "Authentication failed" in safe_out:
        print(
            "\nBrevo SMTP girişi reddetti (535). Brevo panelinde:\n"
            "  SMTP & API → SMTP keys → Login = yasinkarademir147@gmail.com\n"
            "  Anahtarı yeniden oluşturup tekrar dene.\n",
            file=sys.stderr,
        )
        return 1
    return 0 if "SMTP kaydedildi" in safe_out else 1


if __name__ == "__main__":
    raise SystemExit(main())
