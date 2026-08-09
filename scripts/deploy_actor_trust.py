"""Deploy Actor Trust API layer only — backup data, upload new files, smoke test, rollback on failure."""
from __future__ import annotations

import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

import paramiko

from deploy_common import USER, require_deploy_host, require_deploy_pass, require_intelligence_regression_pass

PASSWORD = require_deploy_pass()
DEPLOY_HOST = require_deploy_host()
REMOTE_API = os.environ.get("ZINESH_REMOTE_API", "/www/wwwroot/zinesh.com/api")
REMOTE_DATA = f"{REMOTE_API}/data"
API_BASE = os.environ.get("ZINESH_API_URL", "https://www.zinesh.com/api")

ROOT = Path(__file__).resolve().parents[1]
API_DIR = ROOT / "api"

DEPLOY_FILES = [
    "actor_trust.php",
    "actor_trust_endpoint_lib.php",
    "actor_trust_lib.php",
    "scripts/e2e-actor-trust-test.php",
    "scripts/e2e-actor-trust-api-test.php",
]

# Read-only observation katmanı — sunucuda yoksa yüklenir; escrow çekirdeği değil.
READ_ONLY_DEPS = [
    "zinesh_domain_events_lib.php",
    "contract_versions_lib.php",
    "escrow_memory_lib.php",
    "ai_context_lib.php",
    "trust_intelligence_lib.php",
]

REQUIRED_DEPS = [
    "trust_intelligence_lib.php",
    "escrow_room_lib.php",
    "wallet_lib.php",
    "tl_mode_lib.php",
]

SMOKE_PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';
require_once $api . '/escrow_room_lib.php';

$now = time();
$token = '';
$uid = '';
$sessions = zinesh_json_read('sessions.json');
if (is_array($sessions)) {
    foreach ($sessions as $sessToken => $session) {
        if (!is_string($sessToken) || !is_array($session)) {
            continue;
        }
        if ((int)($session['expires'] ?? 0) < $now) {
            continue;
        }
        $candidateUid = (string)($session['uid'] ?? '');
        if ($candidateUid === '') {
            continue;
        }
        $validated = zinesh_validate_session_token($sessToken, false);
        if (!($validated['ok'] ?? false)) {
            continue;
        }
        $token = $sessToken;
        $uid = $candidateUid;
        break;
    }
}
if ($token === '') {
    $users = zinesh_load_users();
    $pick = null;
    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        $id = (string)($u['uid'] ?? '');
        if ($id !== '') {
            $pick = $u;
            break;
        }
    }
    if (!$pick) {
        echo json_encode(['error' => 'no_user']);
        exit(1);
    }
    $uid = (string)$pick['uid'];
    $token = zinesh_create_session($uid);
}

$participantUids = [];
foreach (zinesh_escrow_rooms_load() as $room) {
    if (!is_array($room)) {
        continue;
    }
    $e = (string)($room['employerUid'] ?? '');
    $w = (string)($room['workerUid'] ?? '');
    if ($e !== '') {
        $participantUids[$e] = true;
    }
    if ($w !== '') {
        $participantUids[$w] = true;
    }
}

$emptyUid = '';
foreach (zinesh_load_users() as $u) {
    if (!is_array($u)) {
        continue;
    }
    $id = (string)($u['uid'] ?? '');
    if ($id === '' || isset($participantUids[$id])) {
        continue;
    }
    $emptyUid = $id;
    break;
}
if ($emptyUid === '') {
    $emptyUid = 'actor-trust-empty-probe-' . substr(bin2hex(random_bytes(4)), 0, 8);
    zinesh_json_atomic('users.json', function (array &$users) use ($emptyUid, $now): bool {
        $users[] = [
            'uid' => $emptyUid,
            'email' => 'actor-trust-empty-probe@zinesh.local',
            'createdAt' => date('c', $now),
            'probe' => true,
        ];
        return true;
    });
}
$emptyToken = zinesh_create_session($emptyUid);

$otherUid = 'actor-trust-probe-other-' . substr(bin2hex(random_bytes(4)), 0, 8);
$base = getenv('ZINESH_API_URL') ?: 'https://www.zinesh.com/api';
$urlOwn = $base . '/actor_trust.php?sessionToken=' . rawurlencode($token);
$urlOther = $base . '/actor_trust.php?sessionToken=' . rawurlencode($token)
    . '&actor_id=' . rawurlencode($otherUid);
$urlEmpty = $base . '/actor_trust.php?sessionToken=' . rawurlencode($emptyToken);

function http_get(string $url): array {
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
    }
    return ['code' => $code, 'body' => is_string($body) ? $body : ''];
}

$own = http_get($urlOwn);
$other = http_get($urlOther);
$empty = http_get($urlEmpty);
$ownJson = json_decode($own['body'], true);
$emptyJson = json_decode($empty['body'], true);

echo json_encode([
    'own_code' => $own['code'],
    'own_ok' => (bool)($ownJson['ok'] ?? false),
    'own_actor_id' => (string)($ownJson['actor_id'] ?? ''),
    'own_transactions' => (int)($ownJson['metrics']['transactions'] ?? -1),
    'other_code' => $other['code'],
    'empty_code' => $empty['code'],
    'empty_ok' => (bool)($emptyJson['ok'] ?? false),
    'empty_transactions' => (int)($emptyJson['metrics']['transactions'] ?? -1),
    'session_uid' => $uid,
    'empty_uid' => $emptyUid,
], JSON_UNESCAPED_UNICODE);
"""


def run(ssh: paramiko.SSHClient, cmd: str) -> tuple[int, str, str]:
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    return stdout.channel.recv_exit_status(), out, err


def backup_data(ssh: paramiko.SSHClient) -> str:
    stamp = datetime.now(timezone.utc).strftime("%Y%m%d_%H%M%S")
    backup_dir = f"{REMOTE_DATA}/backups/actor_trust_{stamp}"
    cmd = (
        f"mkdir -p {backup_dir} && "
        f"cp -a {REMOTE_DATA}/*.json {backup_dir}/ 2>/dev/null || true && "
        f"ls -1 {backup_dir} | wc -l"
    )
    code, out, err = run(ssh, cmd)
    if code != 0:
        raise RuntimeError(f"Data backup failed: {err or out}")
    count = out.strip()
    print(f"=== api/data backup ===")
    print(f"  {backup_dir} ({count} files)")
    return backup_dir


def check_dependencies(ssh: paramiko.SSHClient) -> list[str]:
    """Return read-only deps missing on server (to upload)."""
    missing = []
    for name in READ_ONLY_DEPS:
        code, out, _ = run(ssh, f"test -f {REMOTE_API}/{name} && echo ok")
        if "ok" not in out:
            missing.append(name)
    core_missing = []
    for name in ("escrow_room_lib.php", "wallet_lib.php", "tl_mode_lib.php"):
        code, out, _ = run(ssh, f"test -f {REMOTE_API}/{name} && echo ok")
        if "ok" not in out:
            core_missing.append(name)
    if core_missing:
        raise RuntimeError("Missing core API files on server: " + ", ".join(core_missing))
    print("=== dependency check ===")
    if missing:
        print(f"  will upload read-only deps: {', '.join(missing)}")
    else:
        print("  all read-only deps present")
    return missing


def upload_file(sftp: paramiko.SFTPClient, rel: str) -> str:
    local = API_DIR / rel.replace("/", os.sep)
    if not local.is_file():
        raise FileNotFoundError(f"Missing local file: {local}")
    remote = f"{REMOTE_API}/{rel}"
    parent = remote.rsplit("/", 1)[0]
    try:
        sftp.mkdir(parent)
    except OSError:
        pass
    print(f"  {rel} -> {remote}")
    sftp.put(str(local), remote)
    return remote


def upload_files(sftp: paramiko.SFTPClient, missing_deps: list[str]) -> list[str]:
    uploaded: list[str] = []
    if missing_deps:
        print("=== upload read-only dependencies ===")
        for rel in missing_deps:
            uploaded.append(upload_file(sftp, rel))
    print("=== upload actor trust files ===")
    for rel in DEPLOY_FILES:
        uploaded.append(upload_file(sftp, rel))
    return uploaded


def rollback_files(ssh: paramiko.SSHClient, remote_paths: list[str]) -> None:
    """Rollback yalnızca actor trust dosyaları — escrow çekirdeği ve deps kalır."""
    actor_only = [p for p in remote_paths if "actor_trust" in p]
    print("=== rollback actor trust files ===")
    for remote in actor_only:
        run(ssh, f"rm -f {remote}")
        print(f"  removed {remote}")


def run_remote_tests(ssh: paramiko.SSHClient) -> tuple[bool, str]:
    print("=== remote e2e tests ===")
    for script in ("e2e-actor-trust-test.php", "e2e-actor-trust-api-test.php"):
        code, out, err = run(ssh, f"php {REMOTE_API}/scripts/{script} 2>&1")
        text = (out or err).strip()
        print(text)
        if code != 0:
            return False, f"{script} failed"

    print("=== live smoke test ===")
    remote_probe = "/tmp/zinesh_actor_trust_smoke.php"
    sftp = ssh.open_sftp()
    with sftp.file(remote_probe, "w") as f:
        f.write(SMOKE_PHP)
    sftp.close()

    code, out, err = run(
        ssh,
        f"ZINESH_API_URL={API_BASE} php {remote_probe}; rm -f {remote_probe}",
    )
    text = (out or err).strip()
    print(text)
    run(ssh, f"rm -f {remote_probe}")
    if code != 0:
        return False, "smoke probe php failed"

    try:
        result = json.loads(text)
    except json.JSONDecodeError:
        return False, "smoke probe returned invalid JSON"

    checks = [
        ("own HTTP 200", result.get("own_code") == 200),
        ("own ok:true", result.get("own_ok") is True),
        ("other HTTP 403", result.get("other_code") == 403),
        ("empty user HTTP 200", result.get("empty_code") == 200),
        ("empty user ok:true", result.get("empty_ok") is True),
        ("empty user transactions 0", result.get("empty_transactions") == 0),
    ]
    failed = [label for label, ok in checks if not ok]
    for label, ok in checks:
        print(f"  {'PASS' if ok else 'FAIL'}: {label}")
    if failed:
        return False, "smoke checks failed: " + ", ".join(failed)
    return True, "all checks passed"


def main() -> int:
    require_intelligence_regression_pass()

    missing = [rel for rel in DEPLOY_FILES if not (API_DIR / rel).is_file()]
    if missing:
        print("Missing local files:", ", ".join(missing))
        return 1

    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    print(f"Connecting {USER}@{DEPLOY_HOST} ...")
    ssh.connect(DEPLOY_HOST, username=USER, password=PASSWORD, timeout=25)

    uploaded: list[str] = []
    try:
        code, out, _ = run(ssh, f"test -d {REMOTE_API} && echo ok")
        if "ok" not in out:
            print(f"Remote API dir not found: {REMOTE_API}")
            return 1

        backup_data(ssh)
        missing_deps = check_dependencies(ssh)

        sftp = ssh.open_sftp()
        uploaded = upload_files(sftp, missing_deps)
        sftp.close()

        print("=== syntax check ===")
        syntax_files = missing_deps + DEPLOY_FILES
        for rel in syntax_files:
            if not rel.endswith(".php"):
                continue
            remote = f"{REMOTE_API}/{rel}"
            code, out, err = run(ssh, f"php -l {remote}")
            line = (out or err).strip()
            print(f"  {rel}: {line}")
            if "No syntax errors" not in line:
                raise RuntimeError(f"Syntax error in {rel}")

        ok, message = run_remote_tests(ssh)
        if not ok:
            raise RuntimeError(message)

        print(f"\nActor Trust deploy OK — {message}")
        return 0
    except Exception as exc:
        print(f"\nDEPLOY FAILED: {exc}")
        if uploaded:
            rollback_files(ssh, uploaded)
            print("Rollback complete — escrow core untouched")
        return 1
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
