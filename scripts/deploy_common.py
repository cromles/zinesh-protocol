"""Shared deploy credentials — never hardcode passwords in repo."""
from __future__ import annotations

import hashlib
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
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


def resolve_deploy_key_path() -> Path | None:
    """Return private key path when ZINESH_DEPLOY_KEY points to an existing file."""
    raw = os.environ.get("ZINESH_DEPLOY_KEY", "").strip()
    if not raw:
        return None
    path = Path(raw).expanduser()
    if not path.is_absolute():
        path = (ROOT / path).resolve()
    else:
        path = path.resolve()
    if not path.is_file():
        print(f"WARN: ZINESH_DEPLOY_KEY path not found: {path}", file=sys.stderr)
        return None
    return path


def _deploy_key_passphrase() -> str | None:
    value = os.environ.get("ZINESH_DEPLOY_KEY_PASSPHRASE", "").strip()
    return value if value else None


def load_deploy_private_key(key_path: Path):
    """Load Ed25519 private key; passphrase from ZINESH_DEPLOY_KEY_PASSPHRASE when set."""
    import paramiko

    try:
        return paramiko.Ed25519Key.from_private_key_file(
            str(key_path),
            password=_deploy_key_passphrase(),
        )
    except paramiko.PasswordRequiredException:
        print(
            "ERROR: SSH private key is encrypted.\n"
            f"       Set ZINESH_DEPLOY_KEY_PASSPHRASE in {SECRETS_FILE}",
            file=sys.stderr,
        )
        sys.exit(1)
    except paramiko.SSHException as err:
        print(
            f"ERROR: Could not load SSH private key ({key_path}): {err}",
            file=sys.stderr,
        )
        sys.exit(1)


def resolve_deploy_auth_method() -> str:
    """Return 'key', 'password', or 'none' (no secret values)."""
    if resolve_deploy_key_path() is not None:
        return "key"
    if os.environ.get("ZINESH_DEPLOY_PASS", "").strip():
        return "password"
    return "none"


def connect_deploy_ssh(*, timeout: int = 60):
    """Open SSH session: Ed25519 key first, password fallback during migration."""
    import paramiko

    host = require_deploy_host()
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())

    key_path = resolve_deploy_key_path()
    if key_path is not None:
        pkey = load_deploy_private_key(key_path)
        ssh.connect(host, username=USER, pkey=pkey, timeout=timeout)
        return ssh

    password = os.environ.get("ZINESH_DEPLOY_PASS", "").strip()
    if password:
        ssh.connect(host, username=USER, password=password, timeout=timeout)
        return ssh

    print(
        "ERROR: No deploy SSH credentials.\n"
        f"       Set ZINESH_DEPLOY_KEY (preferred) or ZINESH_DEPLOY_PASS in {SECRETS_FILE}",
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


REGRESSION_REPORT = ROOT / "intelligence-regression.json"
EXPECTED_REPORT_KIND = "intelligence_regression_slice"
EXPECTED_REGRESSION_VERSION = "1.0"
DEFAULT_REGRESSION_MAX_AGE_HOURS = 24
EXPECTED_SECTION_IDS = (
    "architecture_validation",
    "risk_engine",
    "risk_engine_backend",
    "copilot_framework",
    "openai_provider_stub",
    "copilot_evaluation",
    "frontend_contracts",
)


def regression_max_age_seconds() -> int:
    raw = os.environ.get("ZINESH_REGRESSION_MAX_AGE_HOURS", "").strip()
    if raw.isdigit() and int(raw) > 0:
        return int(raw) * 3600
    return DEFAULT_REGRESSION_MAX_AGE_HOURS * 3600


def current_git_head() -> str | None:
    try:
        result = subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return None
    if result.returncode != 0:
        return None
    head = result.stdout.strip()
    return head if re.fullmatch(r"[0-9a-f]{40}", head) else None


def _parse_report_timestamp(value: object) -> datetime | None:
    if not isinstance(value, str) or value.strip() == "":
        return None
    text = value.strip()
    if text.endswith("Z"):
        text = text[:-1] + "+00:00"
    try:
        parsed = datetime.fromisoformat(text)
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


FINGERPRINT_EXCLUDE_KEYS = frozenset({
    "execution_ms",
    "duration_ms",
    "generated_at",
    "started_at",
    "finished_at",
    "source_started_at",
    "source_git_head",
    "stdout",
    "stderr",
    "diagnostics",
    "reliability",
    "report_fingerprint",
})


def _strip_report_for_fingerprint(value: object) -> object:
    if isinstance(value, dict):
        return {
            key: _strip_report_for_fingerprint(child)
            for key, child in value.items()
            if key not in FINGERPRINT_EXCLUDE_KEYS
        }
    if isinstance(value, list):
        return [_strip_report_for_fingerprint(child) for child in value]
    return value


def compute_report_fingerprint(payload: dict) -> str:
    stripped = _strip_report_for_fingerprint(payload)
    encoded = json.dumps(stripped, ensure_ascii=False, separators=(",", ":"))
    return hashlib.sha256(encoded.encode("utf-8")).hexdigest()


def validate_intelligence_regression_report(payload: dict) -> str | None:
    """Return error message when invalid; None when deploy may proceed."""
    if payload.get("report_kind") != EXPECTED_REPORT_KIND:
        return f"report_kind must be {EXPECTED_REPORT_KIND!r}"

    if payload.get("intelligence_regression_version") != EXPECTED_REGRESSION_VERSION:
        return f"intelligence_regression_version must be {EXPECTED_REGRESSION_VERSION!r}"

    fingerprint = payload.get("report_fingerprint")
    if not isinstance(fingerprint, str) or not re.fullmatch(r"[0-9a-f]{64}", fingerprint):
        return "report_fingerprint must be a 64-char sha256 hex digest"

    computed = compute_report_fingerprint(payload)
    if computed != fingerprint:
        return "report_fingerprint does not match report contents"

    reliability = payload.get("reliability")
    if not isinstance(reliability, dict):
        return "reliability block is required"
    issues = reliability.get("issues")
    if not isinstance(issues, list):
        return "reliability.issues must be an array"
    if issues:
        return "reliability.issues must be empty for deploy: " + ", ".join(str(i) for i in issues)

    generated_at = _parse_report_timestamp(payload.get("generated_at"))
    if generated_at is None:
        return "generated_at must be a valid ISO-8601 timestamp"

    age_seconds = (datetime.now(timezone.utc) - generated_at).total_seconds()
    if age_seconds < 0:
        return "generated_at is in the future"
    if age_seconds > regression_max_age_seconds():
        max_hours = regression_max_age_seconds() // 3600
        return f"regression report is stale (older than {max_hours}h); re-run intelligence regression"

    sections = payload.get("sections")
    if not isinstance(sections, list) or sections == []:
        return "sections must be a non-empty array"

    seen_ids: set[str] = set()
    for section in sections:
        if not isinstance(section, dict):
            return "each section must be an object"
        section_id = section.get("id")
        if not isinstance(section_id, str) or section_id == "":
            return "each section must have a non-empty id"
        seen_ids.add(section_id)
        section_status = section.get("status")
        section_exit = section.get("exit_code")
        if section_status == "PASS":
            if section_exit != 0:
                return f"section {section_id} claims PASS but exit_code is {section_exit!r}"
        else:
            return f"section {section_id} is not PASS"
        anomalies = section.get("anomalies")
        if isinstance(anomalies, list) and anomalies:
            return f"section {section_id} has anomalies: " + ", ".join(str(a) for a in anomalies)

    missing_sections = [sid for sid in EXPECTED_SECTION_IDS if sid not in seen_ids]
    if missing_sections:
        return "missing required sections: " + ", ".join(missing_sections)

    report_head = payload.get("source_git_head")
    local_head = current_git_head()
    if local_head is not None:
        if not isinstance(report_head, str) or not re.fullmatch(r"[0-9a-f]{40}", report_head):
            return "source_git_head must match current git HEAD"
        if report_head != local_head:
            return "source_git_head does not match current git HEAD; re-run intelligence regression"

    if payload.get("overall") != "PASS":
        return "overall is not PASS"

    return None


def require_intelligence_regression_pass() -> None:
    """Block deploy unless intelligence-regression.json is fresh and fully PASS."""
    if os.environ.get("ZINESH_SKIP_REGRESSION_GATE", "").strip() == "1":
        print(
            "WARN: deploy regression gate skipped (ZINESH_SKIP_REGRESSION_GATE=1)",
            file=sys.stderr,
        )
        return

    if not REGRESSION_REPORT.is_file():
        print(
            "ERROR: Deploy blocked — intelligence-regression.json missing.\n"
            "       Run: php api/scripts/run-intelligence-regression.php",
            file=sys.stderr,
        )
        sys.exit(1)

    try:
        payload = json.loads(REGRESSION_REPORT.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as err:
        print(
            f"ERROR: Deploy blocked — cannot read regression report: {err}",
            file=sys.stderr,
        )
        sys.exit(1)

    validation_error = validate_intelligence_regression_report(payload)
    if validation_error is not None:
        print(
            "ERROR: Deploy blocked — intelligence regression report failed validation.\n"
            f"       {validation_error}\n"
            f"       Report: {REGRESSION_REPORT}\n"
            "       Run: php api/scripts/run-intelligence-regression.php",
            file=sys.stderr,
        )
        sys.exit(1)
