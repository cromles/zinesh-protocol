<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/escrow_jobs_lib.php';
require_once __DIR__ . '/eslesme_sinyal_lib.php';
require_once __DIR__ . '/escrow_memory_lib.php';

const ZINESH_ESCROW_ROOMS_FILE = 'escrow_rooms.json';
const ZINESH_ESCROW_ROOM_MSG_FILE = 'escrow_room_messages.json';

const ZINESH_ESCROW_COMMISSION_RATE = 0.05;
const ZINESH_ESCROW_DISPUTE_FEE_RATE = 0.01;
const ZINESH_ESCROW_COLLATERAL_RATE = 0.20;
const ZINESH_ESCROW_TRUST_PENALTY = 5.0;
/** locking / settling geçici durumları — süre aşımında otomatik kurtarma */
const ZINESH_ESCROW_TRANSIENT_TTL_SEC = 600;

function zinesh_escrow_room_transient_expired(?string $isoAt): bool
{
    if ($isoAt === null || trim($isoAt) === '') {
        return true;
    }
    $ts = strtotime($isoAt);
    if ($ts === false || $ts <= 0) {
        return true;
    }
    return (time() - $ts) >= ZINESH_ESCROW_TRANSIENT_TTL_SEC;
}

/** @param array<string,mixed> $room */
function zinesh_escrow_room_release_locking_hold(array $room): void
{
    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    $amount = round((float)($room['agreedAmountTry'] ?? 0), 2);
    $collateralAmount = round((float)($room['lockingCollateralTry'] ?? 0), 2);

    if (!empty($room['lockingEmployerFunded']) && $employerUid !== '' && $amount > 0) {
        zinesh_update_user($employerUid, static function (array &$u) use ($amount) {
            zinesh_ensure_wallet_fields($u);
            $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $amount), 2);
        });
    }
    if (!empty($room['lockingWorkerCollateralFunded']) && $workerUid !== '' && $collateralAmount > 0) {
        zinesh_update_user($workerUid, static function (array &$u) use ($collateralAmount) {
            zinesh_ensure_wallet_fields($u);
            $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $collateralAmount), 2);
        });
    }
}

/**
 * Settlement wallet commit kanıtı: claim anındaki snapshot ile users.json karşılaştırılır.
 *
 * @param array<string,mixed> $room
 */
function zinesh_escrow_room_settlement_wallet_committed(array $room): bool
{
    $employerUid = (string)($room['employerUid'] ?? '');
    if ($employerUid === '') {
        return false;
    }
    if (!array_key_exists('settlingSnapshotEmployerEscrow', $room)
        || !array_key_exists('settlingSnapshotEmployerUsdt', $room)) {
        return false;
    }
    $lockTry = round((float)($room['settlingSnapshotLockTry'] ?? $room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2);
    if ($lockTry < 1) {
        return false;
    }
    $escrowBefore = round((float)$room['settlingSnapshotEmployerEscrow'], 2);
    $usdtBefore = round((float)$room['settlingSnapshotEmployerUsdt'], 2);

    $employer = zinesh_find_user_by_uid($employerUid);
    if ($employer === null) {
        return false;
    }
    zinesh_ensure_wallet_fields($employer);
    $escrowDelta = round($escrowBefore - (float)$employer['escrowBalance'], 2);
    $usdtDelta = round($usdtBefore - (float)$employer['usdtBalance'], 2);

    return $escrowDelta + 1e-9 >= $lockTry && $usdtDelta + 1e-9 >= $lockTry;
}

/**
 * Süresi dolmuş locking/settling odalarını kurtarır.
 *
 * @return array{recovered:bool,action?:string,roomId?:string}
 */
function zinesh_escrow_room_recover_stale_transient_if_needed(string $roomId): array
{
    $snapshot = null;
    $action = null;

    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, &$snapshot, &$action) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            $status = (string)($row['status'] ?? '');

            if ($status === 'locking') {
                $abandoned = empty($row['lockingEmployerFunded']) && empty($row['lockingWorkerCollateralFunded']);
                if (
                    !$abandoned
                    && !zinesh_escrow_room_transient_expired((string)($row['lockingAt'] ?? ''))
                ) {
                    return false;
                }
                $snapshot = $row;
                $action = 'locking_revert';
                $rows[$i]['status'] = 'terms_pending';
                unset(
                    $rows[$i]['lockingAt'],
                    $rows[$i]['lockingEmployerFunded'],
                    $rows[$i]['lockingWorkerCollateralFunded'],
                    $rows[$i]['lockingCollateralTry']
                );
                return true;
            }

            if ($status === 'settling') {
                if (!zinesh_escrow_room_transient_expired((string)($row['settlingAt'] ?? ''))) {
                    return false;
                }
                $snapshot = $row;
                $walletCommitted = !empty($row['settlingWalletApplied'])
                    || zinesh_escrow_room_settlement_wallet_committed($row);
                if ($walletCommitted) {
                    $action = 'settling_complete';
                    $amount = round((float)($row['employerLockedTry'] ?? $row['agreedAmountTry'] ?? 0), 2);
                    $commission = round($amount * ZINESH_ESCROW_COMMISSION_RATE, 2);
                    $payout = round($amount - $commission, 2);
                    $rows[$i]['status'] = 'completed';
                    $rows[$i]['completedAt'] = date('c');
                    $rows[$i]['commissionTry'] = $commission;
                    $rows[$i]['payoutTry'] = $payout;
                    $rows[$i]['employerUsdtDebited'] = true;
                    unset(
                        $rows[$i]['settlingAt'],
                        $rows[$i]['settlingPreviousStatus'],
                        $rows[$i]['settlingWalletApplied'],
                        $rows[$i]['settlingSnapshotEmployerEscrow'],
                        $rows[$i]['settlingSnapshotEmployerUsdt'],
                        $rows[$i]['settlingSnapshotLockTry']
                    );
                } else {
                    $action = 'settling_revert';
                    $fallback = (string)($row['settlingPreviousStatus'] ?? '');
                    if (!in_array($fallback, ['locked', 'completion_pending'], true)) {
                        $fallback = !empty($row['employerConfirmedComplete']) && !empty($row['workerConfirmedComplete'])
                            ? 'completion_pending'
                            : 'locked';
                    }
                    $rows[$i]['status'] = $fallback;
                    unset(
                        $rows[$i]['settlingAt'],
                        $rows[$i]['settlingPreviousStatus'],
                        $rows[$i]['settlingWalletApplied'],
                        $rows[$i]['settlingSnapshotEmployerEscrow'],
                        $rows[$i]['settlingSnapshotEmployerUsdt'],
                        $rows[$i]['settlingSnapshotLockTry']
                    );
                }
                return true;
            }

            return false;
        }
        return false;
    });

    if ($snapshot === null || $action === null) {
        return ['recovered' => false];
    }

    if ($action === 'locking_revert') {
        zinesh_escrow_room_release_locking_hold($snapshot);
        zinesh_escrow_room_add_message(
            $roomId,
            'system',
            'Sistem',
            'Kilit işlemi zaman aşımına uğradı; anlaşma tekrar onay bekliyor. Gerekirse iş alan tekrar onaylayabilir.',
            'system'
        );
        zinesh_audit('escrow_room_locking_recovered', ['roomId' => $roomId]);
        zinesh_escrow_memory_on_recovered($roomId, 'locking_revert');
    } elseif ($action === 'settling_revert') {
        zinesh_escrow_room_add_message(
            $roomId,
            'system',
            'Sistem',
            'Ödeme işlemi zaman aşımına uğradı; iş kaldığı yerden devam edebilir. Tamamlama onayını tekrar deneyin.',
            'system'
        );
        zinesh_audit('escrow_room_settling_recovered', ['roomId' => $roomId, 'mode' => 'revert']);
        zinesh_escrow_memory_on_recovered($roomId, 'settling_revert');
    } else {
        zinesh_audit('escrow_room_settling_recovered', ['roomId' => $roomId, 'mode' => 'complete']);
        zinesh_escrow_memory_on_recovered($roomId, 'settling_complete');
    }

    return ['recovered' => true, 'action' => $action, 'roomId' => $roomId];
}

function zinesh_escrow_room_recover_all_stale_transients(): void
{
    foreach (zinesh_escrow_rooms_load() as $room) {
        $status = (string)($room['status'] ?? '');
        if (!in_array($status, ['locking', 'settling'], true)) {
            continue;
        }
        $roomId = (string)($room['id'] ?? '');
        if ($roomId !== '') {
            zinesh_escrow_room_recover_stale_transient_if_needed($roomId);
        }
    }
}

/** @return array{ok:bool,message?:string,code?:string,email_verified?:bool,phone_verified?:bool,kyc_verified?:bool,missing?:list<string>,httpStatus?:int} */
function zinesh_escrow_contract_verification_gate(array $user): array
{
    require_once __DIR__ . '/verification_lib.php';
    $verification = zinesh_contract_verification_status($user);
    if ($verification['ok']) {
        return ['ok' => true];
    }
    $labels = [
        'email' => 'e-posta',
        'phone' => 'telefon',
        'kyc' => 'kimlik (KYC)',
    ];
    $parts = array_map(static fn(string $key) => $labels[$key] ?? $key, $verification['missing']);
    return [
        'ok' => false,
        'message' => 'İşlem için '
            . implode(', ', $parts)
            . ' doğrulamasını tamamlamanız gerekir.',
        'code' => 'verification_required',
        'email_verified' => $verification['email_verified'],
        'phone_verified' => $verification['phone_verified'],
        'kyc_verified' => $verification['kyc_verified'],
        'missing' => $verification['missing'],
        'httpStatus' => 403,
    ];
}

function zinesh_escrow_room_patch_fields(string $roomId, array $fields, ?string $requiredStatus = null): bool
{
    $ok = false;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $fields, $requiredStatus, &$ok) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if ($requiredStatus !== null && (string)($row['status'] ?? '') !== $requiredStatus) {
                return false;
            }
            foreach ($fields as $key => $value) {
                if ($value === null) {
                    unset($rows[$i][$key]);
                } else {
                    $rows[$i][$key] = $value;
                }
            }
            $ok = true;
            return true;
        }
        return false;
    });
    return $ok;
}

function zinesh_escrow_room_notify_peer(array $room, string $fromUid, string $type, string $title, string $message, array $meta = []): void
{
    require_once __DIR__ . '/notifications_lib.php';
    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    $peerUid = $fromUid === $employerUid ? $workerUid : $employerUid;
    if ($peerUid === '' || $peerUid === $fromUid) {
        return;
    }
    $roomId = (string)($room['id'] ?? '');
    zinesh_notify_user($peerUid, $type, $title, $message, array_merge(['roomId' => $roomId], $meta));
}

/** @return list<array<string,mixed>> */
function zinesh_escrow_rooms_load(): array
{
    $rows = zinesh_json_read(ZINESH_ESCROW_ROOMS_FILE);
    return is_array($rows) ? array_values($rows) : [];
}

/** @param list<array<string,mixed>> $rows */
function zinesh_escrow_rooms_save(array $rows): void
{
    zinesh_json_write(ZINESH_ESCROW_ROOMS_FILE, array_values($rows));
}

function zinesh_escrow_room_new_id(): string
{
    return 'ROOM-' . strtoupper(substr(hash('sha256', microtime(true) . random_bytes(8)), 0, 10));
}

function zinesh_escrow_room_find(string $roomId): ?array
{
    zinesh_escrow_room_recover_stale_transient_if_needed($roomId);
    foreach (zinesh_escrow_rooms_load() as $room) {
        if ((string)($room['id'] ?? '') === $roomId) {
            return $room;
        }
    }
    return null;
}

function zinesh_escrow_room_user_role(array $room, string $uid): ?string
{
    if ((string)($room['employerUid'] ?? '') === $uid) {
        return 'employer';
    }
    if ((string)($room['workerUid'] ?? '') === $uid) {
        return 'worker';
    }
    return null;
}

function zinesh_escrow_room_is_participant(array $room, string $uid): bool
{
    return zinesh_escrow_room_user_role($room, $uid) !== null;
}

/** @return array<string,mixed> */
function zinesh_escrow_room_public(array $room, string $viewerUid): array
{
    $role = zinesh_escrow_room_user_role($room, $viewerUid);
    $employer = zinesh_find_user_by_uid((string)($room['employerUid'] ?? ''));
    $worker = zinesh_find_user_by_uid((string)($room['workerUid'] ?? ''));
    return [
        'id' => (string)($room['id'] ?? ''),
        'status' => (string)($room['status'] ?? 'negotiating'),
        'title' => (string)($room['title'] ?? ''),
        'description' => (string)($room['description'] ?? ''),
        'agreedAmountTry' => (float)($room['agreedAmountTry'] ?? 0),
        'employerUid' => (string)($room['employerUid'] ?? ''),
        'workerUid' => (string)($room['workerUid'] ?? ''),
        'employerName' => (string)($employer['name'] ?? 'İşveren'),
        'workerName' => (string)($worker['name'] ?? 'İş alan'),
        'employerTicket' => (string)($employer['ticketNumber'] ?? ''),
        'workerTicket' => (string)($worker['ticketNumber'] ?? ''),
        'employerRequestsCollateral' => !empty($room['employerRequestsCollateral']),
        'workerRequestsCollateral' => !empty($room['workerRequestsCollateral']),
        'collateralActive' => !empty($room['collateralActive']),
        'collateralAmountTry' => (float)($room['collateralAmountTry'] ?? 0),
        'employerLockedTry' => (float)($room['employerLockedTry'] ?? 0),
        'workerLockedTry' => (float)($room['workerLockedTry'] ?? 0),
        'employerConfirmedComplete' => !empty($room['employerConfirmedComplete']),
        'workerConfirmedComplete' => !empty($room['workerConfirmedComplete']),
        'disputeFeeTry' => (float)($room['disputeFeeTry'] ?? $room['disputeDepositTry'] ?? 0),
        'disputeDepositTry' => (float)($room['disputeDepositTry'] ?? $room['disputeFeeTry'] ?? 0),
        'termsProposedBy' => (string)($room['termsProposedBy'] ?? 'employer'),
        'termsChangeRequested' => !empty($room['termsChangeRequested']),
        'termsChangeNote' => (string)($room['termsChangeNote'] ?? ''),
        'myRole' => $role,
        'createdAt' => (string)($room['createdAt'] ?? ''),
        'termsProposedAt' => (string)($room['termsProposedAt'] ?? ''),
        'lockedAt' => (string)($room['lockedAt'] ?? ''),
        'completedAt' => (string)($room['completedAt'] ?? ''),
        'dispute' => is_array($room['dispute'] ?? null) ? $room['dispute'] : null,
        'employerCancelRequested' => !empty($room['employerCancelRequested']),
        'workerCancelRequested' => !empty($room['workerCancelRequested']),
    ];
}

/** @return list<array<string,mixed>> */
function zinesh_escrow_rooms_for_user(string $uid): array
{
    zinesh_escrow_room_recover_all_stale_transients();
    $out = [];
    foreach (zinesh_escrow_rooms_load() as $room) {
        if (zinesh_escrow_room_is_participant($room, $uid)) {
            $out[] = zinesh_escrow_room_public($room, $uid);
        }
    }
    usort($out, static fn($a, $b) => strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? '')));
    return $out;
}

function zinesh_escrow_room_open_between(string $uidA, string $uidB): ?array
{
    foreach (zinesh_escrow_rooms_load() as $room) {
        $e = (string)($room['employerUid'] ?? '');
        $w = (string)($room['workerUid'] ?? '');
        $status = (string)($room['status'] ?? '');
        if (!in_array($status, ['negotiating', 'terms_pending', 'locking', 'locked', 'completion_pending', 'settling', 'disputed'], true)) {
            continue;
        }
        if (($e === $uidA && $w === $uidB) || ($e === $uidB && $w === $uidA)) {
            return $room;
        }
    }
    return null;
}

/** @return array{ok:bool,message?:string,room?:array} */
function zinesh_escrow_room_connect(array $user, string $peerTicket, string $myRole): array
{
    if (!zinesh_tl_mode_enabled()) {
        return ['ok' => false, 'message' => 'Emanet odası yalnızca TL modunda kullanılabilir.'];
    }
    zinesh_backfill_missing_user_tickets();
    $normalizedTicket = zinesh_normalize_member_ticket($peerTicket);
    $peer = zinesh_find_user_by_ticket($normalizedTicket);
    if (!$peer) {
        if (zinesh_looks_like_referral_code($peerTicket)) {
            return ['ok' => false, 'message' => 'Bu bir davet kodu gibi görünüyor. Karşı tarafın üye numarasını (ör. 22595) girin.'];
        }
        if ($normalizedTicket !== '' && !preg_match('/^\d{4,6}$/', $normalizedTicket)) {
            return ['ok' => false, 'message' => 'Üye numarası 4–6 haneli olmalı. Karşı taraftan tam numarayı isteyin.'];
        }
        return ['ok' => false, 'message' => 'Üye numarası bulunamadı. Numarayı kontrol edin; karşı taraf Profil bölümündeki üye numarasını paylaşmalı.'];
    }
    $myUid = (string)($user['uid'] ?? '');
    $peerUid = (string)($peer['uid'] ?? '');
    if ($myUid === '' || $peerUid === '') {
        return ['ok' => false, 'message' => 'Geçersiz hesap.'];
    }
    if ($myUid === $peerUid) {
        return ['ok' => false, 'message' => 'Kendi üye numaranla bağlanamazsın.'];
    }
    $role = strtolower(trim($myRole));
    if (!in_array($role, ['employer', 'worker'], true)) {
        return ['ok' => false, 'message' => 'Rol seç: iş veren (employer) veya iş alan (worker).'];
    }

    $existing = zinesh_escrow_room_open_between($myUid, $peerUid);
    if ($existing) {
        return ['ok' => true, 'room' => zinesh_escrow_room_public($existing, $myUid), 'message' => 'Mevcut görüşme odası açıldı.'];
    }

    require_once __DIR__ . '/verification_lib.php';
    $gate = zinesh_escrow_contract_verification_gate($user);
    if (!$gate['ok']) {
        return $gate;
    }

    $employerUid = $role === 'employer' ? $myUid : $peerUid;
    $workerUid = $role === 'worker' ? $myUid : $peerUid;
    $room = [
        'id' => zinesh_escrow_room_new_id(),
        'status' => 'negotiating',
        'title' => '',
        'description' => '',
        'agreedAmountTry' => 0.0,
        'employerUid' => $employerUid,
        'workerUid' => $workerUid,
        'employerRequestsCollateral' => false,
        'workerRequestsCollateral' => false,
        'collateralActive' => false,
        'collateralAmountTry' => 0.0,
        'employerLockedTry' => 0.0,
        'workerLockedTry' => 0.0,
        'employerConfirmedComplete' => false,
        'workerConfirmedComplete' => false,
        'disputeFeeTry' => 0.0,
        'dispute' => null,
        'createdAt' => date('c'),
        'lockedAt' => '',
        'completedAt' => '',
    ];

    $created = false;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($room, $myUid, $peerUid, &$created) {
        foreach ($rows as $row) {
            $e = (string)($row['employerUid'] ?? '');
            $w = (string)($row['workerUid'] ?? '');
            $status = (string)($row['status'] ?? '');
            if (!in_array($status, ['negotiating', 'terms_pending', 'locked', 'completion_pending', 'disputed'], true)) {
                continue;
            }
            if (($e === $myUid && $w === $peerUid) || ($e === $peerUid && $w === $myUid)) {
                return false;
            }
        }
        $rows[] = $room;
        $created = true;
        return true;
    });

    if (!$created) {
        $existing = zinesh_escrow_room_open_between($myUid, $peerUid);
        if ($existing) {
            return ['ok' => true, 'room' => zinesh_escrow_room_public($existing, $myUid), 'message' => 'Mevcut görüşme odası açıldı.'];
        }
        return ['ok' => false, 'message' => 'Oda oluşturulamadı.'];
    }

    $initiatorName = (string)($user['name'] ?? 'Üye');
    zinesh_escrow_room_add_message(
        (string)$room['id'],
        'system',
        'Sistem',
        $initiatorName . ' üye numarası ile bağlantı kurdu. Tutar ve şartları mesajlaşarak netleştirin.',
        'system'
    );

    $peerUid = $myUid === $employerUid ? $workerUid : $employerUid;
    if ($peerUid !== '' && $peerUid !== $myUid) {
        zinesh_escrow_room_notify_peer(
            $room,
            $myUid,
            'ESCROW_CONNECT',
            'Yeni görüşme talebi',
            $initiatorName . ' sizinle üye numaranız (' . (string)($peer['ticketNumber'] ?? '') . ') üzerinden bağlantı kurdu. Sözleşmelerim → Görüşmelerim bölümünden yanıtlayın.',
            [
                'fromUid' => $myUid,
                'fromName' => $initiatorName,
                'actions' => ['İncele'],
                'signalKind' => 'lock',
            ]
        );
    }

    return ['ok' => true, 'room' => zinesh_escrow_room_public($room, $myUid)];
}

function zinesh_escrow_room_add_message(
    string $roomId,
    string $uid,
    string $name,
    string $body,
    string $type = 'text',
    array $meta = []
): void {
    $body = trim($body);
    if ($body === '') {
        return;
    }
    zinesh_json_atomic(ZINESH_ESCROW_ROOM_MSG_FILE, static function (array &$rows) use ($roomId, $uid, $name, $body, $type, $meta) {
        $rows[] = [
            'id' => 'msg-' . substr(hash('sha256', microtime(true) . random_bytes(6)), 0, 12),
            'roomId' => $roomId,
            'uid' => $uid,
            'name' => $name,
            'body' => function_exists('mb_substr') ? mb_substr($body, 0, 2000) : substr($body, 0, 2000),
            'type' => $type,
            'meta' => $meta,
            'createdAt' => date('c'),
        ];
        if (count($rows) > 5000) {
            $rows = array_slice($rows, -5000);
        }
        return true;
    });
}

/** @return list<array<string,mixed>> */
function zinesh_escrow_room_messages(string $roomId, int $limit = 100): array
{
    $rows = zinesh_json_read(ZINESH_ESCROW_ROOM_MSG_FILE);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row) || (string)($row['roomId'] ?? '') !== $roomId) {
            continue;
        }
        $out[] = $row;
    }
    usort($out, static fn($a, $b) => strcmp((string)($a['createdAt'] ?? ''), (string)($b['createdAt'] ?? '')));
    if (count($out) > $limit) {
        $out = array_slice($out, -$limit);
    }
    return $out;
}

/** @return array{ok:bool,message?:string,room?:array} */
function zinesh_escrow_room_send_message(array $user, string $roomId, string $body): array
{
    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    if (!zinesh_escrow_room_is_participant($room, $uid)) {
        return ['ok' => false, 'message' => 'Bu odaya erişimin yok.'];
    }
    if (!in_array((string)($room['status'] ?? ''), ['negotiating', 'terms_pending', 'locked', 'completion_pending'], true)) {
        return ['ok' => false, 'message' => 'Bu oda artık mesajlaşmaya kapalı.'];
    }
    zinesh_escrow_room_add_message($roomId, $uid, (string)($user['name'] ?? 'Üye'), $body, 'text');
    $fresh = zinesh_escrow_room_find($roomId) ?? $room;
    zinesh_escrow_room_notify_peer(
        $fresh,
        $uid,
        'ESCROW_MESSAGE',
        'Yeni mesaj: ' . (string)($user['name'] ?? 'Üye'),
        (function_exists('mb_substr') ? mb_substr($body, 0, 120) : substr($body, 0, 120)),
        [
            'fromUid' => $uid,
            'fromName' => (string)($user['name'] ?? 'Üye'),
            'actions' => ['İncele'],
            'signalKind' => 'approve',
        ]
    );
    return ['ok' => true, 'room' => zinesh_escrow_room_public($fresh, $uid), 'messages' => zinesh_escrow_room_messages($roomId)];
}

/** @return array{ok:bool,message?:string,room?:array} */
function zinesh_escrow_room_propose_terms(
    array $user,
    string $roomId,
    float $amountTry,
    string $title,
    string $description,
    bool $requestCollateral
): array {
    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    if ((string)($room['employerUid'] ?? '') !== $uid) {
        return ['ok' => false, 'message' => 'Anlaşma tutarını yalnızca işveren önerebilir.'];
    }
    if (!in_array((string)($room['status'] ?? ''), ['negotiating', 'terms_pending'], true)) {
        return ['ok' => false, 'message' => 'Bu aşamada teklif güncellenemez.'];
    }
    if ($amountTry < 1) {
        return ['ok' => false, 'message' => 'Geçerli bir tutar girin.'];
    }
    $title = trim($title);
    if ($title === '') {
        return ['ok' => false, 'message' => 'İş başlığı gerekli.'];
    }
    $description = trim($description);
    if (mb_strlen($description) < 40) {
        return [
            'ok' => false,
            'message' => 'Sözleşme metni en az 40 karakter olmalı. Ne isteniyor ve ne teslim edilecek net yazın.',
        ];
    }

    zinesh_ensure_wallet_fields($user);
    $available = round((float)$user['usdtBalance'] - (float)$user['escrowBalance'], 2);
    if ($available + 1e-9 < $amountTry) {
        return [
            'ok' => false,
            'message' => sprintf(
                'Yetersiz bakiye. Anlaşma için en az %s TL kullanılabilir bakiye gerekir (şu an %s TL).',
                number_format($amountTry, 0, ',', '.'),
                number_format($available, 2, ',', '.')
            ),
        ];
    }

    $updated = null;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $amountTry,
        $title,
        $description,
        $requestCollateral,
        &$updated
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if (!in_array((string)($row['status'] ?? ''), ['negotiating', 'terms_pending'], true)) {
                return false;
            }
            $rows[$i]['agreedAmountTry'] = round($amountTry, 2);
            $rows[$i]['title'] = $title;
            $rows[$i]['description'] = $description;
            $rows[$i]['employerRequestsCollateral'] = $requestCollateral;
            $rows[$i]['status'] = 'terms_pending';
            $rows[$i]['termsProposedAt'] = date('c');
            $rows[$i]['termsProposedBy'] = 'employer';
            $rows[$i]['workerRequestsCollateral'] = false;
            unset($rows[$i]['termsChangeRequested'], $rows[$i]['termsChangeNote'], $rows[$i]['termsRejectedAt']);
            $updated = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$updated) {
        return ['ok' => false, 'message' => 'Teklif kaydedilemedi.'];
    }

    zinesh_escrow_room_add_message(
        $roomId,
        (string)($user['uid'] ?? ''),
        (string)($user['name'] ?? 'Alıcı'),
        sprintf(
            'Sözleşme teklifi: %s TL — %s. Metin: %s',
            number_format($amountTry, 0, ',', '.'),
            $title,
            mb_strlen($description) > 160 ? (mb_substr($description, 0, 160) . '…') : $description
        ),
        'terms_offer',
        ['amountTry' => $amountTry, 'requestCollateral' => $requestCollateral, 'proposedBy' => 'employer']
    );
    zinesh_audit('escrow_room_terms_proposed', ['roomId' => $roomId, 'proposedBy' => 'employer', 'amountTry' => $amountTry]);

    $freshForMemory = zinesh_escrow_room_find($roomId) ?? $updated;
    zinesh_escrow_memory_on_terms_version($freshForMemory, $user, 'employer', 'propose');

    $fresh = zinesh_escrow_room_find($roomId) ?? $updated;
    zinesh_escrow_room_notify_peer(
        $fresh,
        $uid,
        'ESCROW_LOCK',
        'Yeni anlaşma teklifi',
        sprintf(
            '%s size %s TL tutarında bir anlaşma teklifi gönderdi. İnceleyip onaylayabilirsiniz.',
            (string)($user['name'] ?? 'İşveren'),
            number_format($amountTry, 0, ',', '.')
        ),
        [
            'fromUid' => $uid,
            'fromName' => (string)($user['name'] ?? 'İşveren'),
            'actions' => ['Onayla', 'İncele'],
            'signalKind' => 'lock',
        ]
    );

    return ['ok' => true, 'room' => zinesh_escrow_room_public($updated, $uid)];
}

/** @return array{ok:bool,message?:string,room?:array,wallet?:array} */
function zinesh_escrow_room_accept_terms(array $user, string $roomId, bool $workerRequestsCollateral): array
{
    $gate = zinesh_escrow_contract_verification_gate($user);
    if (!$gate['ok']) {
        return $gate;
    }

    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    $termsProposedBy = (string)($room['termsProposedBy'] ?? 'employer');
    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');

    if ($termsProposedBy === 'worker') {
        if ($uid !== $employerUid) {
            return ['ok' => false, 'message' => 'Karşı teklifi yalnızca işveren onaylayabilir.'];
        }
    } elseif ($uid !== $workerUid) {
        return ['ok' => false, 'message' => 'Anlaşmayı yalnızca iş alan onaylayabilir.'];
    }
    if ((string)($room['status'] ?? '') === 'locking') {
        return ['ok' => false, 'message' => 'Anlaşma kilitleniyor. Birkaç saniye sonra tekrar dene.'];
    }
    if ((string)($room['status'] ?? '') !== 'terms_pending') {
        return ['ok' => false, 'message' => 'Bekleyen teklif yok.'];
    }
    if (mb_strlen(trim((string)($room['description'] ?? ''))) < 40) {
        return ['ok' => false, 'message' => 'Yazılı sözleşme eksik. Alıcı önce net sözleşme metni göndermeli.'];
    }

    $amount = round((float)($room['agreedAmountTry'] ?? 0), 2);
    if ($amount < 1) {
        return ['ok' => false, 'message' => 'Geçersiz teklif tutarı.'];
    }

    if ($termsProposedBy === 'worker') {
        $collateralActive = !empty($room['workerRequestsCollateral']);
        $collateralAmount = $collateralActive ? round($amount * ZINESH_ESCROW_COLLATERAL_RATE, 2) : 0.0;
        if ($collateralActive) {
            $workerUser = zinesh_find_user_by_uid($workerUid) ?? $user;
            zinesh_ensure_wallet_fields($workerUser);
            $workerAvailable = round((float)$workerUser['usdtBalance'] - (float)$workerUser['escrowBalance'], 2);
            if ($workerAvailable + 1e-9 < $collateralAmount) {
                return [
                    'ok' => false,
                    'message' => sprintf(
                        'İş alanın teminat bakiyesi yetersiz (%s TL gerekli). Karşı teklif onaylanamadı.',
                        number_format($collateralAmount, 2, ',', '.')
                    ),
                ];
            }
        }
        zinesh_ensure_wallet_fields($user);
        $employerAvailable = round((float)$user['usdtBalance'] - (float)$user['escrowBalance'], 2);
        if ($employerAvailable + 1e-9 < $amount) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Yetersiz bakiye. Anlaşma için en az %s TL kullanılabilir bakiye gerekir.',
                    number_format($amount, 0, ',', '.')
                ),
            ];
        }
    } else {
        $employerRequests = !empty($room['employerRequestsCollateral']);
        $collateralActive = $employerRequests && $workerRequestsCollateral;
        $collateralAmount = $collateralActive ? round($amount * ZINESH_ESCROW_COLLATERAL_RATE, 2) : 0.0;

        $employerUser = zinesh_find_user_by_uid($employerUid);
        if ($employerUser === null) {
            return ['ok' => false, 'message' => 'İşveren hesabı bulunamadı.'];
        }
        zinesh_ensure_wallet_fields($employerUser);
        $employerAvailable = round((float)$employerUser['usdtBalance'] - (float)$employerUser['escrowBalance'], 2);
        if ($employerAvailable + 1e-9 < $amount) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'İşverenin bakiyesi yetersiz. Anlaşma için en az %s TL kullanılabilir bakiye gerekir.',
                    number_format($amount, 0, ',', '.')
                ),
            ];
        }

        if ($collateralActive) {
            zinesh_ensure_wallet_fields($user);
            $workerAvailable = round((float)$user['usdtBalance'] - (float)$user['escrowBalance'], 2);
            if ($workerAvailable + 1e-9 < $collateralAmount) {
                return [
                    'ok' => false,
                    'message' => sprintf(
                        'Teminat için yeterli bakiyen yok. Gerekli: %s TL.',
                        number_format($collateralAmount, 2, ',', '.')
                    ),
                ];
            }
        }
    }

    $claimed = false;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, &$claimed) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if ((string)($row['status'] ?? '') !== 'terms_pending') {
                return false;
            }
            $rows[$i]['status'] = 'locking';
            $rows[$i]['lockingAt'] = date('c');
            unset(
                $rows[$i]['lockingEmployerFunded'],
                $rows[$i]['lockingWorkerCollateralFunded'],
                $rows[$i]['lockingCollateralTry']
            );
            $claimed = true;
            return true;
        }
        return false;
    });
    if (!$claimed) {
        return ['ok' => false, 'message' => 'Bekleyen teklif yok veya başka biri onaylıyor.'];
    }

    $rollbackRoom = static function () use ($roomId): void {
        $snap = null;
        foreach (zinesh_escrow_rooms_load() as $row) {
            if ((string)($row['id'] ?? '') === $roomId) {
                $snap = $row;
                break;
            }
        }
        if ($snap && (string)($snap['status'] ?? '') === 'locking') {
            zinesh_escrow_room_release_locking_hold($snap);
        }
        zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId) {
            foreach ($rows as $i => $row) {
                if ((string)($row['id'] ?? '') !== $roomId) {
                    continue;
                }
                if ((string)($row['status'] ?? '') === 'locking') {
                    $rows[$i]['status'] = 'terms_pending';
                    unset(
                        $rows[$i]['lockingAt'],
                        $rows[$i]['lockingEmployerFunded'],
                        $rows[$i]['lockingWorkerCollateralFunded'],
                        $rows[$i]['lockingCollateralTry']
                    );
                }
                return true;
            }
            return false;
        });
    };

    // Lock employer funds
    try {
        zinesh_update_user($employerUid, static function (array &$u) use ($amount) {
            zinesh_ensure_wallet_fields($u);
            $available = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
            if ($available + 1e-9 < $amount) {
                zinesh_wallet_abort('İşverenin bakiyesi yetersiz; anlaşma kilitlenemedi.');
            }
            $u['escrowBalance'] = round((float)$u['escrowBalance'] + $amount, 2);
        });
    } catch (Throwable $e) {
        $rollbackRoom();
        return ['ok' => false, 'message' => $e->getMessage() ?: 'İşveren bakiyesi kilitlenemedi.'];
    }
    zinesh_escrow_room_patch_fields($roomId, ['lockingEmployerFunded' => true], 'locking');

    // Lock worker collateral if bilateral
    if ($collateralActive) {
        try {
            zinesh_update_user($workerUid, static function (array &$u) use ($collateralAmount) {
                zinesh_ensure_wallet_fields($u);
                $available = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
                if ($available + 1e-9 < $collateralAmount) {
                    zinesh_wallet_abort('İş alan teminatı kilitlenemedi.');
                }
                $u['escrowBalance'] = round((float)$u['escrowBalance'] + $collateralAmount, 2);
            });
        } catch (Throwable $e) {
            zinesh_update_user($employerUid, static function (array &$u) use ($amount) {
                zinesh_ensure_wallet_fields($u);
                $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $amount), 2);
            });
            $rollbackRoom();
            return ['ok' => false, 'message' => 'Teminat kilitlenemedi; anlaşma iptal edildi.'];
        }
        zinesh_escrow_room_patch_fields($roomId, [
            'lockingWorkerCollateralFunded' => true,
            'lockingCollateralTry' => $collateralAmount,
        ], 'locking');
    }

    $updated = null;
    $finalized = zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $termsProposedBy,
        $workerRequestsCollateral,
        $collateralActive,
        $collateralAmount,
        $amount,
        &$updated
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if ((string)($row['status'] ?? '') !== 'locking') {
                return false;
            }
            $workerCollateralFlag = $termsProposedBy === 'worker'
                ? !empty($row['workerRequestsCollateral'])
                : $workerRequestsCollateral;
            $rows[$i]['workerRequestsCollateral'] = $workerCollateralFlag;
            $rows[$i]['collateralActive'] = $collateralActive;
            $rows[$i]['collateralAmountTry'] = $collateralAmount;
            $rows[$i]['employerLockedTry'] = $amount;
            $rows[$i]['workerLockedTry'] = $collateralAmount;
            $rows[$i]['status'] = 'locked';
            $rows[$i]['lockedAt'] = date('c');
            $rows[$i]['employerConfirmedComplete'] = false;
            $rows[$i]['workerConfirmedComplete'] = false;
            unset(
                $rows[$i]['lockingAt'],
                $rows[$i]['lockingEmployerFunded'],
                $rows[$i]['lockingWorkerCollateralFunded'],
                $rows[$i]['lockingCollateralTry']
            );
            $updated = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$finalized) {
        zinesh_update_user($employerUid, static function (array &$u) use ($amount) {
            zinesh_ensure_wallet_fields($u);
            $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $amount), 2);
        });
        if ($collateralActive) {
            zinesh_update_user($workerUid, static function (array &$u) use ($collateralAmount) {
                zinesh_ensure_wallet_fields($u);
                $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $collateralAmount), 2);
            });
        }
        $rollbackRoom();
        return ['ok' => false, 'message' => 'Anlaşma kaydı tamamlanamadı; kilitler geri alındı.'];
    }

    $collateralMsg = $collateralActive
        ? sprintf(' Teminat: %s TL (%%20) kilitlendi.', number_format($collateralAmount, 2, ',', '.'))
        : ' Teminatsız devam.';
    zinesh_escrow_room_add_message(
        $roomId,
        $uid,
        (string)($user['name'] ?? ($termsProposedBy === 'worker' ? 'İşveren' : 'İş alan')),
        sprintf(
            'Anlaşma onaylandı. %s TL işveren hesabında kilitlendi.%s',
            number_format($amount, 0, ',', '.'),
            $collateralMsg
        ),
        'system'
    );
    zinesh_audit('escrow_room_terms_accepted', [
        'roomId' => $roomId,
        'acceptedBy' => $termsProposedBy === 'worker' ? 'employer' : 'worker',
        'amountTry' => $amount,
    ]);

    $lockedForMemory = zinesh_escrow_room_find($roomId) ?? $updated ?? $room;
    zinesh_escrow_memory_on_terms_accepted(
        $lockedForMemory,
        $user,
        $termsProposedBy === 'worker' ? 'employer' : 'worker'
    );

    require_once __DIR__ . '/campaign_lib.php';
    zinesh_campaign_record_agreement($employerUid);
    zinesh_campaign_record_agreement($workerUid);

    zinesh_log_tl_escrow_lock($employerUid, $workerUid, $roomId, $amount, $collateralActive ? $collateralAmount : 0.0);

    $lockedRoom = $updated ?? $room;
    // para_kilitlendi → hizmet sağlayıcıya; is_baslatildi → her iki tarafa
    zinesh_eslesme_sinyal_aliciya($lockedRoom, 'para_kilitlendi', 'system', $workerUid);
    zinesh_eslesme_sinyal_oda_taraflarina($lockedRoom, 'is_baslatildi', 'system');

    $viewer = zinesh_find_user_by_uid($uid) ?? $user;
    return [
        'ok' => true,
        'room' => zinesh_escrow_room_public($updated ?? $room, $uid),
        'wallet' => zinesh_wallet_state($viewer),
        'message' => 'Anlaşma kilitlendi. İş tamamlanınca iki taraf da onaylamalı.',
    ];
}

/** @return array{ok:bool,message?:string,room?:array} */
function zinesh_escrow_room_reject_terms(array $user, string $roomId, string $reason = ''): array
{
    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    if ((string)($room['workerUid'] ?? '') !== $uid) {
        return ['ok' => false, 'message' => 'Teklifi yalnızca iş alan reddedebilir.'];
    }
    if ((string)($room['status'] ?? '') !== 'terms_pending') {
        return ['ok' => false, 'message' => 'Reddedilecek bekleyen teklif yok.'];
    }
    if ((string)($room['termsProposedBy'] ?? 'employer') !== 'employer') {
        return ['ok' => false, 'message' => 'Karşı teklif aşamasında red yerine işverenle görüşün.'];
    }

    $reasonTrim = trim($reason);
    $updated = null;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $reasonTrim, &$updated) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if ((string)($row['status'] ?? '') !== 'terms_pending') {
                return false;
            }
            $rows[$i]['status'] = 'negotiating';
            $rows[$i]['agreedAmountTry'] = 0.0;
            $rows[$i]['termsRejectedAt'] = date('c');
            unset(
                $rows[$i]['termsProposedAt'],
                $rows[$i]['termsChangeRequested'],
                $rows[$i]['termsChangeNote'],
                $rows[$i]['employerRequestsCollateral'],
                $rows[$i]['workerRequestsCollateral']
            );
            $updated = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$updated) {
        return ['ok' => false, 'message' => 'Teklif reddedilemedi.'];
    }

    $msg = 'İş alan mevcut sözleşme teklifini reddetti.';
    if ($reasonTrim !== '') {
        $msg .= ' Gerekçe: ' . (function_exists('mb_substr') ? mb_substr($reasonTrim, 0, 500) : substr($reasonTrim, 0, 500));
    }
    zinesh_escrow_room_add_message($roomId, $uid, (string)($user['name'] ?? 'İş alan'), $msg, 'terms_reject', ['reason' => $reasonTrim]);
    zinesh_audit('escrow_room_terms_rejected', ['roomId' => $roomId, 'reason' => $reasonTrim]);

    $fresh = zinesh_escrow_room_find($roomId) ?? $updated;
    zinesh_escrow_memory_on_terms_rejected($fresh, $user, $reasonTrim);
    zinesh_escrow_room_notify_peer(
        $fresh,
        $uid,
        'ESCROW_TERMS',
        'Sözleşme teklifi reddedildi',
        (string)($user['name'] ?? 'İş alan') . ' teklifi reddetti. Yeni teklif gönderebilirsiniz.',
        ['fromUid' => $uid, 'signalKind' => 'approve']
    );

    return ['ok' => true, 'room' => zinesh_escrow_room_public($fresh, $uid), 'message' => 'Teklif reddedildi.'];
}

/** @return array{ok:bool,message?:string,room?:array} */
function zinesh_escrow_room_request_changes(array $user, string $roomId, string $note): array
{
    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    if ((string)($room['workerUid'] ?? '') !== $uid) {
        return ['ok' => false, 'message' => 'Değişiklik talebini yalnızca iş alan iletebilir.'];
    }
    if ((string)($room['status'] ?? '') !== 'terms_pending') {
        return ['ok' => false, 'message' => 'Bu aşamada değişiklik talebi verilemez.'];
    }

    $noteTrim = trim($note);
    if (mb_strlen($noteTrim) < 10) {
        return ['ok' => false, 'message' => 'Değişiklik talebi en az 10 karakter olmalı.'];
    }

    $updated = null;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $noteTrim, &$updated) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if ((string)($row['status'] ?? '') !== 'terms_pending') {
                return false;
            }
            $rows[$i]['status'] = 'negotiating';
            $rows[$i]['termsChangeRequested'] = true;
            $rows[$i]['termsChangeNote'] = function_exists('mb_substr') ? mb_substr($noteTrim, 0, 2000) : substr($noteTrim, 0, 2000);
            $rows[$i]['agreedAmountTry'] = 0.0;
            unset($rows[$i]['termsProposedAt'], $rows[$i]['employerRequestsCollateral']);
            $updated = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$updated) {
        return ['ok' => false, 'message' => 'Talep kaydedilemedi.'];
    }

    zinesh_escrow_room_add_message(
        $roomId,
        $uid,
        (string)($user['name'] ?? 'İş alan'),
        'Değişiklik talebi: ' . (function_exists('mb_substr') ? mb_substr($noteTrim, 0, 500) : substr($noteTrim, 0, 500)),
        'terms_change_request',
        ['note' => $noteTrim]
    );
    zinesh_audit('escrow_room_terms_change_requested', ['roomId' => $roomId]);

    $fresh = zinesh_escrow_room_find($roomId) ?? $updated;
    zinesh_escrow_memory_on_changes_requested($fresh, $user, $noteTrim);
    zinesh_escrow_room_notify_peer(
        $fresh,
        $uid,
        'ESCROW_TERMS',
        'Sözleşme değişiklik talebi',
        (string)($user['name'] ?? 'İş alan') . ' sözleşmede değişiklik istiyor.',
        ['fromUid' => $uid, 'signalKind' => 'approve']
    );

    return ['ok' => true, 'room' => zinesh_escrow_room_public($fresh, $uid), 'message' => 'Değişiklik talebi iletildi.'];
}

/** @return array{ok:bool,message?:string,room?:array} */
function zinesh_escrow_room_counter_offer(
    array $user,
    string $roomId,
    float $amountTry,
    string $title,
    string $description,
    bool $requestCollateral
): array {
    $gate = zinesh_escrow_contract_verification_gate($user);
    if (!$gate['ok']) {
        return $gate;
    }

    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    if ((string)($room['workerUid'] ?? '') !== $uid) {
        return ['ok' => false, 'message' => 'Karşı teklifi yalnızca iş alan gönderebilir.'];
    }
    if (!in_array((string)($room['status'] ?? ''), ['negotiating', 'terms_pending'], true)) {
        return ['ok' => false, 'message' => 'Bu aşamada karşı teklif verilemez.'];
    }
    if ($amountTry < 1) {
        return ['ok' => false, 'message' => 'Geçerli bir tutar girin.'];
    }
    $title = trim($title);
    if ($title === '') {
        return ['ok' => false, 'message' => 'İş başlığı gerekli.'];
    }
    $description = trim($description);
    if (mb_strlen($description) < 40) {
        return ['ok' => false, 'message' => 'Sözleşme metni en az 40 karakter olmalı.'];
    }

    $updated = null;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $amountTry,
        $title,
        $description,
        $requestCollateral,
        &$updated
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if (!in_array((string)($row['status'] ?? ''), ['negotiating', 'terms_pending'], true)) {
                return false;
            }
            $rows[$i]['agreedAmountTry'] = round($amountTry, 2);
            $rows[$i]['title'] = $title;
            $rows[$i]['description'] = $description;
            $rows[$i]['workerRequestsCollateral'] = $requestCollateral;
            $rows[$i]['employerRequestsCollateral'] = false;
            $rows[$i]['status'] = 'terms_pending';
            $rows[$i]['termsProposedAt'] = date('c');
            $rows[$i]['termsProposedBy'] = 'worker';
            unset($rows[$i]['termsChangeRequested'], $rows[$i]['termsChangeNote'], $rows[$i]['termsRejectedAt']);
            $updated = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$updated) {
        return ['ok' => false, 'message' => 'Karşı teklif kaydedilemedi.'];
    }

    zinesh_escrow_room_add_message(
        $roomId,
        $uid,
        (string)($user['name'] ?? 'İş alan'),
        sprintf(
            'Karşı teklif: %s TL — %s. Metin: %s',
            number_format($amountTry, 0, ',', '.'),
            $title,
            mb_strlen($description) > 160 ? (mb_substr($description, 0, 160) . '…') : $description
        ),
        'terms_counter',
        ['amountTry' => $amountTry, 'requestCollateral' => $requestCollateral, 'proposedBy' => 'worker']
    );
    zinesh_audit('escrow_room_terms_counter', ['roomId' => $roomId, 'amountTry' => $amountTry]);

    $freshForMemory = zinesh_escrow_room_find($roomId) ?? $updated;
    zinesh_escrow_memory_on_terms_version($freshForMemory, $user, 'worker', 'counter_offer', ['reason' => 'counter_offer']);

    $fresh = zinesh_escrow_room_find($roomId) ?? $updated;
    zinesh_escrow_room_notify_peer(
        $fresh,
        $uid,
        'ESCROW_LOCK',
        'Karşı teklif geldi',
        sprintf(
            '%s %s TL tutarında karşı teklif gönderdi.',
            (string)($user['name'] ?? 'İş alan'),
            number_format($amountTry, 0, ',', '.')
        ),
        ['fromUid' => $uid, 'actions' => ['Onayla', 'İncele'], 'signalKind' => 'lock']
    );

    return ['ok' => true, 'room' => zinesh_escrow_room_public($fresh, $uid), 'message' => 'Karşı teklif gönderildi.'];
}

/** @return array{ok:bool,message?:string,room?:array,wallet?:array} */
function zinesh_escrow_room_confirm_complete(array $user, string $roomId): array
{
    $uid = (string)($user['uid'] ?? '');
    $role = null;
    $shouldFinalize = false;
    $updated = null;
    $alreadyDone = false;

    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $uid,
        &$role,
        &$shouldFinalize,
        &$updated,
        &$alreadyDone
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            $status = (string)($row['status'] ?? '');
            if ($status === 'completed') {
                $updated = $row;
                $alreadyDone = true;
                return true;
            }
            if (!in_array($status, ['locked', 'completion_pending'], true)) {
                zinesh_json_response(['message' => 'Bu aşamada onay verilemez.'], 400);
            }
            if ((string)($row['employerUid'] ?? '') === $uid) {
                $role = 'employer';
            } elseif ((string)($row['workerUid'] ?? '') === $uid) {
                $role = 'worker';
            } else {
                zinesh_json_response(['message' => 'Bu odaya erişimin yok.'], 403);
            }
            if ($role === 'employer') {
                $rows[$i]['employerConfirmedComplete'] = true;
            } else {
                $rows[$i]['workerConfirmedComplete'] = true;
            }
            $employerDone = !empty($rows[$i]['employerConfirmedComplete']);
            $workerDone = !empty($rows[$i]['workerConfirmedComplete']);
            if ($employerDone && $workerDone) {
                $shouldFinalize = true;
            } else {
                $rows[$i]['status'] = 'completion_pending';
            }
            $updated = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$updated) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }

    if ($alreadyDone) {
        $viewer = zinesh_find_user_by_uid($uid) ?? $user;
        return [
            'ok' => true,
            'room' => zinesh_escrow_room_public($updated, $uid),
            'wallet' => zinesh_wallet_state($viewer),
            'message' => 'İş zaten tamamlanmış.',
        ];
    }

    if ($shouldFinalize) {
        zinesh_eslesme_sinyal_eylem_tamamla($roomId, 'is_tamamlandi');
        zinesh_escrow_memory_on_release_requested($updated, $user, (string)$role);
        return zinesh_escrow_room_finalize_success($roomId, $uid);
    }

    zinesh_escrow_room_add_message(
        $roomId,
        $uid,
        (string)($user['name'] ?? 'Üye'),
        ($role === 'employer' ? 'İş veren' : 'İş alan') . ' işin tamamlandığını onayladı.',
        'system'
    );

    // is_tamamlandi → hizmet alana (onay isteği)
    if ($role === 'worker') {
        $employerUid = (string)($updated['employerUid'] ?? '');
        if ($employerUid !== '') {
            zinesh_eslesme_sinyal_aliciya($updated, 'is_tamamlandi', $uid, $employerUid);
        }
    }

    $viewer = zinesh_find_user_by_uid($uid) ?? $user;
    return [
        'ok' => true,
        'room' => zinesh_escrow_room_public($updated, $uid),
        'wallet' => zinesh_wallet_state($viewer),
        'message' => 'Onayın alındı. Karşı tarafın onayı bekleniyor.',
    ];
}

/** @return array{ok:bool,message?:string,room?:array,wallet?:array} */
function zinesh_escrow_room_finalize_success(string $roomId, ?string $viewerUid = null): array
{
    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }

    $status = (string)($room['status'] ?? '');
    if ($status === 'completed') {
        $viewerUid = $viewerUid ?? (string)($room['employerUid'] ?? '');
        $viewer = zinesh_find_user_by_uid($viewerUid);
        return [
            'ok' => true,
            'room' => zinesh_escrow_room_public($room, $viewerUid),
            'wallet' => $viewer ? zinesh_wallet_state($viewer) : null,
            'message' => 'İş zaten tamamlanmış.',
        ];
    }
    if ($status === 'settling') {
        if (!zinesh_escrow_room_transient_expired((string)($room['settlingAt'] ?? ''))) {
            return ['ok' => false, 'message' => 'Ödeme işleniyor. Lütfen birkaç saniye sonra tekrar deneyin.'];
        }
        zinesh_escrow_room_recover_stale_transient_if_needed($roomId);
        $room = zinesh_escrow_room_find($roomId);
        if (!$room) {
            return ['ok' => false, 'message' => 'Oda bulunamadı.'];
        }
        $status = (string)($room['status'] ?? '');
        if ($status === 'completed') {
            $viewerUid = $viewerUid ?? (string)($room['employerUid'] ?? '');
            $viewer = zinesh_find_user_by_uid($viewerUid);
            return [
                'ok' => true,
                'room' => zinesh_escrow_room_public($room, $viewerUid),
                'wallet' => $viewer ? zinesh_wallet_state($viewer) : null,
                'message' => 'İş zaten tamamlanmış.',
            ];
        }
        return ['ok' => false, 'message' => 'Ödeme işlenemedi. Tamamlama onayını tekrar deneyin.'];
    }
    if (!in_array($status, ['locked', 'completion_pending'], true)) {
        return ['ok' => false, 'message' => 'Bu aşamada ödeme serbest bırakılamaz.'];
    }

    $amount = round((float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2);
    $collateral = round((float)($room['workerLockedTry'] ?? 0), 2);
    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    $commission = round($amount * ZINESH_ESCROW_COMMISSION_RATE, 2);
    $payout = round($amount - $commission, 2);
    $previousStatus = $status;

    $employerBefore = zinesh_find_user_by_uid($employerUid);
    $employerUsdtBefore = $employerBefore
        ? round((float)($employerBefore['usdtBalance'] ?? 0), 2)
        : null;
    $employerEscrowBefore = $employerBefore
        ? round((float)($employerBefore['escrowBalance'] ?? 0), 2)
        : null;

    $settlingClaimed = zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $previousStatus,
        $amount,
        $employerEscrowBefore,
        $employerUsdtBefore,
        $employerUid
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            $current = (string)($row['status'] ?? '');
            if ($current === 'completed' || $current === 'settling') {
                return false;
            }
            if (!in_array($current, ['locked', 'completion_pending'], true)) {
                return false;
            }
            if ($current !== $previousStatus) {
                return false;
            }
            if ($employerUid !== '') {
                foreach ($rows as $other) {
                    if ((string)($other['id'] ?? '') === $roomId) {
                        continue;
                    }
                    if ((string)($other['employerUid'] ?? '') !== $employerUid) {
                        continue;
                    }
                    if ((string)($other['status'] ?? '') === 'settling') {
                        return false;
                    }
                }
            }
            $rows[$i]['status'] = 'settling';
            $rows[$i]['settlingAt'] = date('c');
            $rows[$i]['settlingPreviousStatus'] = $current;
            if ($employerEscrowBefore !== null && $employerUsdtBefore !== null) {
                $rows[$i]['settlingSnapshotEmployerEscrow'] = $employerEscrowBefore;
                $rows[$i]['settlingSnapshotEmployerUsdt'] = $employerUsdtBefore;
                $rows[$i]['settlingSnapshotLockTry'] = $amount;
            }
            unset($rows[$i]['settlingWalletApplied']);
            return true;
        }
        return false;
    });

    if ($settlingClaimed) {
        $settlingRoom = zinesh_escrow_room_find($roomId) ?? $room;
        zinesh_escrow_memory_on_settlement_started($settlingRoom);
    }

    if (!$settlingClaimed) {
        $fresh = zinesh_escrow_room_find($roomId);
        if ($fresh && (string)($fresh['status'] ?? '') === 'completed') {
            $viewerUid = $viewerUid ?? $employerUid;
            $viewer = zinesh_find_user_by_uid($viewerUid);
            return [
                'ok' => true,
                'room' => zinesh_escrow_room_public($fresh, $viewerUid),
                'wallet' => $viewer ? zinesh_wallet_state($viewer) : null,
                'message' => 'İş zaten tamamlanmış.',
            ];
        }
        return ['ok' => false, 'message' => 'Ödeme zaten işlendi veya oda durumu uygun değil.'];
    }

    $walletSettled = false;
    try {
        zinesh_wallet_apply_tl_escrow_settlement($employerUid, $workerUid, $amount, $payout, $collateral);
        $walletSettled = true;
        zinesh_escrow_room_patch_fields($roomId, ['settlingWalletApplied' => true], 'settling');

        $employerAfter = zinesh_find_user_by_uid($employerUid);
        if ($employerAfter === null) {
            throw new RuntimeException('İşveren cüzdanı doğrulanamadı.');
        }
        $employerUsdtAfter = round((float)($employerAfter['usdtBalance'] ?? 0), 2);
        $expectedUsdt = $employerUsdtBefore !== null
            ? round(max(0, $employerUsdtBefore - $amount), 2)
            : null;
        if ($expectedUsdt !== null && abs($employerUsdtAfter - $expectedUsdt) > 0.01) {
            zinesh_audit('escrow_room_settlement_debit_mismatch', [
                'roomId' => $roomId,
                'employerUid' => $employerUid,
                'expectedUsdt' => $expectedUsdt,
                'actualUsdt' => $employerUsdtAfter,
                'lockAmount' => $amount,
            ]);
            throw new RuntimeException('Gönderen bakiyesi düşürülemedi (muhasebe doğrulama).');
        }

        zinesh_distribute_commission($commission, 'escrow_room', 'TL');

        zinesh_log_tl_escrow_settlement($employerUid, $workerUid, $roomId, $amount, $payout);

        $completed = zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $amount, $commission, $payout) {
            foreach ($rows as $i => $row) {
                if ((string)($row['id'] ?? '') !== $roomId) {
                    continue;
                }
                if ((string)($row['status'] ?? '') !== 'settling') {
                    return false;
                }
                $rows[$i]['status'] = 'completed';
                $rows[$i]['completedAt'] = date('c');
                $rows[$i]['commissionTry'] = $commission;
                $rows[$i]['payoutTry'] = $payout;
                $rows[$i]['employerUsdtDebited'] = true;
                unset(
                    $rows[$i]['settlingAt'],
                    $rows[$i]['settlingPreviousStatus'],
                    $rows[$i]['settlingWalletApplied'],
                    $rows[$i]['settlingSnapshotEmployerEscrow'],
                    $rows[$i]['settlingSnapshotEmployerUsdt'],
                    $rows[$i]['settlingSnapshotLockTry']
                );
                return true;
            }
            return false;
        });

        if (!$completed) {
            zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $commission, $payout) {
                foreach ($rows as $i => $row) {
                    if ((string)($row['id'] ?? '') !== $roomId) {
                        continue;
                    }
                    $rows[$i]['status'] = 'completed';
                    $rows[$i]['completedAt'] = date('c');
                    $rows[$i]['commissionTry'] = $commission;
                    $rows[$i]['payoutTry'] = $payout;
                    $rows[$i]['employerUsdtDebited'] = true;
                    unset(
                        $rows[$i]['settlingAt'],
                        $rows[$i]['settlingPreviousStatus'],
                        $rows[$i]['settlingWalletApplied'],
                        $rows[$i]['settlingSnapshotEmployerEscrow'],
                        $rows[$i]['settlingSnapshotEmployerUsdt'],
                        $rows[$i]['settlingSnapshotLockTry']
                    );
                    return true;
                }
                return false;
            });
            zinesh_audit('escrow_room_settlement_room_mark_retry', ['roomId' => $roomId]);
        }
    } catch (Throwable $e) {
        if (!$walletSettled) {
            zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $previousStatus) {
                foreach ($rows as $i => $row) {
                    if ((string)($row['id'] ?? '') !== $roomId) {
                        continue;
                    }
                    if ((string)($row['status'] ?? '') === 'settling') {
                        $rows[$i]['status'] = $previousStatus;
                        unset(
                            $rows[$i]['settlingAt'],
                            $rows[$i]['settlingPreviousStatus'],
                            $rows[$i]['settlingWalletApplied'],
                            $rows[$i]['settlingSnapshotEmployerEscrow'],
                            $rows[$i]['settlingSnapshotEmployerUsdt'],
                            $rows[$i]['settlingSnapshotLockTry']
                        );
                    }
                    return true;
                }
                return false;
            });
        } else {
            zinesh_audit('escrow_room_settlement_partial', ['roomId' => $roomId, 'error' => $e->getMessage()]);
        }
        zinesh_escrow_memory_on_settlement_failed($roomId, $e->getMessage());
        return ['ok' => false, 'message' => 'Ödeme işlenemedi: ' . $e->getMessage()];
    }

    zinesh_escrow_room_add_message(
        $roomId,
        'system',
        'Sistem',
        sprintf(
            'İş tamamlandı. İş alana %s TL aktarıldı (%%5 protokol payı: %s TL).',
            number_format($payout, 2, ',', '.'),
            number_format($commission, 2, ',', '.')
        ),
        'system'
    );

    $completedRoom = zinesh_escrow_room_find($roomId);
    if ($completedRoom) {
        $completedRoom['payoutTry'] = $payout;
        $completedRoom['commissionTry'] = $commission;
        zinesh_escrow_memory_on_settlement_completed($completedRoom);
    }

    zinesh_eslesme_sinyal_eylem_tamamla($roomId, 'is_tamamlandi');
    $freshForSignal = zinesh_escrow_room_find($roomId) ?? $room;
    // onay_verildi → her iki tarafa (para serbest)
    zinesh_eslesme_sinyal_oda_taraflarina($freshForSignal, 'onay_verildi', 'system');

    if ($employerUid) {
        zinesh_recalc_trust_score($employerUid);
    }
    if ($workerUid) {
        zinesh_recalc_trust_score($workerUid);
    }

    $fresh = zinesh_escrow_room_find($roomId) ?? $room;
    $viewerUid = $viewerUid ?? $employerUid;
    $viewer = zinesh_find_user_by_uid($viewerUid);
    return [
        'ok' => true,
        'room' => zinesh_escrow_room_public($fresh, $viewerUid),
        'wallet' => $viewer ? zinesh_wallet_state($viewer) : null,
        'message' => 'Ödeme serbest bırakıldı.',
    ];
}

/** İtiraz depozitosu: anapara üzerinden %1 */
function zinesh_escrow_dispute_deposit_amount(float $principalTry): float
{
    return round(max(0.0, $principalTry) * ZINESH_ESCROW_DISPUTE_FEE_RATE, 2);
}

/**
 * İtiraz açanın depozitosunu tahsil eder (treasury'ye gitmez — çözümde dağıtılır).
 *
 * @return array{ok:bool,source?:string,message?:string}
 */
function zinesh_escrow_dispute_collect_filer_deposit(array $room, string $filerUid, float $deposit): array
{
    if ($deposit <= 1e-9) {
        return ['ok' => true, 'source' => 'none'];
    }

    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    $principal = round((float)($room['employerLockedTry'] ?? 0), 2);
    $collateral = round((float)($room['workerLockedTry'] ?? 0), 2);

    if ($filerUid === $employerUid) {
        if ($principal + 1e-9 < $deposit) {
            return ['ok' => false, 'message' => 'Şikayet depozitosu için yeterli kilitli tutar yok.'];
        }
        return ['ok' => true, 'source' => 'employer_locked'];
    }

    if ($filerUid !== $workerUid) {
        return ['ok' => false, 'message' => 'Geçersiz itiraz eden.'];
    }

    $worker = zinesh_find_user_by_uid($workerUid);
    if (!$worker) {
        return ['ok' => false, 'message' => 'İş alan hesabı bulunamadı.'];
    }
    zinesh_ensure_wallet_fields($worker);
    $available = round((float)$worker['usdtBalance'] - (float)$worker['escrowBalance'], 2);
    if ($available + 1e-9 >= $deposit) {
        try {
            zinesh_update_user($workerUid, static function (array &$u) use ($deposit) {
                zinesh_ensure_wallet_fields($u);
                $avail = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
                if ($avail + 1e-9 < $deposit) {
                    zinesh_json_response(['message' => 'Şikayet depozitosu için yeterli bakiye yok.'], 400);
                }
                $u['escrowBalance'] = round((float)$u['escrowBalance'] + $deposit, 2);
            });
            return ['ok' => true, 'source' => 'worker_balance'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Şikayet depozitosu tahsil edilemedi.'];
        }
    }

    if ($collateral + 1e-9 >= $deposit) {
        return ['ok' => true, 'source' => 'worker_collateral'];
    }

    return [
        'ok' => false,
        'message' => sprintf(
            'Şikayet depozitosu için en az %s TL bakiye veya teminat gerekir.',
            number_format($deposit, 2, ',', '.')
        ),
    ];
}

/** İtiraz depozitosu tahsilini geri al (oda kaydı başarısız olursa). */
function zinesh_escrow_dispute_refund_filer_deposit(array $room, string $filerUid, float $deposit, string $source): void
{
    if ($deposit <= 1e-9 || $source === 'none' || $source === 'employer_locked' || $source === 'worker_collateral') {
        return;
    }
    if ($source === 'worker_balance' && $filerUid !== '') {
        zinesh_update_user($filerUid, static function (array &$u) use ($deposit) {
            zinesh_ensure_wallet_fields($u);
            $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $deposit), 2);
        });
    }
}

/**
 * Haklı tarafa itiraz depozitosu iadesi / haksıza aktarım.
 *
 * @return array{employerBonus:float,workerBonus:float,disposition:string}
 */
function zinesh_escrow_dispute_deposit_disposition(
    string $fault,
    string $filerUid,
    string $employerUid,
    string $workerUid,
    float $deposit
): array {
    if ($deposit <= 1e-9) {
        return ['employerBonus' => 0.0, 'workerBonus' => 0.0, 'disposition' => 'none'];
    }

    $filerIsEmployer = $filerUid === $employerUid;
    $filerIsWorker = $filerUid === $workerUid;

    if ($fault === 'none') {
        if ($filerIsEmployer) {
            return ['employerBonus' => $deposit, 'workerBonus' => 0.0, 'disposition' => 'refund_filer'];
        }
        if ($filerIsWorker) {
            return ['employerBonus' => 0.0, 'workerBonus' => $deposit, 'disposition' => 'refund_filer'];
        }
    }

    if ($fault === 'worker' && $filerIsEmployer) {
        return ['employerBonus' => $deposit, 'workerBonus' => 0.0, 'disposition' => 'refund_filer'];
    }
    if ($fault === 'employer' && $filerIsWorker) {
        return ['employerBonus' => 0.0, 'workerBonus' => $deposit, 'disposition' => 'refund_filer'];
    }

    if ($fault === 'split') {
        $half = round($deposit / 2, 2);
        $other = round($deposit - $half, 2);
        if ($filerIsEmployer) {
            return ['employerBonus' => $half, 'workerBonus' => $other, 'disposition' => 'split'];
        }
        if ($filerIsWorker) {
            return ['employerBonus' => $other, 'workerBonus' => $half, 'disposition' => 'split'];
        }
    }

    if ($fault === 'worker' && $filerIsWorker) {
        return ['employerBonus' => $deposit, 'workerBonus' => 0.0, 'disposition' => 'forfeit_to_peer'];
    }
    if ($fault === 'employer' && $filerIsEmployer) {
        return ['employerBonus' => 0.0, 'workerBonus' => $deposit, 'disposition' => 'forfeit_to_peer'];
    }

    return ['employerBonus' => 0.0, 'workerBonus' => 0.0, 'disposition' => 'protocol'];
}

/** @return array{ok:bool,message?:string,room?:array} */
function zinesh_escrow_room_file_dispute(array $user, string $roomId, string $reason, string $evidence = ''): array
{
    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    if (!zinesh_escrow_room_is_participant($room, $uid)) {
        return ['ok' => false, 'message' => 'Bu odaya erişimin yok.'];
    }

    if ((string)($room['status'] ?? '') === 'disputed') {
        $existing = is_array($room['dispute'] ?? null) ? $room['dispute'] : [];
        if ((string)($existing['status'] ?? '') === 'open') {
            return [
                'ok' => true,
                'room' => zinesh_escrow_room_public($room, $uid),
                'message' => 'Şikayet zaten açık.',
            ];
        }
    }

    if (!in_array((string)($room['status'] ?? ''), ['locked', 'completion_pending'], true)) {
        return ['ok' => false, 'message' => 'Şikayet yalnızca kilitli işlerde açılabilir.'];
    }

    $principal = round((float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2);
    $deposit = zinesh_escrow_dispute_deposit_amount($principal);
    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');

    $collect = zinesh_escrow_dispute_collect_filer_deposit($room, $uid, $deposit);
    if (!$collect['ok']) {
        return ['ok' => false, 'message' => $collect['message'] ?? 'Şikayet depozitosu tahsil edilemedi.'];
    }
    $depositSource = (string)($collect['source'] ?? 'none');

    $updated = null;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $uid,
        $reason,
        $evidence,
        $deposit,
        $depositSource,
        $principal,
        &$updated
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if (!in_array((string)($row['status'] ?? ''), ['locked', 'completion_pending'], true)) {
                return false;
            }
            $lockedPrincipal = round((float)($row['employerLockedTry'] ?? 0), 2);
            $workerCollateral = round((float)($row['workerLockedTry'] ?? 0), 2);

            if ($depositSource === 'employer_locked' && $lockedPrincipal + 1e-9 < $deposit) {
                return false;
            }
            if ($depositSource === 'worker_collateral' && $workerCollateral + 1e-9 < $deposit) {
                return false;
            }

            $reasonTrim = trim($reason);
            $evidenceTrim = trim($evidence);
            $rows[$i]['status'] = 'disputed';
            $rows[$i]['disputeDepositTry'] = $deposit;
            $rows[$i]['disputeFeeTry'] = $deposit;
            $rows[$i]['disputeDepositPaidByUid'] = $uid;
            $rows[$i]['disputeDepositSource'] = $depositSource;

            if ($depositSource === 'employer_locked') {
                $rows[$i]['employerLockedTry'] = round(max(0, $lockedPrincipal - $deposit), 2);
            } elseif ($depositSource === 'worker_collateral') {
                $rows[$i]['workerLockedTry'] = round(max(0, $workerCollateral - $deposit), 2);
            }

            $rows[$i]['dispute'] = [
                'filedByUid' => $uid,
                'feePaidByUid' => $uid,
                'depositTry' => $deposit,
                'depositSource' => $depositSource,
                'reason' => function_exists('mb_substr') ? mb_substr($reasonTrim, 0, 2000) : substr($reasonTrim, 0, 2000),
                'evidence' => function_exists('mb_substr') ? mb_substr($evidenceTrim, 0, 4000) : substr($evidenceTrim, 0, 4000),
                'filedAt' => date('c'),
                'status' => 'open',
            ];
            $updated = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$updated) {
        zinesh_escrow_dispute_refund_filer_deposit($room, $uid, $deposit, $depositSource);
        return ['ok' => false, 'message' => 'Şikayet kaydedilemedi.'];
    }

    $filerRole = $uid === $employerUid ? 'işveren' : 'iş alan';
    zinesh_escrow_room_add_message(
        $roomId,
        $uid,
        (string)($user['name'] ?? 'Üye'),
        sprintf(
            'Şikayet açıldı (%s depozito: %s TL, %%1). İnceleme bekleniyor. Haklı çıkana depozito iade edilir.',
            $filerRole,
            number_format($deposit, 2, ',', '.')
        ),
        'system'
    );
    zinesh_audit('escrow_room_dispute_filed', [
        'roomId' => $roomId,
        'filedByUid' => $uid,
        'depositTry' => $deposit,
        'depositSource' => $depositSource,
    ]);
    zinesh_escrow_memory_on_dispute_opened($updated, $user, $deposit, $depositSource);

    zinesh_eslesme_sinyal_oda_taraflarina($updated, 'hakem_cagirildi', $uid);

    return [
        'ok' => true,
        'room' => zinesh_escrow_room_public($updated, $uid),
        'message' => 'Şikayetin alındı. Yetkililer inceleyecek.',
    ];
}

/**
 * Admin dispute resolution.
 * $fault: employer | worker | split | none
 *
 * @return array{ok:bool,message?:string}
 */
function zinesh_escrow_room_resolve_dispute(string $roomId, string $fault, string $adminNote = ''): array
{
    $room = zinesh_escrow_room_find($roomId);
    if (!$room || (string)($room['status'] ?? '') !== 'disputed') {
        return ['ok' => false, 'message' => 'Açık şikayet bulunamadı.'];
    }

    $dispute = is_array($room['dispute'] ?? null) ? $room['dispute'] : [];
    if ((string)($dispute['status'] ?? '') === 'resolved') {
        return ['ok' => true, 'message' => 'Şikayet zaten sonuçlandırılmış.'];
    }

    $amount = round((float)($room['employerLockedTry'] ?? 0), 2);
    $collateral = round((float)($room['workerLockedTry'] ?? 0), 2);
    $deposit = round((float)($room['disputeDepositTry'] ?? $room['disputeFeeTry'] ?? 0), 2);
    $depositSource = (string)($room['disputeDepositSource'] ?? $dispute['depositSource'] ?? '');
    $filerUid = (string)($room['disputeDepositPaidByUid'] ?? $dispute['feePaidByUid'] ?? $dispute['filedByUid'] ?? '');
    $employerEscrowRelease = $depositSource === 'employer_locked' ? round($amount + $deposit, 2) : $amount;
    $workerEscrowRelease = $collateral;
    if ($depositSource === 'worker_collateral') {
        $workerEscrowRelease = round($collateral + $deposit, 2);
    } elseif ($depositSource === 'worker_balance') {
        $workerEscrowRelease = round($collateral + $deposit, 2);
    }
    $commission = round($amount * ZINESH_ESCROW_COMMISSION_RATE, 2);

    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    $fault = strtolower(trim($fault));
    if (!in_array($fault, ['employer', 'worker', 'split', 'none'], true)) {
        $fault = 'split';
    }

    $workerPayout = 0.0;
    $employerRefund = 0.0;
    if ($fault === 'worker') {
        $employerRefund = max(0, $amount - $commission);
        zinesh_apply_trust_fault_penalty($workerUid, ZINESH_ESCROW_TRUST_PENALTY);
    } elseif ($fault === 'employer') {
        $workerPayout = max(0, $amount - $commission);
        zinesh_apply_trust_fault_penalty($employerUid, ZINESH_ESCROW_TRUST_PENALTY);
    } elseif ($fault === 'split') {
        $net = max(0, $amount - $commission);
        $workerPayout = round($net / 2, 2);
        $employerRefund = round($net - $workerPayout, 2);
    } else {
        $workerPayout = max(0, $amount - $commission);
    }

    $depositDisposition = zinesh_escrow_dispute_deposit_disposition($fault, $filerUid, $employerUid, $workerUid, $deposit);
    $employerRefund = round($employerRefund + $depositDisposition['employerBonus'], 2);
    $workerPayout = round($workerPayout + $depositDisposition['workerBonus'], 2);

    zinesh_json_atomic('users.json', static function (array &$users) use (
        $employerUid,
        $workerUid,
        $employerEscrowRelease,
        $workerEscrowRelease,
        $workerPayout,
        $employerRefund
    ) {
        $employerIdx = $workerIdx = null;
        foreach ($users as $i => $u) {
            if (($u['uid'] ?? '') === $employerUid) {
                $employerIdx = $i;
            }
            if (($u['uid'] ?? '') === $workerUid) {
                $workerIdx = $i;
            }
        }
        if ($employerIdx === null || $workerIdx === null) {
            zinesh_json_response(['message' => 'Taraflar bulunamadı.'], 404);
        }
        zinesh_ensure_wallet_fields($users[$employerIdx]);
        zinesh_ensure_wallet_fields($users[$workerIdx]);
        if ((float)$users[$employerIdx]['escrowBalance'] + 1e-9 < $employerEscrowRelease) {
            zinesh_json_response(['message' => 'Kilitli tutar yetersiz.'], 400);
        }
        $employerDebit = round(max(0, $employerEscrowRelease - $employerRefund), 2);
        $users[$employerIdx]['escrowBalance'] = round((float)$users[$employerIdx]['escrowBalance'] - $employerEscrowRelease, 2);
        if ($employerDebit > 0) {
            $users[$employerIdx]['usdtBalance'] = round((float)$users[$employerIdx]['usdtBalance'] - $employerDebit, 2);
        }
        if ($workerPayout > 0) {
            $users[$workerIdx]['usdtBalance'] = round((float)$users[$workerIdx]['usdtBalance'] + $workerPayout, 2);
        }
        if ($workerEscrowRelease > 0 && (float)$users[$workerIdx]['escrowBalance'] + 1e-9 >= $workerEscrowRelease) {
            $users[$workerIdx]['escrowBalance'] = round((float)$users[$workerIdx]['escrowBalance'] - $workerEscrowRelease, 2);
        }
        return true;
    });

    if ($commission > 1e-9) {
        zinesh_distribute_commission($commission, 'escrow_dispute', 'TL');
    }

    $forfeitToProtocol = $depositDisposition['disposition'] === 'protocol' ? $deposit : 0.0;
    if ($forfeitToProtocol > 1e-9) {
        zinesh_add_treasury_fee('protocol_commission_tl', $forfeitToProtocol);
    }

    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $fault,
        $adminNote,
        $commission,
        $deposit,
        $depositDisposition,
        $workerPayout,
        $employerRefund
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            $rows[$i]['status'] = 'resolved';
            $rows[$i]['completedAt'] = date('c');
            $disputeRow = is_array($row['dispute'] ?? null) ? $row['dispute'] : [];
            $disputeRow['status'] = 'resolved';
            $disputeRow['fault'] = $fault;
            $disputeRow['adminNote'] = mb_substr(trim($adminNote), 0, 2000);
            $disputeRow['resolvedAt'] = date('c');
            $disputeRow['commissionTry'] = $commission;
            $disputeRow['depositDisposition'] = $depositDisposition['disposition'];
            $disputeRow['depositTry'] = $deposit;
            $disputeRow['workerPayoutTry'] = $workerPayout;
            $disputeRow['employerRefundTry'] = $employerRefund;
            $rows[$i]['dispute'] = $disputeRow;
            return true;
        }
        return false;
    });

    zinesh_audit('escrow_room_dispute_resolved', [
        'roomId' => $roomId,
        'fault' => $fault,
        'depositDisposition' => $depositDisposition['disposition'],
        'depositTry' => $deposit,
    ]);

    $resolvedRoom = zinesh_escrow_room_find($roomId);
    if ($resolvedRoom) {
        zinesh_escrow_memory_on_dispute_resolved(
            $resolvedRoom,
            $fault,
            $depositDisposition['disposition'],
            $deposit
        );
    }

    if ($employerUid !== '') {
        zinesh_recalc_trust_score($employerUid);
    }
    if ($workerUid !== '') {
        zinesh_recalc_trust_score($workerUid);
    }

    return ['ok' => true, 'message' => 'Şikayet karara bağlandı.'];
}

/** @return array{ok:bool,message?:string,room?:array,wallet?:array} */
function zinesh_escrow_room_request_cancel(array $user, string $roomId): array
{
    $uid = (string)($user['uid'] ?? '');
    $role = null;
    $mutualCancel = false;
    $updated = null;
    $roomSnapshot = null;

    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
        $uid,
        &$role,
        &$mutualCancel,
        &$updated,
        &$roomSnapshot
    ) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            $status = (string)($row['status'] ?? '');
            if (!in_array($status, ['locked', 'completion_pending'], true)) {
                zinesh_json_response(['message' => 'Bu aşamada iptal talebi verilemez.'], 400);
            }
            if ((string)($row['employerUid'] ?? '') === $uid) {
                $role = 'employer';
            } elseif ((string)($row['workerUid'] ?? '') === $uid) {
                $role = 'worker';
            } else {
                zinesh_json_response(['message' => 'Bu odaya erişimin yok.'], 403);
            }
            if ($role === 'employer') {
                $rows[$i]['employerCancelRequested'] = true;
            } else {
                $rows[$i]['workerCancelRequested'] = true;
            }
            $employerCancel = !empty($rows[$i]['employerCancelRequested']);
            $workerCancel = !empty($rows[$i]['workerCancelRequested']);
            if ($employerCancel && $workerCancel) {
                $mutualCancel = true;
                $rows[$i]['status'] = 'cancelled';
                $rows[$i]['completedAt'] = date('c');
            }
            $updated = $rows[$i];
            $roomSnapshot = $rows[$i];
            return true;
        }
        return false;
    });

    if (!$updated || !$roomSnapshot) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }

    if (!$mutualCancel) {
        $employerUidPeer = (string)($updated['employerUid'] ?? '');
        $workerUidPeer = (string)($updated['workerUid'] ?? '');
        $peerUid = $uid === $employerUidPeer ? $workerUidPeer : $employerUidPeer;
        // iptal_istegi → karşı tarafa (onay beklenir)
        if ($peerUid !== '') {
            zinesh_eslesme_sinyal_aliciya($updated, 'iptal_istegi', $uid, $peerUid);
        }
        return [
            'ok' => true,
            'room' => zinesh_escrow_room_public($updated, $uid),
            'wallet' => zinesh_wallet_state(zinesh_find_user_by_uid($uid) ?? $user),
            'message' => 'İptal talebin alındı. Karşı tarafın onayı bekleniyor.',
        ];
    }

    zinesh_eslesme_sinyal_eylem_tamamla($roomId, 'iptal_istegi');

    $collateral = round((float)($roomSnapshot['workerLockedTry'] ?? 0), 2);
    $employerUid = (string)($roomSnapshot['employerUid'] ?? '');
    $workerUid = (string)($roomSnapshot['workerUid'] ?? '');
    $unlockEmployer = round((float)($roomSnapshot['employerLockedTry'] ?? $roomSnapshot['agreedAmountTry'] ?? 0), 2);

    if ($employerUid !== '' && $unlockEmployer > 0) {
        zinesh_update_user($employerUid, static function (array &$u) use ($unlockEmployer) {
            zinesh_ensure_wallet_fields($u);
            if ((float)$u['escrowBalance'] + 1e-9 >= $unlockEmployer) {
                $u['escrowBalance'] = round((float)$u['escrowBalance'] - $unlockEmployer, 2);
            }
        });
    }
    if ($workerUid !== '' && $collateral > 0) {
        zinesh_update_user($workerUid, static function (array &$u) use ($collateral) {
            zinesh_ensure_wallet_fields($u);
            if ((float)$u['escrowBalance'] + 1e-9 >= $collateral) {
                $u['escrowBalance'] = round((float)$u['escrowBalance'] - $collateral, 2);
            }
        });
    }

    zinesh_escrow_room_add_message($roomId, $uid, (string)($user['name'] ?? 'Üye'), 'Her iki taraf iptali onayladı — kilit kaldırıldı.', 'system');
    if ($employerUid !== '') {
        zinesh_recalc_trust_score($employerUid);
    }
    if ($workerUid !== '') {
        zinesh_recalc_trust_score($workerUid);
    }
    $viewer = zinesh_find_user_by_uid($uid) ?? $user;
    return [
        'ok' => true,
        'room' => zinesh_escrow_room_public(zinesh_escrow_room_find($roomId) ?? $updated, $uid),
        'wallet' => zinesh_wallet_state($viewer),
        'message' => 'Emanet iptal edildi.',
    ];
}

/** @return list<array<string,mixed>> */
function zinesh_escrow_room_disputes_open(): array
{
    $out = [];
    foreach (zinesh_escrow_rooms_load() as $room) {
        if ((string)($room['status'] ?? '') === 'disputed') {
            $out[] = $room;
        }
    }
    return $out;
}

/** Emanet odası durumunu güven profili / iş geçmişi satırına eşle. */
function zinesh_escrow_room_trust_job_status(string $roomStatus): string
{
    return match ($roomStatus) {
        'completed' => 'success',
        'cancelled' => 'cancelled',
        'disputed', 'resolved' => 'disputed',
        'locked', 'completion_pending', 'settling' => 'completion_pending',
        default => 'active',
    };
}

/** @return array<string,mixed> */
function zinesh_escrow_room_trust_job_row(array $room, ?string $viewerUid = null): array
{
    $roomStatus = (string)($room['status'] ?? '');
    $status = zinesh_escrow_room_trust_job_status($roomStatus);
    $employer = zinesh_find_user_by_uid((string)($room['employerUid'] ?? ''));
    $worker = zinesh_find_user_by_uid((string)($room['workerUid'] ?? ''));
    $role = ($viewerUid !== null && $viewerUid !== '') ? zinesh_escrow_room_user_role($room, $viewerUid) : null;
    $amount = (float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0);

    return [
        'id' => (string)($room['id'] ?? ''),
        'matchCode' => '',
        'title' => (string)($room['title'] ?? 'Emanet odası'),
        'description' => (string)($room['description'] ?? ''),
        'category' => 'Emanet odası',
        'value' => $amount,
        'status' => $status,
        'fundsLocked' => in_array($roomStatus, ['locked', 'completion_pending', 'settling'], true),
        'lockedValue' => in_array($roomStatus, ['locked', 'completion_pending', 'settling'], true) ? $amount : 0.0,
        'buyerName' => (string)($employer['name'] ?? 'İşveren'),
        'supplierName' => (string)($worker['name'] ?? 'İş alan'),
        'deliveryDate' => '',
        'createdAt' => (string)($room['createdAt'] ?? ''),
        'matchedAt' => (string)($room['lockedAt'] ?? ''),
        'activatedAt' => (string)($room['lockedAt'] ?? ''),
        'completedAt' => (string)($room['completedAt'] ?? ''),
        'commission' => isset($room['commissionTry']) ? (float)$room['commissionTry'] : null,
        'payout' => isset($room['payoutTry']) ? (float)$room['payoutTry'] : null,
        'myRole' => $role === 'employer' ? 'buyer' : ($role === 'worker' ? 'supplier' : null),
    ];
}

/** @return array{active:int,completed:int,unsuccessful:int} */
function zinesh_escrow_room_stats_for_user(string $uid): array
{
    $active = 0;
    $success = 0;
    $failed = 0;
    foreach (zinesh_escrow_rooms_load() as $room) {
        if (!zinesh_escrow_room_is_participant($room, $uid)) {
            continue;
        }
        $roomStatus = (string)($room['status'] ?? '');
        if (in_array($roomStatus, ['negotiating', 'terms_pending', 'locking', 'locked', 'completion_pending', 'settling'], true)) {
            $active++;
        } elseif ($roomStatus === 'completed') {
            $success++;
        } elseif (in_array($roomStatus, ['cancelled', 'disputed', 'resolved'], true)) {
            $failed++;
        }
    }
    return [
        'active' => $active,
        'completed' => $success,
        'unsuccessful' => $failed,
    ];
}

/** @param list<array<string,mixed>> $rooms */
function zinesh_escrow_room_trust_jobs_for_user(string $uid, array $rooms = []): array
{
    if ($rooms === []) {
        $rooms = zinesh_escrow_rooms_load();
    }
    $out = [];
    foreach ($rooms as $room) {
        if (!zinesh_escrow_room_is_participant($room, $uid)) {
            continue;
        }
        $out[] = zinesh_escrow_room_trust_job_row($room, $uid);
    }
    return $out;
}
