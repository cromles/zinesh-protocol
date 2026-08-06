<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/escrow_jobs_lib.php';
require_once __DIR__ . '/eslesme_sinyal_lib.php';

const ZINESH_ESCROW_ROOMS_FILE = 'escrow_rooms.json';
const ZINESH_ESCROW_ROOM_MSG_FILE = 'escrow_room_messages.json';

const ZINESH_ESCROW_COMMISSION_RATE = 0.05;
const ZINESH_ESCROW_DISPUTE_FEE_RATE = 0.01;
const ZINESH_ESCROW_COLLATERAL_RATE = 0.20;
const ZINESH_ESCROW_TRUST_PENALTY = 5.0;

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
        'disputeFeeTry' => (float)($room['disputeFeeTry'] ?? 0),
        'myRole' => $role,
        'createdAt' => (string)($room['createdAt'] ?? ''),
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
        if (!in_array($status, ['negotiating', 'terms_pending', 'locked', 'completion_pending', 'disputed'], true)) {
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
            $rows[$i]['workerRequestsCollateral'] = false;
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
        ['amountTry' => $amountTry, 'requestCollateral' => $requestCollateral]
    );

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
    $room = zinesh_escrow_room_find($roomId);
    if (!$room) {
        return ['ok' => false, 'message' => 'Oda bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    if ((string)($room['workerUid'] ?? '') !== $uid) {
        return ['ok' => false, 'message' => 'Anlaşmayı yalnızca iş alan onaylayabilir.'];
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

    $employerRequests = !empty($room['employerRequestsCollateral']);
    $collateralActive = $employerRequests && $workerRequestsCollateral;
    $collateralAmount = $collateralActive ? round($amount * ZINESH_ESCROW_COLLATERAL_RATE, 2) : 0.0;

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

    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');

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
            $claimed = true;
            return true;
        }
        return false;
    });
    if (!$claimed) {
        return ['ok' => false, 'message' => 'Bekleyen teklif yok veya başka biri onaylıyor.'];
    }

    $rollbackRoom = static function () use ($roomId): void {
        zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId) {
            foreach ($rows as $i => $row) {
                if ((string)($row['id'] ?? '') !== $roomId) {
                    continue;
                }
                if ((string)($row['status'] ?? '') === 'locking') {
                    $rows[$i]['status'] = 'terms_pending';
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
                zinesh_json_response(['message' => 'İşverenin bakiyesi yetersiz; anlaşma kilitlenemedi.'], 400);
            }
            $u['escrowBalance'] = round((float)$u['escrowBalance'] + $amount, 2);
        });
    } catch (Throwable $e) {
        $rollbackRoom();
        return ['ok' => false, 'message' => 'İşveren bakiyesi kilitlenemedi.'];
    }

    // Lock worker collateral if bilateral
    if ($collateralActive) {
        try {
            zinesh_update_user($workerUid, static function (array &$u) use ($collateralAmount) {
                zinesh_ensure_wallet_fields($u);
                $available = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
                if ($available + 1e-9 < $collateralAmount) {
                    zinesh_json_response(['message' => 'İş alan teminatı kilitlenemedi.'], 400);
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
    }

    $updated = null;
    $finalized = zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use (
        $roomId,
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
            $rows[$i]['workerRequestsCollateral'] = $workerRequestsCollateral;
            $rows[$i]['collateralActive'] = $collateralActive;
            $rows[$i]['collateralAmountTry'] = $collateralAmount;
            $rows[$i]['employerLockedTry'] = $amount;
            $rows[$i]['workerLockedTry'] = $collateralAmount;
            $rows[$i]['status'] = 'locked';
            $rows[$i]['lockedAt'] = date('c');
            $rows[$i]['employerConfirmedComplete'] = false;
            $rows[$i]['workerConfirmedComplete'] = false;
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
        (string)($user['name'] ?? 'İş alan'),
        sprintf(
            'Anlaşma onaylandı. %s TL işveren hesabında kilitlendi.%s',
            number_format($amount, 0, ',', '.'),
            $collateralMsg
        ),
        'system'
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
        return ['ok' => false, 'message' => 'Ödeme işleniyor. Lütfen birkaç saniye sonra tekrar deneyin.'];
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

    $settlingClaimed = zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $previousStatus) {
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
            $rows[$i]['status'] = 'settling';
            $rows[$i]['settlingAt'] = date('c');
            return true;
        }
        return false;
    });

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
                unset($rows[$i]['settlingAt']);
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
                    unset($rows[$i]['settlingAt']);
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
                        unset($rows[$i]['settlingAt']);
                    }
                    return true;
                }
                return false;
            });
        } else {
            zinesh_audit('escrow_room_settlement_partial', ['roomId' => $roomId, 'error' => $e->getMessage()]);
        }
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
    if (!in_array((string)($room['status'] ?? ''), ['locked', 'completion_pending'], true)) {
        return ['ok' => false, 'message' => 'Şikayet yalnızca kilitli işlerde açılabilir.'];
    }

    $amount = round((float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2);
    $disputeFee = round($amount * ZINESH_ESCROW_DISPUTE_FEE_RATE, 2);
    $employerUid = (string)($room['employerUid'] ?? '');

    if ($disputeFee > 0 && $employerUid !== '') {
        try {
            zinesh_update_user($employerUid, static function (array &$u) use ($disputeFee) {
                zinesh_ensure_wallet_fields($u);
                if ((float)$u['escrowBalance'] + 1e-9 < $disputeFee) {
                    zinesh_json_response(['message' => 'Şikayet ücreti için yeterli kilitli bakiye yok.'], 400);
                }
                $u['escrowBalance'] = round((float)$u['escrowBalance'] - $disputeFee, 2);
                $u['usdtBalance'] = round((float)$u['usdtBalance'] - $disputeFee, 2);
            });
            zinesh_add_treasury_fee('protocol_commission_tl', $disputeFee);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Şikayet ücreti tahsil edilemedi.'];
        }
    }

    $updated = null;
    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $uid, $reason, $evidence, $disputeFee, $amount, &$updated) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            if (!in_array((string)($row['status'] ?? ''), ['locked', 'completion_pending'], true)) {
                return false;
            }
            $rows[$i]['status'] = 'disputed';
            $rows[$i]['disputeFeeTry'] = $disputeFee;
            $rows[$i]['employerLockedTry'] = round(max(0, $amount - $disputeFee), 2);
            $reasonTrim = trim($reason);
            $evidenceTrim = trim($evidence);
            $rows[$i]['dispute'] = [
                'filedByUid' => $uid,
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
        if ($disputeFee > 0 && $employerUid !== '') {
            zinesh_update_user($employerUid, static function (array &$u) use ($disputeFee) {
                zinesh_ensure_wallet_fields($u);
                $u['escrowBalance'] = round((float)$u['escrowBalance'] + $disputeFee, 2);
                $u['usdtBalance'] = round((float)$u['usdtBalance'] + $disputeFee, 2);
            });
        }
        return ['ok' => false, 'message' => 'Şikayet kaydedilemedi.'];
    }

    zinesh_escrow_room_add_message(
        $roomId,
        $uid,
        (string)($user['name'] ?? 'Üye'),
        sprintf('Şikayet açıldı. Hizmet bedeli: %s TL (%%1). İnceleme bekleniyor.', number_format($disputeFee, 2, ',', '.')),
        'system'
    );

    // hakem_cagirildi → her iki tarafa
    zinesh_eslesme_sinyal_oda_taraflarina($updated ?? $room, 'hakem_cagirildi', $uid);

    return [
        'ok' => true,
        'room' => zinesh_escrow_room_public($updated ?? $room, $uid),
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

    $amount = round((float)($room['employerLockedTry'] ?? 0), 2);
    $collateral = round((float)($room['workerLockedTry'] ?? 0), 2);
    $disputeFee = round((float)($room['disputeFeeTry'] ?? 0), 2);
    $commission = round($amount * ZINESH_ESCROW_COMMISSION_RATE, 2);
    $totalFees = round($disputeFee + $commission, 2);

    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    $fault = strtolower(trim($fault));
    if (!in_array($fault, ['employer', 'worker', 'split', 'none'], true)) {
        $fault = 'split';
    }

    $workerPayout = 0.0;
    $employerRefund = 0.0;
    if ($fault === 'worker') {
        $workerPayout = 0.0;
        $employerRefund = max(0, $amount - $totalFees);
        zinesh_apply_trust_fault_penalty($workerUid, ZINESH_ESCROW_TRUST_PENALTY);
    } elseif ($fault === 'employer') {
        $workerPayout = max(0, $amount - $totalFees);
        $employerRefund = 0.0;
        zinesh_apply_trust_fault_penalty($employerUid, ZINESH_ESCROW_TRUST_PENALTY);
    } elseif ($fault === 'split') {
        $net = max(0, $amount - $totalFees);
        $workerPayout = round($net / 2, 2);
        $employerRefund = round($net - $workerPayout, 2);
    } else {
        $workerPayout = max(0, $amount - $totalFees);
        $employerRefund = 0.0;
    }

    zinesh_json_atomic('users.json', static function (array &$users) use (
        $employerUid,
        $workerUid,
        $amount,
        $collateral,
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
        if ((float)$users[$employerIdx]['escrowBalance'] + 1e-9 < $amount) {
            zinesh_json_response(['message' => 'Kilitli tutar yetersiz.'], 400);
        }
        $employerDebit = round(max(0, $amount - $employerRefund), 2);
        $users[$employerIdx]['escrowBalance'] = round((float)$users[$employerIdx]['escrowBalance'] - $amount, 2);
        if ($employerDebit > 0) {
            $users[$employerIdx]['usdtBalance'] = round((float)$users[$employerIdx]['usdtBalance'] - $employerDebit, 2);
        }
        if ($workerPayout > 0) {
            $users[$workerIdx]['usdtBalance'] = round((float)$users[$workerIdx]['usdtBalance'] + $workerPayout, 2);
        }
        if ($collateral > 0 && (float)$users[$workerIdx]['escrowBalance'] + 1e-9 >= $collateral) {
            $users[$workerIdx]['escrowBalance'] = round((float)$users[$workerIdx]['escrowBalance'] - $collateral, 2);
        }
        return true;
    });

    if ($commission > 1e-9) {
        zinesh_distribute_commission($commission, 'escrow_dispute', 'TL');
    }

    zinesh_json_atomic(ZINESH_ESCROW_ROOMS_FILE, static function (array &$rows) use ($roomId, $fault, $adminNote, $totalFees, $workerPayout, $employerRefund) {
        foreach ($rows as $i => $row) {
            if ((string)($row['id'] ?? '') !== $roomId) {
                continue;
            }
            $rows[$i]['status'] = 'resolved';
            $rows[$i]['completedAt'] = date('c');
            $dispute = is_array($row['dispute'] ?? null) ? $row['dispute'] : [];
            $dispute['status'] = 'resolved';
            $dispute['fault'] = $fault;
            $dispute['adminNote'] = mb_substr(trim($adminNote), 0, 2000);
            $dispute['resolvedAt'] = date('c');
            $dispute['totalFeesTry'] = $totalFees;
            $dispute['workerPayoutTry'] = $workerPayout;
            $dispute['employerRefundTry'] = $employerRefund;
            $rows[$i]['dispute'] = $dispute;
            return true;
        }
        return false;
    });

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
