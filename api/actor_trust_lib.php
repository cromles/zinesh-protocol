<?php
declare(strict_types=1);

/**
 * Actor Trust Aggregation v0.1 — kullanıcı bazlı gözlemlenen davranış özeti.
 * Salt okunur; yazma/cache/event üretimi yok. Karar etiketi vermez.
 */
require_once __DIR__ . '/trust_intelligence_lib.php';
require_once __DIR__ . '/escrow_room_lib.php';

const ZINESH_ACTOR_TRUST_VERSION = '0.1';

/** @return list<string> */
function zinesh_actor_trust_room_ids(string $actorId): array
{
    $actorId = trim($actorId);
    if ($actorId === '') {
        return [];
    }

    $ids = [];
    foreach (zinesh_escrow_rooms_load() as $room) {
        if (!is_array($room)) {
            continue;
        }
        $employer = (string)($room['employerUid'] ?? '');
        $worker = (string)($room['workerUid'] ?? '');
        if ($employer !== $actorId && $worker !== $actorId) {
            continue;
        }
        $roomId = (string)($room['id'] ?? '');
        if ($roomId !== '') {
            $ids[] = $roomId;
        }
    }

    return array_values(array_unique($ids));
}

/** @param list<int|float> $values */
function zinesh_actor_trust_average(array $values): float
{
    if ($values === []) {
        return 0.0;
    }
    return round(array_sum($values) / count($values), 2);
}

/** @return array<string,mixed> */
function zinesh_actor_trust_empty(string $actorId): array
{
    return [
        'trust_version' => ZINESH_ACTOR_TRUST_VERSION,
        'actor_id' => $actorId,
        'metrics' => [
            'transactions' => 0,
            'successful_settlements' => 0,
            'disputes' => 0,
            'behavior' => [
                'cooperation' => 0.0,
                'conflict' => 0.0,
                'reliability' => 0.0,
            ],
            'history' => [
                'average_completion_time' => 0,
                'average_negotiation_rounds' => 0.0,
            ],
        ],
    ];
}

/**
 * Kullanıcının tüm escrow odalarından toplu gözlem metrikleri (salt okunur).
 *
 * @return array<string,mixed>
 */
function zinesh_actor_trust_aggregate(string $actorId): array
{
    $actorId = trim($actorId);
    if ($actorId === '') {
        return zinesh_actor_trust_empty('');
    }

    try {
        $roomIds = zinesh_actor_trust_room_ids($actorId);
        if ($roomIds === []) {
            return zinesh_actor_trust_empty($actorId);
        }

        $transactions = 0;
        $successfulSettlements = 0;
        $disputes = 0;
        $completionTimes = [];
        $negotiationRounds = [];
        $cooperationSignals = [];
        $conflictSignals = [];
        $reliabilitySignals = [];

        foreach ($roomIds as $roomId) {
            $package = zinesh_trust_intelligence_metrics($roomId);
            $metrics = is_array($package['metrics'] ?? null) ? $package['metrics'] : [];
            $negotiation = is_array($metrics['negotiation'] ?? null) ? $metrics['negotiation'] : [];
            $settlement = is_array($metrics['settlement'] ?? null) ? $metrics['settlement'] : [];
            $behavior = is_array($metrics['behavior'] ?? null) ? $metrics['behavior'] : [];
            $time = is_array($metrics['time'] ?? null) ? $metrics['time'] : [];

            $transactions++;
            if (!empty($settlement['successful_settlement'])) {
                $successfulSettlements++;
            }
            $disputes += (int)($settlement['dispute_count'] ?? 0);

            $duration = (int)($time['duration_seconds'] ?? 0);
            if ($duration > 0) {
                $completionTimes[] = $duration;
            }

            $negotiationRounds[] = (int)($negotiation['negotiation_rounds'] ?? 0);
            $cooperationSignals[] = (int)($behavior['cooperation_signal'] ?? 0);
            $conflictSignals[] = (int)($behavior['conflict_signal'] ?? 0);
            $reliabilitySignals[] = (int)($behavior['reliability_signal'] ?? 0);
        }

        return [
            'trust_version' => ZINESH_ACTOR_TRUST_VERSION,
            'actor_id' => $actorId,
            'metrics' => [
                'transactions' => $transactions,
                'successful_settlements' => $successfulSettlements,
                'disputes' => $disputes,
                'behavior' => [
                    'cooperation' => zinesh_actor_trust_average($cooperationSignals),
                    'conflict' => zinesh_actor_trust_average($conflictSignals),
                    'reliability' => zinesh_actor_trust_average($reliabilitySignals),
                ],
                'history' => [
                    'average_completion_time' => (int) round(zinesh_actor_trust_average($completionTimes)),
                    'average_negotiation_rounds' => zinesh_actor_trust_average($negotiationRounds),
                ],
            ],
        ];
    } catch (Throwable $e) {
        error_log('zinesh_actor_trust_aggregate: ' . $e->getMessage());
        return zinesh_actor_trust_empty($actorId);
    }
}
