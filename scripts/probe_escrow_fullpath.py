#!/usr/bin/env python3
"""Full wallet.php escrow_create simulation with proper session map format."""
from __future__ import annotations

import json
import os
import sys

import paramiko

HOST = os.environ.get("ZINESH_DEPLOY_HOST", "193.164.6.95")
USER = os.environ.get("ZINESH_DEPLOY_USER", "root")
PASS = os.environ.get("ZINESH_DEPLOY_PASS", "")

PHP = r"""<?php
declare(strict_types=1);
$api = '/www/wwwroot/zinesh.com/api';
chdir($api);

// Capture json_response instead of exiting
function zinesh_json_response($data, $code = 200) {
    echo "HTTP_CODE=$code\n";
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    throw new RuntimeException('__JSON_EXIT__');
}

require_once $api . '/wallet_lib.php';
require_once $api . '/campaign_lib.php';
require_once $api . '/founder_lib.php';
require_once $api . '/founder_profile_lib.php';
require_once $api . '/tl_mode_lib.php';
require_once $api . '/escrow_jobs_lib.php';

$uid = '1b9b932370787e42f66cddc693a1c14a';
$user = zinesh_find_user_by_uid($uid);
if (!$user) { echo "no user\n"; exit(1); }

$token = bin2hex(random_bytes(24));
$now = time();
zinesh_json_atomic('sessions.json', function (array &$sessions) use ($token, $uid, $now) {
    $sessions[$token] = [
        'uid' => $uid,
        'expires' => $now + 3600,
        'createdAt' => gmdate('c'),
        'lastActivity' => $now,
        'probe' => true,
    ];
    return true;
});

$input = [
    'action' => 'escrow_create',
    'sessionToken' => $token,
    'uid' => $uid,
    'email' => (string)($user['email'] ?? ''),
    'amount' => 1,
    'title' => 'Full path probe',
    'description' => 'sim',
    'supplierEmail' => '',
    'supplierName' => '',
    'deliveryDate' => '',
    'category' => 'Emanet',
    'matchOnly' => true,
];

// Inline the escrow_create block from wallet.php
try {
    $user = zinesh_require_auth($input);
    $amount = (float)($input['amount'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $supplierEmail = strtolower(trim((string)($input['supplierEmail'] ?? '')));
    $supplierName = trim((string)($input['supplierName'] ?? ''));
    $deliveryDate = trim((string)($input['deliveryDate'] ?? ''));
    $category = trim((string)($input['category'] ?? 'Emanet'));
    $matchOnly = $supplierEmail === '' || !empty($input['matchOnly']);

    if ($amount <= 0) {
        zinesh_json_response(['message' => 'Geçersiz tutar.'], 400);
    }

    $uid = (string)$user['uid'];
    $useTl = zinesh_escrow_uses_tl();
    zinesh_ensure_wallet_fields($user);
    $available = round((float)$user['usdtBalance'] - (float)$user['escrowBalance'], 2);
    echo "available=$available amount=$amount matchOnly=" . ($matchOnly ? '1' : '0') . "\n";
    if ($available + 1e-9 < $amount) {
        zinesh_json_response(['message' => 'Yetersiz TL bakiyesi. Önce site cüzdanına para yatırın.'], 400);
    }

    $jobResult = zinesh_escrow_job_create(
        $uid, $amount, $title, $description, $supplierEmail, $supplierName, $deliveryDate, $category, $matchOnly
    );
    echo 'job_ok=' . (!empty($jobResult['ok']) ? '1' : '0') . ' msg=' . ($jobResult['message'] ?? '') . "\n";
    if (!$jobResult['ok']) {
        zinesh_json_response(['message' => $jobResult['message'] ?? 'Emanet kaydı oluşturulamadı.'], 400);
    }
    $user = zinesh_find_user_by_uid($uid) ?? $user;
    $job = $jobResult['job'];
    $payload = [
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'job' => zinesh_escrow_job_public_row($job, $uid),
        'matchCode' => (string)($job['matchCode'] ?? ''),
        'message' => 'Emanet kodu hazır.',
    ];
    // cleanup job
    $jid = $job['id'];
    zinesh_json_atomic('escrow_jobs.json', function (array &$jobs) use ($jid) {
        $jobs = array_values(array_filter($jobs, static fn($j) => ($j['id'] ?? '') !== $jid));
        return true;
    });
    echo 'RESPONSE_KEYS=' . implode(',', array_keys($payload)) . "\n";
    echo 'has_wallet=' . (isset($payload['wallet']) ? '1' : '0') . "\n";
    echo 'has_job=' . (isset($payload['job']) ? '1' : '0') . "\n";
    echo 'job_public=' . json_encode($payload['job'], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    if ($e->getMessage() !== '__JSON_EXIT__') {
        echo 'EXCEPTION: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    }
}

// cleanup session
zinesh_json_atomic('sessions.json', function (array &$sessions) use ($token) {
    unset($sessions[$token]);
    return true;
});
echo "done\n";
"""


def main() -> int:
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, username=USER, password=PASS, timeout=25)
    try:
        sftp = ssh.open_sftp()
        with sftp.file("/tmp/z_full_escrow.php", "w") as f:
            f.write(PHP)
        sftp.close()
        _, stdout, stderr = ssh.exec_command("php /tmp/z_full_escrow.php; rm -f /tmp/z_full_escrow.php")
        print(stdout.read().decode("utf-8", errors="replace"))
        err = stderr.read().decode("utf-8", errors="replace")
        if err.strip():
            print("ERR:", err)
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
