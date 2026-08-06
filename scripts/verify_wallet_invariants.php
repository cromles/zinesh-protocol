<?php
declare(strict_types=1);

/**
 * Cüzdan muhasebe tutarlılık denetimi — yatırımcı gösterimi öncesi çalıştırın.
 * php scripts/verify_wallet_invariants.php
 */

require_once __DIR__ . '/../api/wallet_lib.php';
require_once __DIR__ . '/../api/escrow_room_lib.php';
require_once __DIR__ . '/../api/escrow_jobs_lib.php';

$audit = zinesh_wallet_invariant_audit();
$issues = $audit['issues'];

$rooms = zinesh_escrow_rooms_load();
foreach ($rooms as $room) {
    $status = (string)($room['status'] ?? '');
    if (!in_array($status, ['completed'], true)) {
        continue;
    }
    $employerUid = (string)($room['employerUid'] ?? '');
    $amount = round((float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2);
    $payout = round((float)($room['payoutTry'] ?? 0), 2);
    if ($employerUid === '' || $amount <= 0) {
        continue;
    }
    if ($payout <= 0 && $amount > 0) {
        $issues[] = sprintf('room %s completed but payoutTry missing', (string)($room['id'] ?? ''));
    }
}

$jobs = zinesh_json_read('escrow_jobs.json');
if (is_array($jobs)) {
    foreach ($jobs as $job) {
        if (!is_array($job) || (string)($job['status'] ?? '') !== 'success') {
            continue;
        }
        $payout = (float)($job['payout'] ?? 0);
        $value = (float)($job['value'] ?? 0);
        if ($value > 0 && $payout <= 0) {
            $issues[] = sprintf('job %s success but payout missing', (string)($job['id'] ?? ''));
        }
    }
}

echo "=== Zinesh wallet invariant audit ===\n";
echo 'Total usdtBalance: ' . $audit['totalUsdt'] . "\n";
echo 'Total escrowBalance: ' . $audit['totalEscrow'] . "\n";
echo 'Issues: ' . count($issues) . "\n";
foreach ($issues as $issue) {
    echo "  - $issue\n";
}
exit($issues === [] ? 0 : 1);
