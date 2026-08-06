"""Shared deploy credentials — never hardcode passwords in repo."""
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SECRETS_FILE = ROOT / "secrets" / "deploy.local.env"
LEGACY_ENV_FILE = ROOT / ".env.deploy"


def _parse_env_line(line: str) -> tuple[str, str] | None:
    line = line.strip()
    if not line or line.startswith("#"):
        return None
    if line.startswith("export "):
        line = line[7:].strip()
    if "=" not in line:
        return None
    key, _, value = line.partition("=")
    key = key.strip()
    if not key:
        return None
    value = value.strip()
    if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
        value = value[1:-1]
    return key, value


def load_deploy_env() -> None:
    """Load secrets/deploy.local.env into os.environ (existing env vars win)."""
    for path in (SECRETS_FILE, LEGACY_ENV_FILE):
        if not path.is_file():
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except OSError as err:
            print(f"WARN: Could not read {path}: {err}", file=sys.stderr)
            continue
        for line in text.splitlines():
            parsed = _parse_env_line(line)
            if not parsed:
                continue
            key, value = parsed
            if key not in os.environ:
                os.environ[key] = value
        return


load_deploy_env()

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "").strip()
USER = os.environ.get("ZINESH_DEPLOY_USER", "root").strip() or "root"


def require_deploy_host() -> str:
    if HOST:
        return HOST
    print(
        "ERROR: ZINESH_DEPLOY_HOST is not set.\n"
        f"       Fill in: {SECRETS_FILE}\n"
        f"       (copy from secrets/deploy.local.example.env)",
        file=sys.stderr,
    )
    sys.exit(1)


def require_deploy_pass() -> str:
    password = os.environ.get("ZINESH_DEPLOY_PASS", "").strip()
    if password:
        return password
    print(
        "ERROR: ZINESH_DEPLOY_PASS is not set.\n"
        f"       Fill in: {SECRETS_FILE}\n"
        f"       (copy from secrets/deploy.local.example.env)",
        file=sys.stderr,
    )
    sys.exit(1)


def require_founder_email() -> str:
    email = os.environ.get("ZINESH_FOUNDER_EMAIL", "").strip().lower()
    if email:
        return email
    print(
        "ERROR: ZINESH_FOUNDER_EMAIL is not set.\n"
        f"       Fill in: {SECRETS_FILE}",
        file=sys.stderr,
    )
    sys.exit(1)


def require_certbot_email() -> str:
    email = os.environ.get("ZINESH_CERTBOT_EMAIL", "").strip()
    if email:
        return email
    print(
        "ERROR: ZINESH_CERTBOT_EMAIL is not set.\n"
        f"       Fill in: {SECRETS_FILE}",
        file=sys.stderr,
    )
    sys.exit(1)
