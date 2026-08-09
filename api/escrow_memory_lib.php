<?php
declare(strict_types=1);

require_once __DIR__ . '/zinesh_domain_events_lib.php';
require_once __DIR__ . '/contract_versions_lib.php';

/** @var array<string,string> */
const ZINESH_ESCROW_TIMELINE_LABELS = [
    'contract_created' => 'Sözleşme oluşturuldu',
    'terms_proposed' => 'Sözleşme teklifi gönderildi',
    'terms_updated' => 'Sözleşme güncellendi',
    'terms_rejected' => 'Sözleşme teklifi reddedildi',
    'changes_requested' => 'Değişiklik talebi iletildi',
    'counter_offer_created' => 'Karşı teklif gönderildi',
    'terms_accepted' => 'Sözleşme onaylandı',
    'contract_finalized' => 'Sözleşme kesinleşti',
    'escrow_funded' => 'Emanet fonlandı',
    'escrow_locked' => 'Emanet kilitlendi',
    'escrow_release_requested' => 'Ödeme serbest bırakma talebi',
    'settlement_started' => 'Ödeme işlemi başladı',
    'settlement_completed' => 'Ödeme tamamlandı',
    'settlement_failed' => 'Ödeme işlemi başarısız',
    'escrow_recovered' => 'Sistem otomatik kurtarma çalıştı',
    'dispute_opened' => 'İtiraz açıldı',
    'dispute_deposit_paid' => 'İtiraz depozitosu ödendi',
    'dispute_resolved' => 'İtiraz sonuçlandı',
    'dispute_deposit_refunded' => 'İtiraz depozitosu iade edildi',
    'dispute_deposit_forfeited' => 'İtiraz depozitosu karşı tarafa aktarıldı',
];

/** @var array<string,string> */
const ZINESH_ESCROW_ROLE_LABELS = [
    'employer' => 'işveren',
    'worker' => 'iş alan',
    'system' => 'sistem',
];

function zinesh_escrow_memory_safe(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log('zinesh_escrow_memory: ' . $e->getMessage());
    }
}

function zinesh_escrow_memory_role_label(?string $role): string
{
    $role = strtolower(trim((string)$role));
    return ZINESH_ESCROW_ROLE_LABELS[$role] ?? ($role !== '' ? $role : 'katılımcı');
}

/**
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $metadata
 */
function zinesh_escrow_memory_emit(
    string $roomId,
    string $eventType,
    string $actorId,
    ?string $actorRole,
    array $payload = [],
    array $metadata = []
): void {
    zinesh_escrow_memory_safe(static function () use ($roomId, $eventType, $actorId, $actorRole, $payload, $metadata) {
        zinesh_domain_event_emit($roomId, $eventType, $actorId, $actorRole, $payload, $metadata);
    });
}

/**
 * @param array<string,mixed> $room
 * @param array<string,mixed> $actor
 * @param array<string,mixed> $extra
 */
function zinesh_escrow_memory_on_terms_version(
    array $room,
    array $actor,
    string $proposedBy,
    string $eventKind,
    array $extra = []
): void {
    $roomId = (string)($room['id'] ?? '');
    $uid = (string)($actor['uid'] ?? 'system');
    $role = $proposedBy === 'worker' ? 'worker' : 'employer';
    $title = (string)($room['title'] ?? '');
    $description = (string)($room['description'] ?? '');
    $amount = round((float)($room['agreedAmountTry'] ?? 0), 2);

    $previous = zinesh_contract_version_latest($roomId);
    $previousVersion = $previous ? (int)($previous['version_number'] ?? 0) : 0;

    $version = zinesh_contract_version_append(
        $roomId,
        $uid,
        $role,
        $title,
        $description,
        $amount,
        zinesh_contract_structured_terms_skeleton($room),
        ['event_kind' => $eventKind, 'proposed_by' => $proposedBy]
    );
    if ($version === null) {
        return;
    }

    $versionNumber = (int)($version['version_number'] ?? 0);
    $basePayload = array_merge([
        'version' => $versionNumber,
        'previous_version' => $previousVersion > 0 ? $previousVersion : null,
        'changed_by' => $proposedBy,
        'amount_try' => $amount,
        'currency' => 'TRY',
        'title' => $title,
    ], $extra);

    if ($previousVersion === 0) {
        zinesh_escrow_memory_emit(
            $roomId,
            'contract_created',
            $uid,
            $role,
            $basePayload,
            ['idempotency_key' => $roomId . ':contract_created:v' . $versionNumber]
        );
        zinesh_escrow_memory_emit(
            $roomId,
            'terms_proposed',
            $uid,
            $role,
            $basePayload,
            ['idempotency_key' => $roomId . ':terms_proposed:v' . $versionNumber]
        );
        return;
    }

    if ($eventKind === 'counter_offer') {
        zinesh_escrow_memory_emit(
            $roomId,
            'counter_offer_created',
            $uid,
            $role,
            array_merge($basePayload, ['reason' => $extra['reason'] ?? 'counter_offer']),
            ['idempotency_key' => $roomId . ':counter_offer:v' . $versionNumber]
        );
    }

    zinesh_escrow_memory_emit(
        $roomId,
        'terms_updated',
        $uid,
        $role,
        $basePayload,
        ['idempotency_key' => $roomId . ':terms_updated:v' . $versionNumber]
    );
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_terms_rejected(array $room, array $actor, string $reason = ''): void
{
    $roomId = (string)($room['id'] ?? '');
    $uid = (string)($actor['uid'] ?? '');
    $latest = zinesh_contract_version_latest($roomId);
    zinesh_escrow_memory_emit(
        $roomId,
        'terms_rejected',
        $uid,
        'worker',
        [
            'version' => $latest ? (int)($latest['version_number'] ?? 0) : null,
            'reason' => $reason,
            'changed_by' => 'worker',
        ],
        ['idempotency_key' => $roomId . ':terms_rejected:' . date('Y-m-d\TH:i')]
    );
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_changes_requested(array $room, array $actor, string $note): void
{
    $roomId = (string)($room['id'] ?? '');
    $uid = (string)($actor['uid'] ?? '');
    $latest = zinesh_contract_version_latest($roomId);
    zinesh_escrow_memory_emit(
        $roomId,
        'changes_requested',
        $uid,
        'worker',
        [
            'version' => $latest ? (int)($latest['version_number'] ?? 0) : null,
            'note' => $note,
            'changed_by' => 'worker',
            'reason' => 'scope_change',
        ],
        ['idempotency_key' => $roomId . ':changes_requested:' . substr(hash('sha256', $note), 0, 12)]
    );
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_terms_accepted(array $room, array $actor, string $acceptedByRole): void
{
    $roomId = (string)($room['id'] ?? '');
    $uid = (string)($actor['uid'] ?? '');
    $latest = zinesh_contract_version_latest($roomId);
    $versionNumber = $latest ? (int)($latest['version_number'] ?? 0) : 0;
    $amount = round((float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2);
    $lockedAt = (string)($room['lockedAt'] ?? date('c'));
    $txId = $roomId . ':lock:' . $lockedAt;

    if ($versionNumber > 0) {
        zinesh_contract_version_mark_accepted($roomId, $versionNumber);
    }

    $payload = [
        'version' => $versionNumber > 0 ? $versionNumber : null,
        'amount_try' => $amount,
        'currency' => 'TRY',
        'transaction_id' => $txId,
        'accepted_by' => $acceptedByRole,
    ];

    zinesh_escrow_memory_emit(
        $roomId,
        'terms_accepted',
        $uid,
        $acceptedByRole,
        $payload,
        ['idempotency_key' => $roomId . ':terms_accepted:' . $txId]
    );
    zinesh_escrow_memory_emit(
        $roomId,
        'contract_finalized',
        $uid,
        $acceptedByRole,
        $payload,
        ['idempotency_key' => $roomId . ':contract_finalized:' . $txId]
    );
    zinesh_escrow_memory_emit(
        $roomId,
        'escrow_funded',
        (string)($room['employerUid'] ?? $uid),
        'employer',
        $payload,
        ['idempotency_key' => $roomId . ':escrow_funded:' . $txId]
    );
    zinesh_escrow_memory_emit(
        $roomId,
        'escrow_locked',
        (string)($room['employerUid'] ?? $uid),
        'employer',
        array_merge($payload, [
            'collateral_try' => round((float)($room['workerLockedTry'] ?? 0), 2),
        ]),
        ['idempotency_key' => $roomId . ':escrow_locked:' . $txId]
    );
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_release_requested(array $room, array $actor, string $role): void
{
    $roomId = (string)($room['id'] ?? '');
    zinesh_escrow_memory_emit(
        $roomId,
        'escrow_release_requested',
        (string)($actor['uid'] ?? ''),
        $role,
        [
            'amount_try' => round((float)($room['employerLockedTry'] ?? 0), 2),
            'currency' => 'TRY',
            'employer_confirmed' => !empty($room['employerConfirmedComplete']),
            'worker_confirmed' => !empty($room['workerConfirmedComplete']),
        ],
        ['idempotency_key' => $roomId . ':release_requested:both_confirmed'],
    );
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_settlement_started(array $room): void
{
    $roomId = (string)($room['id'] ?? '');
    $settlingAt = (string)($room['settlingAt'] ?? date('c'));
    zinesh_escrow_memory_emit(
        $roomId,
        'settlement_started',
        'system',
        'system',
        [
            'amount_try' => round((float)($room['employerLockedTry'] ?? 0), 2),
            'currency' => 'TRY',
            'transaction_id' => $roomId . ':settle:' . $settlingAt,
        ],
        ['idempotency_key' => $roomId . ':settlement_started:' . $settlingAt]
    );
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_settlement_completed(array $room): void
{
    $roomId = (string)($room['id'] ?? '');
    $completedAt = (string)($room['completedAt'] ?? date('c'));
    $settlingAt = (string)($room['settlingAt'] ?? '');
    $durationSec = null;
    if ($settlingAt !== '') {
        $start = strtotime($settlingAt);
        $end = strtotime($completedAt);
        if ($start !== false && $end !== false && $end >= $start) {
            $durationSec = $end - $start;
        }
    }
    zinesh_escrow_memory_emit(
        $roomId,
        'settlement_completed',
        'system',
        'system',
        [
            'amount_try' => round((float)($room['employerLockedTry'] ?? $room['agreedAmountTry'] ?? 0), 2),
            'payout_try' => round((float)($room['payoutTry'] ?? 0), 2),
            'commission_try' => round((float)($room['commissionTry'] ?? 0), 2),
            'currency' => 'TRY',
            'transaction_id' => $roomId . ':settled:' . $completedAt,
            'duration_sec' => $durationSec,
        ],
        ['idempotency_key' => $roomId . ':settlement_completed:' . $completedAt]
    );
}

function zinesh_escrow_memory_on_settlement_failed(string $roomId, string $error): void
{
    zinesh_escrow_memory_emit(
        $roomId,
        'settlement_failed',
        'system',
        'system',
        ['error' => function_exists('mb_substr') ? mb_substr($error, 0, 500) : substr($error, 0, 500)],
        ['idempotency_key' => $roomId . ':settlement_failed:' . substr(hash('sha256', $error), 0, 12)]
    );
}

function zinesh_escrow_memory_on_recovered(string $roomId, string $action): void
{
    zinesh_escrow_memory_emit(
        $roomId,
        'escrow_recovered',
        'system',
        'system',
        ['recovery_action' => $action],
        ['idempotency_key' => $roomId . ':recovered:' . $action . ':' . date('Y-m-d\TH:i')]
    );
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_dispute_opened(array $room, array $actor, float $deposit, string $depositSource): void
{
    $roomId = (string)($room['id'] ?? '');
    $uid = (string)($actor['uid'] ?? '');
    $role = $uid === (string)($room['employerUid'] ?? '') ? 'employer' : 'worker';
    $filedAt = is_array($room['dispute'] ?? null) ? (string)($room['dispute']['filedAt'] ?? date('c')) : date('c');
    $txId = $roomId . ':dispute:' . $filedAt;

    zinesh_escrow_memory_emit(
        $roomId,
        'dispute_opened',
        $uid,
        $role,
        [
            'deposit_try' => $deposit,
            'currency' => 'TRY',
            'transaction_id' => $txId,
            'deposit_source' => $depositSource,
        ],
        ['idempotency_key' => $roomId . ':dispute_opened:' . $txId]
    );

    if ($deposit > 1e-9) {
        zinesh_escrow_memory_emit(
            $roomId,
            'dispute_deposit_paid',
            $uid,
            $role,
            [
                'amount_try' => $deposit,
                'currency' => 'TRY',
                'transaction_id' => $txId,
                'actor' => $uid,
                'deposit_source' => $depositSource,
            ],
            ['idempotency_key' => $roomId . ':dispute_deposit_paid:' . $txId]
        );
    }
}

/** @param array<string,mixed> $room */
function zinesh_escrow_memory_on_dispute_resolved(array $room, string $fault, string $disposition, float $deposit): void
{
    $roomId = (string)($room['id'] ?? '');
    $resolvedAt = is_array($room['dispute'] ?? null)
        ? (string)($room['dispute']['resolvedAt'] ?? date('c'))
        : date('c');
    $txId = $roomId . ':dispute_resolved:' . $resolvedAt;

    zinesh_escrow_memory_emit(
        $roomId,
        'dispute_resolved',
        'system',
        'system',
        [
            'fault' => $fault,
            'deposit_disposition' => $disposition,
            'deposit_try' => $deposit,
            'currency' => 'TRY',
            'transaction_id' => $txId,
        ],
        ['idempotency_key' => $roomId . ':dispute_resolved:' . $txId]
    );

    if ($deposit <= 1e-9) {
        return;
    }

    if (in_array($disposition, ['refund_filer', 'split'], true)) {
        zinesh_escrow_memory_emit(
            $roomId,
            'dispute_deposit_refunded',
            'system',
            'system',
            [
                'amount_try' => $deposit,
                'currency' => 'TRY',
                'disposition' => $disposition,
                'transaction_id' => $txId,
            ],
            ['idempotency_key' => $roomId . ':dispute_deposit_refunded:' . $txId]
        );
    } elseif ($disposition === 'forfeit_to_peer') {
        zinesh_escrow_memory_emit(
            $roomId,
            'dispute_deposit_forfeited',
            'system',
            'system',
            [
                'amount_try' => $deposit,
                'currency' => 'TRY',
                'transaction_id' => $txId,
            ],
            ['idempotency_key' => $roomId . ':dispute_deposit_forfeited:' . $txId]
        );
    }
}

/** @return list<array<string,mixed>> */
function zinesh_escrow_room_timeline(string $roomId): array
{
    $events = zinesh_domain_events_for_room($roomId);
    $out = [];
    foreach ($events as $event) {
        $type = (string)($event['event_type'] ?? '');
        $role = (string)($event['actor_role'] ?? '');
        $payload = is_array($event['payload_json'] ?? null) ? $event['payload_json'] : [];
        $out[] = [
            'id' => (string)($event['id'] ?? ''),
            'event' => $type,
            'actor' => $role,
            'actor_label' => zinesh_escrow_memory_role_label($role),
            'date' => (string)($event['created_at'] ?? ''),
            'description' => ZINESH_ESCROW_TIMELINE_LABELS[$type] ?? $type,
            'payload' => $payload,
        ];
    }
    return $out;
}

/** @return array<string,mixed> */
function zinesh_escrow_room_ai_context(string $roomId): array
{
    $versions = zinesh_contract_versions_for_room($roomId);
    $accepted = zinesh_contract_version_accepted($roomId);
    $events = zinesh_domain_events_for_room($roomId);

    $stats = [
        'terms_proposed_count' => 0,
        'terms_rejected_count' => 0,
        'changes_requested_count' => 0,
        'counter_offer_count' => 0,
        'dispute_opened_count' => 0,
        'dispute_resolved_count' => 0,
    ];
    foreach ($events as $event) {
        $type = (string)($event['event_type'] ?? '');
        if ($type === 'terms_proposed' || $type === 'terms_updated' || $type === 'contract_created') {
            $stats['terms_proposed_count']++;
        } elseif ($type === 'terms_rejected') {
            $stats['terms_rejected_count']++;
        } elseif ($type === 'changes_requested') {
            $stats['changes_requested_count']++;
        } elseif ($type === 'counter_offer_created') {
            $stats['counter_offer_count']++;
        } elseif ($type === 'dispute_opened') {
            $stats['dispute_opened_count']++;
        } elseif ($type === 'dispute_resolved') {
            $stats['dispute_resolved_count']++;
        }
    }

    return [
        'room_id' => $roomId,
        'contract_versions' => $versions,
        'accepted_contract_version' => $accepted,
        'latest_contract_version' => zinesh_contract_version_latest($roomId),
        'behavior_stats' => $stats,
        'event_count' => count($events),
    ];
}
