#!/usr/bin/env python3
"""Remove E2E / test escrow rooms, messages, and stale open escrow jobs from live data."""
import json
import os
import sys
import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
$api = '/www/wwwroot/zinesh.com/api';
require_once $api . '/_bootstrap.php';
require_once $api . '/wallet_lib.php';

$roomsFile = 'escrow_rooms.json';
$msgsFile = 'escrow_room_messages.json';
$jobsFile = 'escrow_jobs.json';

$rooms = zinesh_json_read($roomsFile);
$msgs = zinesh_json_read($msgsFile);
$jobs = zinesh_json_read($jobsFile);

$roomCountBefore = count($rooms);
$msgCountBefore = count($msgs);
$jobCountBefore = count($jobs);

// Remove all escrow rooms + messages (fresh start for production testing tomorrow)
zinesh_json_write($roomsFile, []);
zinesh_json_write($msgsFile, []);

// Cancel open legacy escrow jobs (pending_match / active / completion_pending)
$openStatuses = ['pending_match', 'active', 'completion_pending'];
$jobsKept = [];
$jobsCancelled = 0;
$refunded = 0;

foreach ($jobs as $job) {
    if (!is_array($job)) continue;
    $status = (string)($job['status'] ?? '');
    if (!in_array($status, $openStatuses, true)) {
        $jobsKept[] = $job;
        continue;
    }
    $amount = (float)($job['value'] ?? 0);
    $buyerUid = (string)($job['buyerUid'] ?? '');
    if ($buyerUid !== '' && $amount > 0 && in_array($status, ['active', 'completion_pending'], true)) {
        try {
            zinesh_update_user($buyerUid, static function (array &$u) use ($amount, &$refunded) {
                zinesh_ensure_wallet_fields($u);
                if ((float)$u['escrowBalance'] + 1e-9 >= $amount) {
                    $u['escrowBalance'] = round((float)$u['escrowBalance'] - $amount, 2);
                    $refunded++;
                }
            });
        } catch (Throwable $e) {
            // continue
        }
    }
    $job['status'] = 'cancelled';
    $job['completedAt'] = date('c');
    $job['cancelReason'] = 'admin_cleanup_2026-07-08';
    $jobsKept[] = $job;
    $jobsCancelled++;
}

zinesh_json_write($jobsFile, array_values($jobsKept));

echo json_encode([
    'rooms_removed' => $roomCountBefore,
    'messages_removed' => $msgCountBefore,
    'open_jobs_cancelled' => $jobsCancelled,
    'escrow_refunds' => $refunded,
    'jobs_total_after' => count($jobsKept),
], JSON_UNESCAPED_UNICODE) . PHP_EOL;
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/z_cleanup_escrow.php", "w") as f:
        f.write(PHP)
    sftp.close()
    _, stdout, stderr = ssh.exec_command("php /tmp/z_cleanup_escrow.php 2>&1; rm -f /tmp/z_cleanup_escrow.php")
    out = stdout.read().decode("utf-8", errors="replace").strip()
    err = stderr.read().decode("utf-8", errors="replace").strip()
    ssh.close()
    if err:
        print("ERR:", err)
    for line in out.splitlines():
        if line.startswith("{"):
            data = json.loads(line)
            print("Temizlik tamam:")
            print(f"  - Görüşme odası silindi: {data.get('rooms_removed', 0)}")
            print(f"  - Mesaj silindi: {data.get('messages_removed', 0)}")
            print(f"  - Açık kodlu emanet iptal: {data.get('open_jobs_cancelled', 0)}")
            print(f"  - Kilit iadesi yapılan: {data.get('escrow_refunds', 0)}")
            return 0
    print(out or "Beklenmeyen çıktı")
    return 1


if __name__ == "__main__":
    sys.exit(main())
