<?php
declare(strict_types=1);

/**
 * Zinesh domain event store (append-only, AI/timeline hazırlığı).
 * Marketing frontend eventleri (events_lib.php) ile karıştırılmamalıdır.
 */
const ZINESH_EVENTS_FILE = 'zinesh_events.json';
const ZINESH_EVENTS_MAX_ROWS = 100000;

/** @return list<string> */
function zinesh_domain_event_types(): array
{
    return [
        'contract_created',
        'terms_proposed',
        'terms_updated',
        'terms_rejected',
        'changes_requested',
        'counter_offer_created',
        'terms_accepted',
        'contract_finalized',
        'escrow_funded',
        'escrow_locked',
        'escrow_release_requested',
        'settlement_started',
        'settlement_completed',
        'settlement_failed',
        'escrow_recovered',
        'dispute_opened',
        'dispute_deposit_paid',
        'dispute_resolved',
        'dispute_deposit_refunded',
        'dispute_deposit_forfeited',
    ];
}

function zinesh_domain_event_new_id(): string
{
    return 'evt-' . substr(hash('sha256', microtime(true) . random_bytes(8)), 0, 16);
}

/** @return list<array<string,mixed>> */
function zinesh_domain_events_load(): array
{
    $rows = zinesh_json_read(ZINESH_EVENTS_FILE);
    return is_array($rows) ? $rows : [];
}

/** @return list<array<string,mixed>> */
function zinesh_domain_events_for_room(string $roomId, ?int $limit = null): array
{
    $out = [];
    foreach (zinesh_domain_events_load() as $row) {
        if (!is_array($row) || (string)($row['room_id'] ?? '') !== $roomId) {
            continue;
        }
        $out[] = $row;
    }
    usort($out, static fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
    if ($limit !== null && $limit > 0 && count($out) > $limit) {
        $out = array_slice($out, -$limit);
    }
    return $out;
}

function zinesh_domain_event_find_duplicate(string $roomId, string $idempotencyKey): ?array
{
    if ($idempotencyKey === '') {
        return null;
    }
    foreach (zinesh_domain_events_load() as $row) {
        if (!is_array($row) || (string)($row['room_id'] ?? '') !== $roomId) {
            continue;
        }
        $meta = is_array($row['metadata_json'] ?? null) ? $row['metadata_json'] : [];
        if ((string)($meta['idempotency_key'] ?? '') === $idempotencyKey) {
            return $row;
        }
    }
    return null;
}

/**
 * Append-only domain event. Duplicate idempotency_key → mevcut kayıt döner.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $metadata
 * @return array<string,mixed>|null
 */
function zinesh_domain_event_emit(
    string $roomId,
    string $eventType,
    string $actorId,
    ?string $actorRole,
    array $payload = [],
    array $metadata = []
): ?array {
    if ($roomId === '' || !in_array($eventType, zinesh_domain_event_types(), true)) {
        error_log('zinesh_domain_event_emit: invalid room or type: ' . $eventType);
        return null;
    }

    $idempotencyKey = trim((string)($metadata['idempotency_key'] ?? ''));
    if ($idempotencyKey !== '') {
        $dup = zinesh_domain_event_find_duplicate($roomId, $idempotencyKey);
        if ($dup !== null) {
            return $dup;
        }
    }

    $created = null;
    zinesh_json_atomic(ZINESH_EVENTS_FILE, static function (array &$rows) use (
        $roomId,
        $eventType,
        $actorId,
        $actorRole,
        $payload,
        $metadata,
        $idempotencyKey,
        &$created
    ) {
        if ($idempotencyKey !== '') {
            foreach ($rows as $row) {
                if (!is_array($row) || (string)($row['room_id'] ?? '') !== $roomId) {
                    continue;
                }
                $meta = is_array($row['metadata_json'] ?? null) ? $row['metadata_json'] : [];
                if ((string)($meta['idempotency_key'] ?? '') === $idempotencyKey) {
                    $created = $row;
                    return false;
                }
            }
        }

        $event = [
            'id' => zinesh_domain_event_new_id(),
            'room_id' => $roomId,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? '',
            'created_at' => date('c'),
            'payload_json' => $payload,
            'metadata_json' => $metadata,
        ];
        $rows[] = $event;
        if (count($rows) > ZINESH_EVENTS_MAX_ROWS) {
            $rows = array_slice($rows, -ZINESH_EVENTS_MAX_ROWS);
        }
        $created = $event;
        return true;
    });

    return $created;
}
