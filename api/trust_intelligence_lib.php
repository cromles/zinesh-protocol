<?php
declare(strict_types=1);

/**
 * Trust Intelligence Engine v0.1 — salt okunur gözlem/metrik katmanı.
 * Escrow motorundan izole; yazma/cache yok.
 */
require_once __DIR__ . '/escrow_memory_lib.php';
require_once __DIR__ . '/ai_context_lib.php';

const ZINESH_TRUST_INTELLIGENCE_VERSION = '0.1';

/** @param list<array<string,mixed>> $events */
function zinesh_trust_first_event_at(array $events, string $eventType): ?string
{
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        if ((string)($event['event_type'] ?? '') === $eventType) {
            $at = (string)($event['created_at'] ?? '');
            if ($at !== '') {
                return $at;
            }
        }
    }
    return null;
}

/** @param list<array<string,mixed>> $events */
function zinesh_trust_has_event(array $events, string $eventType): bool
{
    foreach ($events as $event) {
        if (is_array($event) && (string)($event['event_type'] ?? '') === $eventType) {
            return true;
        }
    }
    return false;
}

/** @param array<string,mixed> $context */
function zinesh_trust_negotiation_metrics(array $context): array
{
    $stats = is_array($context['behavior_stats'] ?? null) ? $context['behavior_stats'] : [];
    $proposed = (int)($stats['terms_proposed_count'] ?? 0);
    $rejected = (int)($stats['terms_rejected_count'] ?? 0);
    $changes = (int)($stats['changes_requested_count'] ?? 0);
    $counter = (int)($stats['counter_offer_count'] ?? 0);

    $rounds = $counter + $changes + $rejected + max(0, $proposed - 1);

    return [
        'terms_proposed_count' => $proposed,
        'terms_rejected_count' => $rejected,
        'changes_requested_count' => $changes,
        'counter_offer_count' => $counter,
        'negotiation_rounds' => $rounds,
    ];
}

/**
 * @param array<string,mixed> $context
 * @param list<array<string,mixed>> $events
 * @param array<string,mixed>|null $room
 */
function zinesh_trust_settlement_metrics(array $context, array $events, ?array $room): array
{
    $stats = is_array($context['behavior_stats'] ?? null) ? $context['behavior_stats'] : [];
    $disputeCount = (int)($stats['dispute_opened_count'] ?? 0);
    $disputeResolvedCount = (int)($stats['dispute_resolved_count'] ?? 0);

    $roomStatus = is_array($room) ? (string)($room['status'] ?? '') : '';
    $hasSettlementCompleted = zinesh_trust_has_event($events, 'settlement_completed');
    $hasSettlementFailed = zinesh_trust_has_event($events, 'settlement_failed');

    $successfulSettlement = $hasSettlementCompleted
        || in_array($roomStatus, ['completed', 'resolved'], true);

    $disputeResolved = $disputeResolvedCount > 0
        || ($disputeCount > 0 && $roomStatus === 'resolved');

    $completionStatus = $roomStatus !== '' ? $roomStatus : 'unknown';
    if ($completionStatus === 'unknown' && $hasSettlementCompleted) {
        $completionStatus = 'completed';
    }
    if ($hasSettlementFailed) {
        $completionStatus = 'settlement_failed';
    }
    if ($disputeCount > 0 && $roomStatus === 'disputed') {
        $completionStatus = 'disputed';
    }

    return [
        'successful_settlement' => $successfulSettlement,
        'dispute_count' => $disputeCount,
        'dispute_resolved' => $disputeResolved,
        'completion_status' => $completionStatus,
    ];
}

/**
 * @param array<string,mixed> $context
 * @param list<array<string,mixed>> $events
 */
function zinesh_trust_behavior_signals(array $context, array $events): array
{
    $stats = is_array($context['behavior_stats'] ?? null) ? $context['behavior_stats'] : [];
    $accepted = is_array($context['accepted_contract_version'] ?? null)
        ? $context['accepted_contract_version']
        : null;

    $rejected = (int)($stats['terms_rejected_count'] ?? 0);
    $changes = (int)($stats['changes_requested_count'] ?? 0);
    $counter = (int)($stats['counter_offer_count'] ?? 0);
    $disputes = (int)($stats['dispute_opened_count'] ?? 0);
    $disputeResolved = (int)($stats['dispute_resolved_count'] ?? 0);

    $settled = zinesh_trust_has_event($events, 'settlement_completed');
    $failed = zinesh_trust_has_event($events, 'settlement_failed');

    $cooperation = 50;
    if ($accepted !== null) {
        $cooperation += 20;
    }
    if ($settled) {
        $cooperation += 15;
    }
    $cooperation -= min(35, $rejected * 12);
    $cooperation -= min(25, $disputes * 18);
    $cooperation = max(0, min(100, $cooperation));

    $conflict = min(100, $rejected * 14 + $changes * 6 + $counter * 5 + $disputes * 22);

    $reliability = 45;
    if ($settled) {
        $reliability += 35;
    }
    if ($accepted !== null) {
        $reliability += 10;
    }
    if ($failed) {
        $reliability -= 30;
    }
    if ($disputes > 0 && $disputeResolved === 0) {
        $reliability -= 20;
    }
    if ($disputes > 0 && $disputeResolved > 0) {
        $reliability += 5;
    }
    $reliability = max(0, min(100, $reliability));

    return [
        'cooperation_signal' => $cooperation,
        'conflict_signal' => $conflict,
        'reliability_signal' => $reliability,
    ];
}

/**
 * @param list<array<string,mixed>> $events
 * @param array<string,mixed>|null $room
 */
function zinesh_trust_time_metrics(array $events, ?array $room): array
{
    $creation = zinesh_trust_first_event_at($events, 'contract_created');
    if ($creation === null) {
        $creation = zinesh_trust_first_event_at($events, 'terms_proposed');
    }
    if ($creation === null && is_array($room)) {
        $createdAt = (string)($room['createdAt'] ?? '');
        $creation = $createdAt !== '' ? $createdAt : null;
    }

    $acceptance = zinesh_trust_first_event_at($events, 'terms_accepted');
    if ($acceptance === null && is_array($room)) {
        $lockedAt = (string)($room['lockedAt'] ?? '');
        $acceptance = $lockedAt !== '' ? $lockedAt : null;
    }

    $settlement = zinesh_trust_first_event_at($events, 'settlement_completed');
    if ($settlement === null && is_array($room)) {
        $completedAt = (string)($room['completedAt'] ?? '');
        $settlement = $completedAt !== '' ? $completedAt : null;
    }

    $durationSeconds = 0;
    if ($creation !== null && $settlement !== null) {
        $start = strtotime($creation);
        $end = strtotime($settlement);
        if ($start !== false && $end !== false && $end >= $start) {
            $durationSeconds = $end - $start;
        }
    }

    return [
        'contract_creation_time' => $creation,
        'acceptance_time' => $acceptance,
        'settlement_time' => $settlement,
        'duration_seconds' => $durationSeconds,
    ];
}

/**
 * Escrow odası için Trust Intelligence v0.1 metrik paketi (salt okunur).
 *
 * @return array<string,mixed>
 */
function zinesh_trust_intelligence_metrics(string $roomId): array
{
    $roomId = trim($roomId);
    if ($roomId === '') {
        return zinesh_trust_intelligence_empty('');
    }

    try {
        $context = zinesh_escrow_room_ai_context($roomId);
        $events = zinesh_domain_events_for_room($roomId);
        $room = zinesh_ai_context_room_snapshot($roomId);

        return [
            'trust_version' => ZINESH_TRUST_INTELLIGENCE_VERSION,
            'room_id' => $roomId,
            'metrics' => [
                'negotiation' => zinesh_trust_negotiation_metrics($context),
                'settlement' => zinesh_trust_settlement_metrics($context, $events, $room),
                'behavior' => zinesh_trust_behavior_signals($context, $events),
                'time' => zinesh_trust_time_metrics($events, $room),
            ],
            'generated_at' => date('c'),
        ];
    } catch (Throwable $e) {
        error_log('zinesh_trust_intelligence_metrics: ' . $e->getMessage());
        return zinesh_trust_intelligence_empty($roomId);
    }
}

/** @return array<string,mixed> */
function zinesh_trust_intelligence_empty(string $roomId): array
{
    return [
        'trust_version' => ZINESH_TRUST_INTELLIGENCE_VERSION,
        'room_id' => $roomId,
        'metrics' => [
            'negotiation' => [
                'terms_proposed_count' => 0,
                'terms_rejected_count' => 0,
                'changes_requested_count' => 0,
                'counter_offer_count' => 0,
                'negotiation_rounds' => 0,
            ],
            'settlement' => [
                'successful_settlement' => false,
                'dispute_count' => 0,
                'dispute_resolved' => false,
                'completion_status' => 'unknown',
            ],
            'behavior' => [
                'cooperation_signal' => 0,
                'conflict_signal' => 0,
                'reliability_signal' => 0,
            ],
            'time' => [
                'contract_creation_time' => null,
                'acceptance_time' => null,
                'settlement_time' => null,
                'duration_seconds' => 0,
            ],
        ],
        'generated_at' => date('c'),
    ];
}
