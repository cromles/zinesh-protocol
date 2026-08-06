"""Check deploy.local.env without printing secret values."""
from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
path = ROOT / "secrets" / "deploy.local.env"

if not path.is_file():
    print("MISSING:", path)
    raise SystemExit(1)

required = ("ZINESH_DEPLOY_HOST", "ZINESH_DEPLOY_PASS")
optional = ("ZINESH_DEPLOY_USER", "ZINESH_WWW_ROOT", "ZINESH_APP_ROOT", "ZINESH_REMOTE_API")
values: dict[str, str] = {}

for line in path.read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if not line or line.startswith("#") or "=" not in line:
        continue
    key, _, value = line.partition("=")
    values[key.strip()] = value.strip().strip('"').strip("'")

print("secrets/deploy.local.env")
for key in required + optional:
    val = values.get(key, "")
    status = "SET" if val else "EMPTY"
    print(f"  {key}: {status}")

missing = [k for k in required if not values.get(k)]
if missing:
    print("FILL_REQUIRED:", ", ".join(missing))
    raise SystemExit(1)

print("READY")
