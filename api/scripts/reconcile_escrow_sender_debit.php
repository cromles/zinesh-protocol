<?php
declare(strict_types=1);

/**
 * Tamamlanmış emanetlerde gönderen usdt düşülmemişse düzeltir.
 *
 * Kullanım (sunucuda):
 *   php api/scripts/reconcile_escrow_sender_debit.php
 *   php api/scripts/reconcile_escrow_sender_debit.php --ticket=ZN-SH-DUAL-27902 --from=11:00 --to=12:00
 *   php api/scripts/reconcile_escrow_sender_debit.php --dry-run
 */

require_once dirname(__DIR__) . '/wallet_lib.php';
require_once dirname(__DIR__) . '/escrow_room_lib.php';

$opts = getopt('', ['ticket:', 'from:', 'to:', 'date:', 'dry-run', 'amount:']);
$dryRun = array_key_exists('dry-run', $opts);
$ticketFilter = isset($opts['ticket']) ? zinesh_normalize_member_ticket((string)$opts['ticket']) : '';
$amountFilter = isset($opts['amount']) ? round((float)$opts['amount'], 2) : null;
$dateStr = (string)($opts['date'] ?? date('Y-m-d'));
$fromStr = (string)($opts['from'] ?? '00:00');
$toStr = (string)($opts['to'] ?? '23:59');
$tz = new DateTimeZone('Europe/Istanbul');

$windowStartDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $fromStr . ':00', $tz);
$windowEndDt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $toStr . ':59', $tz);
if ($windowStartDt === false || $windowEndDt === false) {
    fwrite(STDERR, "Geçersiz tarih/saat penceresi.\n");
    exit(2);
}
$windowStart = $windowStartDt->getTimestamp();
$windowEnd = $windowEndDt->getTimestamp();

function reconcile_room_in_window(array $room, int $windowStart, int $windowEnd): bool
{
    $completedAt = (string)($room['completedAt'] ?? '');
    if ($completedAt === '') {
        return false;
    }
    try {
        $dt = new DateTimeImmutable($completedAt);
        $ts = $dt->getTimestamp();
    } catch (Exception $e) {
        return false;
    }
    return $ts >= $windowStart && $ts <= $windowEnd;
}

$fixed = 0;
$skipped = 0;
$rooms = zinesh_escrow_rooms_load();

foreach ($rooms as $room) {
    if ((string)($room['status'] ?? '') !== 'completed') {
        continue;
    }
    if (!reconcile_room_in_window($room, $windowStart, $windowEnd)) {
        continue;
    }

    $roomId = (string)($room['id'] ?? '');
    $employerUid = (string)($room['employerUid'] ?? '');
    $amount = round((float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2);
    if ($roomId === '' || $employerUid === '' || $amount <= 0) {
        continue;
    }

    $employer = zinesh_find_user_by_uid($employerUid);
    if (!$employer) {
        continue;
    }

    $employerTicket = zinesh_normalize_member_ticket((string)($employer['ticketNumber'] ?? ''));
    if ($ticketFilter !== '' && $employerTicket !== $ticketFilter) {
        continue;
    }
    if ($amountFilter !== null && abs($amount - $amountFilter) > 0.01) {
        continue;
    }

    if (!empty($room['senderDebitReconciled'])) {
        $skipped++;
        echo "SKIP $roomId — zaten düzeltilmiş\n";
        continue;
    }

    // Tamamlanmış odada gönderen escrow kiliti kalkmış olmalı; usdt hâlâ tam ise eksik düşüm var.
    zinesh_ensure_wallet_fields($employer);
    $escrow = round((float)$employer['escrowBalance'], 2);
    $usdt = round((float)$employer['usdtBalance'], 2);
    $payout = round((float)($room['payoutTry'] ?? 0), 2);

    if ($payout <= 0) {
        $skipped++;
        echo "SKIP $roomId — payoutTry yok\n";
        continue;
    }

    $explicitTarget = $ticketFilter !== '' && $amountFilter !== null;
    if ($explicitTarget) {
        $needsDebit = $usdt + 1e-9 >= $amount || $escrow > 0.01;
    } else {
        $needsDebit = ($usdt + 1e-9 >= $amount && $escrow + 1e-9 < $amount)
            || ($escrow > 0.01 && $payout > 0);
    }

    if (!$needsDebit) {
        $skipped++;
        echo "SKIP $roomId — düzeltme gerekmiyor (usdt=$usdt escrow=$escrow)\n";
        continue;
    }

    echo ($dryRun ? 'DRY ' : '') . "FIX $roomId ticket=$employerTicket debit={$amount}TL usdt=$usdt->"
        . round(max(0, $usdt - $amount), 2) . "\n";

    if ($dryRun) {
        $fixed++;
        continue;
    }

    zinesh_update_user($employerUid, static function (array &$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = round(max(0, (float)$u['usdtBalance'] - $amount), 2);
        $lock = round((float)$u['escrowBalance'], 2);
        if ($lock + 1e-9 >= $amount) {
            $u['escrowBalance'] = round(max(0, $lock - $amount), 2);
        }
    });

    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            $rows[$i]['senderDebitReconciled'] = true;
            $rows[$i]['senderDebitReconciledAt'] = date('c');
            return true;
        }
        return false;
    });

    zinesh_append_tx_log($employerUid, [
        'id' => 'rec-' . substr(hash('sha256', $roomId . $employerUid), 0, 12),
        'type' => 'escrow_reconcile_debit',
        'amount' => number_format($amount, 2, '.', ''),
        'asset' => 'TL',
        'txHash' => $roomId,
        'status' => 'completed',
        'label' => 'Emanet düzeltmesi',
    ]);

    zinesh_audit('escrow_sender_debit_reconcile', [
        'roomId' => $roomId,
        'uid' => $employerUid,
        'amount' => $amount,
    ]);

    $fixed++;
}

echo "=== reconcile_escrow_sender_debit ===\n";
echo "fixed=$fixed skipped=$skipped dryRun=" . ($dryRun ? '1' : '0') . "\n";

$audit = zinesh_wallet_invariant_audit();
echo 'invariant_issues=' . count($audit['issues']) . "\n";
foreach ($audit['issues'] as $issue) {
    echo "  - $issue\n";
}

exit($fixed > 0 || $skipped > 0 ? 0 : 1);
