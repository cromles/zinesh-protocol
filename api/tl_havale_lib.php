<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/founder_profile_lib.php';

const ZINESH_HAVALE_PENDING_FILE = 'havale_pending.json';

/** @return array<string,mixed> */
function zinesh_havale_config(): array
{
    $cfg = zinesh_config();
    $h = $cfg['tl_havale'] ?? [];
    return is_array($h) ? $h : [];
}

function zinesh_havale_iban_normalized(): string
{
    $raw = trim((string)(zinesh_havale_config()['iban'] ?? ''));
    return strtoupper(preg_replace('/\s+/', '', $raw) ?? '');
}

function zinesh_havale_enabled(): bool
{
    if (!zinesh_tl_mode_enabled()) {
        return false;
    }
    if (zinesh_havale_config()['enabled'] === false) {
        return false;
    }
    return strlen(zinesh_havale_iban_normalized()) >= 15;
}

function zinesh_havale_min_try(): float
{
    return max(10.0, (float)(zinesh_havale_config()['min_deposit_try'] ?? 50.0));
}

function zinesh_havale_user_reference(array $user): string
{
    $ticket = trim((string)($user['ticketNumber'] ?? ''));
    if ($ticket !== '') {
        return $ticket;
    }
    $uid = (string)($user['uid'] ?? '');
    if ($uid !== '') {
        return 'ZNS-' . strtoupper(substr(hash('sha256', $uid), 0, 8));
    }
    return 'ZNS-REF';
}

/** @return array<string,mixed> */
function zinesh_havale_public_info(array $user): array
{
    $cfg = zinesh_havale_config();
    $iban = trim((string)($cfg['iban'] ?? ''));
    return [
        'enabled' => zinesh_havale_enabled(),
        'iban' => $iban,
        'accountHolder' => trim((string)($cfg['account_holder'] ?? '')),
        'bankName' => trim((string)($cfg['bank_name'] ?? '')),
        'minDepositTry' => zinesh_havale_min_try(),
        'reference' => zinesh_havale_user_reference($user),
        'instructions' => 'Havale açıklamasına referans kodunu yazın. Onay sonrası bakiye site cüzdanına geçer.',
    ];
}

/** @return list<array<string,mixed>> */
function zinesh_havale_load_pending(): array
{
    $rows = zinesh_json_read(ZINESH_HAVALE_PENDING_FILE, []);
    return is_array($rows) ? $rows : [];
}

/** @param list<array<string,mixed>> $rows */
function zinesh_havale_save_pending(array $rows): void
{
    zinesh_json_write(ZINESH_HAVALE_PENDING_FILE, array_values($rows));
}

/**
 * @return array{ok:bool,message?:string,pending?:array,wallet?:array,user?:array,autoApproved?:bool}
 */
function zinesh_havale_report_deposit(array $user, float $amountTry, string $note = ''): array
{
    if (!zinesh_havale_enabled()) {
        return ['ok' => false, 'message' => 'Havale yatırma henüz yapılandırılmadı.'];
    }
    if ($amountTry < zinesh_havale_min_try()) {
        return [
            'ok' => false,
            'message' => sprintf('Minimum havale tutarı %s TL.', number_format(zinesh_havale_min_try(), 0, ',', '.')),
        ];
    }

    $uid = (string)($user['uid'] ?? '');
    foreach (zinesh_havale_load_pending() as $pending) {
        if (($pending['uid'] ?? '') === $uid && ($pending['status'] ?? 'pending') === 'pending') {
            return [
                'ok' => false,
                'message' => 'Zaten bekleyen bir havale bildirimin var. Onaylanmasını bekle veya destek ile iletişime geç.',
            ];
        }
    }

    $reference = zinesh_havale_user_reference($user);
    $note = trim($note);
    $id = 'hav-' . substr(hash('sha256', $uid . microtime(true) . random_bytes(8)), 0, 12);

    $row = [
        'id' => $id,
        'uid' => $uid,
        'email' => (string)($user['email'] ?? ''),
        'name' => (string)($user['name'] ?? ''),
        'amountTry' => round($amountTry, 2),
        'reference' => $reference,
        'note' => mb_substr($note, 0, 120),
        'status' => 'pending',
        'createdAt' => gmdate('c'),
    ];

    $autoApprove = !empty(zinesh_havale_config()['auto_approve_founder']) && zinesh_is_founder($user);
    if ($autoApprove) {
        return zinesh_havale_credit_deposit($row, 'auto_founder');
    }

    zinesh_json_atomic(ZINESH_HAVALE_PENDING_FILE, function (array &$rows) use ($row) {
        $rows[] = $row;
        return true;
    });

    return [
        'ok' => true,
        'message' => 'Havale bildirimin alındı. Onaylandığında bakiyene yansır (genelde aynı iş günü).',
        'pending' => $row,
        'autoApproved' => false,
    ];
}

/**
 * @param array<string,mixed> $row
 * @return array{ok:bool,message?:string,wallet?:array,user?:array}
 */
function zinesh_havale_credit_deposit(array $row, string $approvedBy = 'admin'): array
{
    $uid = (string)($row['uid'] ?? '');
    $amount = round((float)($row['amountTry'] ?? 0), 2);
    $id = (string)($row['id'] ?? '');
    if ($uid === '' || $amount <= 0 || $id === '') {
        return ['ok' => false, 'message' => 'Geçersiz havale kaydı.'];
    }

    if (!zinesh_havale_mark_id_processed($id)) {
        return ['ok' => false, 'message' => 'Bu havale zaten işlendi.'];
    }

    if ($approvedBy !== 'auto_founder') {
        zinesh_json_atomic(ZINESH_HAVALE_PENDING_FILE, function (array &$rows) use ($id, $approvedBy) {
            foreach ($rows as $i => $r) {
                if (($r['id'] ?? '') === $id && ($r['status'] ?? '') === 'pending') {
                    $rows[$i]['status'] = 'approved';
                    $rows[$i]['approvedAt'] = gmdate('c');
                    $rows[$i]['approvedBy'] = $approvedBy;
                    return true;
                }
            }
            return true;
        });
    } else {
        $row['status'] = 'approved';
        $row['approvedAt'] = gmdate('c');
        $row['approvedBy'] = $approvedBy;
        zinesh_json_atomic(ZINESH_HAVALE_PENDING_FILE, function (array &$rows) use ($row) {
            $rows[] = $row;
            return true;
        });
    }

    $user = zinesh_update_user($uid, function (&$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 2);
    });

    zinesh_append_tx_log($uid, [
        'id' => $id,
        'type' => 'deposit',
        'network' => 'havale',
        'amount' => number_format($amount, 2, '.', ''),
        'asset' => 'TRY',
        'txHash' => (string)($row['reference'] ?? ''),
        'date' => date('d.m.Y H:i'),
        'status' => 'completed',
    ]);

    zinesh_audit('havale_deposit', [
        'uid' => $uid,
        'amount' => $amount,
        'approvedBy' => $approvedBy,
        'id' => $id,
    ]);

    $depositTask = zinesh_campaign_record_deposit($uid, $amount);

    return [
        'ok' => true,
        'message' => sprintf('%s TL site cüzdanına eklendi.', number_format($amount, 2, ',', '.')),
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'campaignTask' => $depositTask,
        'autoApproved' => $approvedBy === 'auto_founder',
    ];
}

/** @return array{ok:bool,message?:string,wallet?:array,user?:array} */
function zinesh_havale_approve_pending(string $pendingId, array $approver): array
{
    if (!zinesh_is_founder($approver)) {
        return ['ok' => false, 'message' => 'Yetkisiz.'];
    }

    $found = null;
    zinesh_json_atomic(ZINESH_HAVALE_PENDING_FILE, function (array &$rows) use ($pendingId, &$found) {
        foreach ($rows as $r) {
            if (($r['id'] ?? '') === $pendingId && ($r['status'] ?? '') === 'pending') {
                $found = $r;
                break;
            }
        }
        return true;
    });

    if (!is_array($found)) {
        return ['ok' => false, 'message' => 'Bekleyen havale bulunamadı.'];
    }

    return zinesh_havale_credit_deposit($found, 'founder:' . (string)($approver['uid'] ?? ''));
}

/** @return list<array<string,mixed>> */
function zinesh_havale_list_pending(): array
{
    return array_values(array_filter(
        zinesh_havale_load_pending(),
        fn($r) => ($r['status'] ?? '') === 'pending'
    ));
}

function zinesh_havale_mark_id_processed(string $id): bool
{
    return (bool) zinesh_json_atomic('havale_processed.json', function (array &$idx) use ($id) {
        if (isset($idx[$id])) {
            return false;
        }
        $idx[$id] = gmdate('c');
        return true;
    });
}
