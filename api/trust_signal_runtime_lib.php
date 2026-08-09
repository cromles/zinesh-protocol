<?php
declare(strict_types=1);

/**
 * Trust Signal Runtime v0.1 — Metric → Signal (salt okunur).
 * Architecture Freeze v1.0: Event → Metric → Signal (metric zorunlu).
 * Context, Risk Observation, AI yok (S3.1B kapsamı).
 */
require_once __DIR__ . '/trust_signal_catalog_lib.php';
require_once __DIR__ . '/trust_intelligence_lib.php';
require_once __DIR__ . '/zinesh_domain_events_lib.php';
require_once __DIR__ . '/ai_context_lib.php';
require_once __DIR__ . '/actor_trust_lib.php';

const ZINESH_TRUST_SIGNAL_RUNTIME_VERSION = '0.1';

/** @param list<array<string,mixed>> $events */
function zinesh_trust_signal_events_of_types(array $events, array $types): array
{
    $out = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $type = (string)($event['event_type'] ?? '');
        if ($type === '' || ($types !== [] && !in_array($type, $types, true))) {
            continue;
        }
        $out[] = $event;
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $events
 * @return list<array<string,mixed>>
 */
function zinesh_trust_signal_event_sources_summary(array $events, array $types): array
{
    $counts = [];
    foreach (zinesh_trust_signal_events_of_types($events, $types) as $event) {
        $type = (string)($event['event_type'] ?? '');
        if ($type === '') {
            continue;
        }
        if (!isset($counts[$type])) {
            $counts[$type] = [
                'event_type' => $type,
                'created_at' => (string)($event['created_at'] ?? ''),
                'count' => 0,
            ];
        }
        $counts[$type]['count']++;
        $at = (string)($event['created_at'] ?? '');
        if ($at !== '' && ($counts[$type]['created_at'] === '' || strcmp($at, $counts[$type]['created_at']) < 0)) {
            $counts[$type]['created_at'] = $at;
        }
    }
    return array_values($counts);
}

/** @param list<array<string,mixed>> $events */
function zinesh_trust_signal_first_event_ts(array $events, array $types): ?int
{
    $min = null;
    foreach (zinesh_trust_signal_events_of_types($events, $types) as $event) {
        $ts = strtotime((string)($event['created_at'] ?? ''));
        if ($ts === false) {
            continue;
        }
        if ($min === null || $ts < $min) {
            $min = $ts;
        }
    }
    return $min;
}

/** @param list<array<string,mixed>> $events */
function zinesh_trust_signal_last_event_ts(array $events, array $types): ?int
{
    $max = null;
    foreach (zinesh_trust_signal_events_of_types($events, $types) as $event) {
        $ts = strtotime((string)($event['created_at'] ?? ''));
        if ($ts === false) {
            continue;
        }
        if ($max === null || $ts > $max) {
            $max = $ts;
        }
    }
    return $max;
}

/** @param list<array<string,mixed>> $events */
function zinesh_trust_signal_max_silence_gap_seconds(array $events): int
{
    $timestamps = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $ts = strtotime((string)($event['created_at'] ?? ''));
        if ($ts !== false) {
            $timestamps[] = $ts;
        }
    }
    if (count($timestamps) < 2) {
        return 0;
    }
    sort($timestamps);
    $maxGap = 0;
    for ($i = 1, $n = count($timestamps); $i < $n; $i++) {
        $gap = $timestamps[$i] - $timestamps[$i - 1];
        if ($gap > $maxGap) {
            $maxGap = $gap;
        }
    }
    return $maxGap;
}

function zinesh_trust_signal_iso_ts(string $iso): ?int
{
    if ($iso === '') {
        return null;
    }
    $ts = strtotime($iso);
    return $ts === false ? null : $ts;
}

/**
 * Kabul sonrası sözleşme değişikliği — contract version metrikleri (event emit gate değil).
 *
 * @param array<string,mixed>|null $accepted
 * @param list<array<string,mixed>> $versions
 */
function zinesh_trust_signal_post_acceptance_from_versions(?array $accepted, array $versions): int
{
    if ($accepted === null) {
        return -1;
    }
    $acceptNum = (int)($accepted['version_number'] ?? 0);
    $count = 0;
    foreach ($versions as $version) {
        if (!is_array($version)) {
            continue;
        }
        $num = (int)($version['version_number'] ?? 0);
        if ($num > $acceptNum) {
            $count++;
        }
    }
    return $count;
}

/**
 * Emit kararları için birleşik metrik snapshot (C5: Event → Metric burada; Signal yalnızca okur).
 *
 * @param array<string,mixed> $baseMetrics metricsPkg['metrics']
 * @param array<string,mixed>|null $room
 * @param array<string,mixed> $aiContext
 * @param array<string,mixed> $contractMetrics
 * @param list<array<string,mixed>> $events yalnızca activity.* türetimi (emit switch'e girmez)
 * @return array<string,mixed>
 */
function zinesh_trust_signal_runtime_emit_metrics(
    array $baseMetrics,
    ?array $room,
    array $aiContext,
    array $contractMetrics,
    array $events
): array {
    $neg = is_array($baseMetrics['negotiation'] ?? null) ? $baseMetrics['negotiation'] : [];
    $settlement = is_array($baseMetrics['settlement'] ?? null) ? $baseMetrics['settlement'] : [];
    $time = is_array($baseMetrics['time'] ?? null) ? $baseMetrics['time'] : [];

    $creationTs = zinesh_trust_signal_iso_ts((string)($time['contract_creation_time'] ?? ''));
    $acceptanceTs = zinesh_trust_signal_iso_ts((string)($time['acceptance_time'] ?? ''));
    $settlementTs = zinesh_trust_signal_iso_ts((string)($time['settlement_time'] ?? ''));
    $lockedTs = is_array($room)
        ? zinesh_trust_signal_iso_ts((string)($room['lockedAt'] ?? ''))
        : null;

    $agreementDuration = null;
    if ($creationTs !== null && $acceptanceTs !== null && $acceptanceTs >= $creationTs) {
        $agreementDuration = $acceptanceTs - $creationTs;
    }

    $negotiationEndTs = $acceptanceTs;
    if ($lockedTs !== null && ($negotiationEndTs === null || $lockedTs > $negotiationEndTs)) {
        $negotiationEndTs = $lockedTs;
    }
    $negotiationDuration = 0;
    if ($creationTs !== null && $negotiationEndTs !== null && $negotiationEndTs >= $creationTs) {
        $negotiationDuration = $negotiationEndTs - $creationTs;
    }

    $lockTsForSettle = $lockedTs ?? $acceptanceTs;
    $lockToSettlement = null;
    if ($lockTsForSettle !== null && $settlementTs !== null && $settlementTs >= $lockTsForSettle) {
        $lockToSettlement = $settlementTs - $lockTsForSettle;
    }

    $accepted = is_array($aiContext['accepted_contract_version'] ?? null)
        ? $aiContext['accepted_contract_version']
        : null;
    $versions = is_array($aiContext['contract_versions'] ?? null) ? $aiContext['contract_versions'] : [];

    return [
        'negotiation' => $neg,
        'settlement' => $settlement,
        'time' => $time,
        'contract' => array_merge($contractMetrics, [
            'post_acceptance_change_count' => zinesh_trust_signal_post_acceptance_from_versions($accepted, $versions),
            'has_accepted_contract' => $accepted !== null,
        ]),
        'activity' => [
            'max_silence_gap_seconds' => zinesh_trust_signal_max_silence_gap_seconds($events),
            'recovery_count' => count(zinesh_trust_signal_events_of_types($events, ['escrow_recovered'])),
        ],
        'derived_time' => [
            'agreement_duration_seconds' => $agreementDuration,
            'negotiation_duration_seconds' => $negotiationDuration,
            'lock_to_settlement_seconds' => $lockToSettlement,
        ],
        'derived_settlement' => [
            'settlement_failed_observed' => (string)($settlement['completion_status'] ?? '') === 'settlement_failed',
        ],
    ];
}

/** @param array<string,mixed> $aiContext */
function zinesh_trust_signal_contract_metrics(array $aiContext): array
{
    $versions = is_array($aiContext['contract_versions'] ?? null) ? $aiContext['contract_versions'] : [];
    $versionCount = count($versions);
    $amountChanges = 0;
    $prevAmount = null;
    foreach ($versions as $version) {
        if (!is_array($version)) {
            continue;
        }
        $amount = $version['amount_try'] ?? null;
        if ($amount === null) {
            continue;
        }
        $val = (float)$amount;
        if ($prevAmount !== null && abs($val - $prevAmount) > 1e-9) {
            $amountChanges++;
        }
        $prevAmount = $val;
    }
    return [
        'version_count' => $versionCount,
        'amount_change_count' => $amountChanges,
    ];
}

/** @param array<string,mixed>|null $accepted */
function zinesh_trust_signal_deadline_missed(?array $accepted, array $events): ?bool
{
    if ($accepted === null) {
        return null;
    }
    $structured = is_array($accepted['structured_terms_json'] ?? null)
        ? $accepted['structured_terms_json']
        : [];
    $deadlineRaw = (string)($structured['delivery_date'] ?? $structured['deadline'] ?? '');
    if ($deadlineRaw === '') {
        return null;
    }
    $deadlineTs = strtotime($deadlineRaw);
    if ($deadlineTs === false) {
        return null;
    }
    $settleTs = zinesh_trust_signal_last_event_ts($events, ['settlement_completed']);
    if ($settleTs !== null) {
        return $settleTs > $deadlineTs;
    }
    return null;
}

function zinesh_trust_signal_peer_pair_room_count(string $employerUid, string $workerUid): int
{
    if ($employerUid === '' || $workerUid === '') {
        return 0;
    }
    $rows = zinesh_json_read('escrow_rooms.json');
    if (!is_array($rows)) {
        return 0;
    }
    $count = 0;
    foreach ($rows as $room) {
        if (!is_array($room)) {
            continue;
        }
        $e = (string)($room['employerUid'] ?? '');
        $w = (string)($room['workerUid'] ?? '');
        if ($e === $employerUid && $w === $workerUid) {
            $status = (string)($room['status'] ?? '');
            if (in_array($status, ['completed', 'resolved', 'locked', 'settling'], true)) {
                $count++;
            }
        }
    }
    return $count;
}

/**
 * Evidence Completeness — Domain Model §9.
 *
 * @param int $eventCount
 * @param int|null $actorTransactions
 */
function zinesh_trust_signal_confidence_level(int $eventCount, ?int $actorTransactions = null): string
{
    if ($eventCount < 2) {
        return 'low';
    }
    if ($actorTransactions !== null && $actorTransactions < 3) {
        return 'low';
    }
    if ($eventCount >= 5 && ($actorTransactions === null || $actorTransactions >= 5)) {
        return 'high';
    }
    return 'medium';
}

/**
 * @param array<string,mixed> $def
 * @param array<string,mixed> $metricSources
 * @param list<array<string,mixed>> $eventSources
 */
function zinesh_trust_signal_build_emitted(
    string $signalId,
    array $def,
    string $description,
    array $metricSources,
    array $eventSources,
    string $confidence
): array {
    return [
        'signal_id' => $signalId,
        'title' => (string)($def['title'] ?? ''),
        'description' => $description,
        'metric_sources' => $metricSources,
        'event_sources' => $eventSources,
        'explanation' => [
            'chain' => 'signal → metric → event',
            'signal_id' => $signalId,
            'metrics' => $metricSources,
            'events' => $eventSources,
            'catalog_version' => ZINESH_TRUST_SIGNAL_CATALOG_VERSION,
        ],
        'confidence' => $confidence,
        'signal_version' => ZINESH_TRUST_SIGNAL_RUNTIME_VERSION,
        'emitted' => true,
    ];
}

/** @return array<string,mixed> */
function zinesh_trust_signal_runtime_empty(string $roomId): array
{
    return [
        'signal_runtime_version' => ZINESH_TRUST_SIGNAL_RUNTIME_VERSION,
        'catalog_version' => ZINESH_TRUST_SIGNAL_CATALOG_VERSION,
        'room_id' => $roomId,
        'actor_id' => null,
        'signals' => [],
    ];
}

/**
 * Oda için Trust Signal paketi (yalnızca emit edilen signal'lar).
 *
 * @return array<string,mixed>
 */
function zinesh_trust_signal_runtime_for_room(string $roomId, ?string $actorId = null): array
{
    $roomId = trim($roomId);
    if ($roomId === '') {
        return zinesh_trust_signal_runtime_empty('');
    }

    try {
        $metricsPkg = zinesh_trust_intelligence_metrics($roomId);
        $events = zinesh_domain_events_for_room($roomId);
        $aiContext = zinesh_escrow_room_ai_context($roomId);
        $room = zinesh_ai_context_room_snapshot($roomId);

        $neg = is_array($metricsPkg['metrics']['negotiation'] ?? null)
            ? $metricsPkg['metrics']['negotiation'] : [];
        $settlement = is_array($metricsPkg['metrics']['settlement'] ?? null)
            ? $metricsPkg['metrics']['settlement'] : [];
        $time = is_array($metricsPkg['metrics']['time'] ?? null)
            ? $metricsPkg['metrics']['time'] : [];

        $actorTrust = null;
        $actorMetrics = null;
        if ($actorId !== null && trim($actorId) !== '') {
            $actorTrust = zinesh_actor_trust_aggregate(trim($actorId));
            $actorMetrics = is_array($actorTrust['metrics'] ?? null) ? $actorTrust['metrics'] : [];
        } elseif (is_array($room)) {
            $employer = (string)($room['employerUid'] ?? '');
            if ($employer !== '') {
                $actorTrust = zinesh_actor_trust_aggregate($employer);
                $actorMetrics = is_array($actorTrust['metrics'] ?? null) ? $actorTrust['metrics'] : [];
                $actorId = $employer;
            }
        }

        $contractMetrics = zinesh_trust_signal_contract_metrics($aiContext);
        $accepted = is_array($aiContext['accepted_contract_version'] ?? null)
            ? $aiContext['accepted_contract_version'] : null;

        $employerUid = is_array($room) ? (string)($room['employerUid'] ?? '') : '';
        $workerUid = is_array($room) ? (string)($room['workerUid'] ?? '') : '';
        $peerPairCount = zinesh_trust_signal_peer_pair_room_count($employerUid, $workerUid);

        $baseMetrics = is_array($metricsPkg['metrics'] ?? null) ? $metricsPkg['metrics'] : [];
        $emitMetrics = zinesh_trust_signal_runtime_emit_metrics(
            $baseMetrics,
            is_array($room) ? $room : null,
            $aiContext,
            $contractMetrics,
            $events
        );
        $derivedTime = is_array($emitMetrics['derived_time'] ?? null) ? $emitMetrics['derived_time'] : [];
        $derivedSettlement = is_array($emitMetrics['derived_settlement'] ?? null) ? $emitMetrics['derived_settlement'] : [];
        $emitContract = is_array($emitMetrics['contract'] ?? null) ? $emitMetrics['contract'] : [];
        $emitActivity = is_array($emitMetrics['activity'] ?? null) ? $emitMetrics['activity'] : [];

        $definitions = zinesh_trust_signal_catalog_definitions();
        $emitted = [];
        $eventCount = count($events);
        $actorTx = is_array($actorMetrics) ? (int)($actorMetrics['transactions'] ?? 0) : null;

        foreach (zinesh_trust_signal_catalog_ids() as $signalId) {
            $def = $definitions[$signalId] ?? null;
            if ($def === null) {
                continue;
            }
            $types = is_array($def['event_types'] ?? null) ? $def['event_types'] : [];
            $eventSources = zinesh_trust_signal_event_sources_summary($events, $types);
            $confidence = zinesh_trust_signal_confidence_level($eventCount, $actorTx);

            switch ($signalId) {
                case 'SIG-NEG-001':
                    $rounds = (int)($neg['negotiation_rounds'] ?? 0);
                    if ($rounds >= ZINESH_SIG_NEG001_MIN_ROUNDS) {
                        $metricSources = [
                            'negotiation.negotiation_rounds' => $rounds,
                            'negotiation.terms_proposed_count' => (int)($neg['terms_proposed_count'] ?? 0),
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Bu odada {$rounds} müzakere turu kaydı var (revizyon/teklif aktivitesi).",
                            $metricSources,
                            $eventSources,
                            $confidence
                        );
                    }
                    break;

                case 'SIG-NEG-002':
                    $counter = (int)($neg['counter_offer_count'] ?? 0);
                    if ($counter >= ZINESH_SIG_NEG002_MIN_COUNTER) {
                        $metricSources = [
                            'negotiation.counter_offer_count' => $counter,
                            'negotiation.negotiation_rounds' => (int)($neg['negotiation_rounds'] ?? 0),
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Bu odada {$counter} karşı teklif kaydı var.",
                            $metricSources,
                            $eventSources,
                            $eventCount >= 2 ? 'high' : 'medium'
                        );
                    }
                    break;

                case 'SIG-NEG-003':
                    $rounds = (int)($neg['negotiation_rounds'] ?? 0);
                    $proposed = (int)($neg['terms_proposed_count'] ?? 0);
                    $hasAccepted = zinesh_trust_signal_first_event_ts($events, ['terms_accepted']) !== null;
                    if ($rounds <= ZINESH_SIG_NEG003_MAX_ROUNDS && $proposed >= 1 && $hasAccepted) {
                        $metricSources = ['negotiation.negotiation_rounds' => $rounds];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Müzakere turu sayısı düşük ({$rounds}).",
                            $metricSources,
                            $eventSources,
                            'high'
                        );
                    }
                    break;

                case 'SIG-TIME-001':
                    $delta = $derivedTime['agreement_duration_seconds'] ?? null;
                    if ($delta !== null && $delta >= 0 && $delta <= ZINESH_SIG_TIME001_MAX_SECONDS) {
                        $minutes = max(1, (int)round($delta / 60));
                        $metricSources = [
                            'time.contract_creation_time' => (string)($time['contract_creation_time'] ?? ''),
                            'time.acceptance_time' => (string)($time['acceptance_time'] ?? ''),
                            'time.agreement_duration_seconds' => $delta,
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Tekliften kabul'e yaklaşık {$minutes} dakika geçti.",
                            $metricSources,
                            $eventSources,
                            $delta > 0 ? 'medium' : 'low'
                        );
                    }
                    break;

                case 'SIG-TIME-002':
                    $rounds = (int)($neg['negotiation_rounds'] ?? 0);
                    $delta = (int)($derivedTime['negotiation_duration_seconds'] ?? 0);
                    $longByTime = $delta >= ZINESH_SIG_TIME002_MIN_SECONDS;
                    if ($longByTime || $rounds >= ZINESH_SIG_TIME002_MIN_ROUNDS) {
                        $metricSources = [
                            'negotiation.negotiation_rounds' => $rounds,
                            'time.negotiation_duration_seconds' => $delta,
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            $longByTime
                                ? 'Müzakere fazı uzun sürdü (kabul/kilitlenme öncesi süre üst eşikte).'
                                : "Müzakere turu sayısı yüksek ({$rounds}).",
                            $metricSources,
                            $eventSources,
                            'medium'
                        );
                    }
                    break;

                case 'SIG-TIME-003':
                    $delta = $derivedTime['lock_to_settlement_seconds'] ?? null;
                    if ($delta !== null && $delta >= ZINESH_SIG_TIME003_MIN_LOCK_TO_SETTLE) {
                        $days = max(1, (int)round($delta / 86400));
                        $metricSources = [
                            'time.duration_seconds' => (int)($time['duration_seconds'] ?? $delta),
                            'time.lock_to_settlement_seconds' => $delta,
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Kilitlenmeden tamamlamaya yaklaşık {$days} gün geçti.",
                            $metricSources,
                            $eventSources,
                            'medium'
                        );
                    }
                    break;

                case 'SIG-TIME-004':
                    $missed = zinesh_trust_signal_deadline_missed($accepted, $events);
                    if ($missed === true) {
                        $metricSources = [
                            'time.settlement_time' => (string)($time['settlement_time'] ?? ''),
                            'contract.deadline_missed' => true,
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            'Sözleşmede kayıtlı teslim tarihi tamamlamadan önce geçmiş.',
                            $metricSources,
                            $eventSources,
                            'high'
                        );
                    }
                    break;

                case 'SIG-DSP-001':
                    $disputes = (int)($settlement['dispute_count'] ?? 0);
                    if ($disputes >= 1) {
                        $metricSources = [
                            'settlement.dispute_count' => $disputes,
                            'settlement.dispute_resolved' => (bool)($settlement['dispute_resolved'] ?? false),
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Bu odada {$disputes} dispute kaydı var.",
                            $metricSources,
                            $eventSources,
                            $disputes >= 2 ? 'medium' : 'low'
                        );
                    }
                    break;

                case 'SIG-SET-001':
                    if (is_array($actorMetrics)) {
                        $tx = (int)($actorMetrics['transactions'] ?? 0);
                        $success = (int)($actorMetrics['successful_settlements'] ?? 0);
                        if ($tx >= ZINESH_SIG_SET001_MIN_TRANSACTIONS && $tx > 0) {
                            $ratio = round($success / $tx, 2);
                            if ($ratio >= ZINESH_SIG_SET001_MIN_SUCCESS_RATIO) {
                                $metricSources = [
                                    'actor.transactions' => $tx,
                                    'actor.successful_settlements' => $success,
                                    'actor.success_ratio' => $ratio,
                                ];
                                $emitted[] = zinesh_trust_signal_build_emitted(
                                    $signalId,
                                    $def,
                                    "Actor geçmişinde başarılı settlement oranı {$ratio} (n={$tx}).",
                                    $metricSources,
                                    $eventSources,
                                    $tx >= 10 ? 'high' : 'low'
                                );
                            }
                        }
                    }
                    break;

                case 'SIG-SET-002':
                    $hasLocked = zinesh_trust_signal_first_event_ts($events, ['escrow_locked']) !== null;
                    $hasCompleted = (bool)($settlement['successful_settlement'] ?? false);
                    $hasFailed = zinesh_trust_signal_first_event_ts($events, ['settlement_failed']) !== null;
                    if ($hasLocked && (!$hasCompleted || $hasFailed)) {
                        $metricSources = [
                            'settlement.successful_settlement' => $hasCompleted,
                            'settlement.completion_status' => (string)($settlement['completion_status'] ?? 'unknown'),
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            $hasFailed
                                ? 'Escrow kilitlendikten sonra tamamlama başarısızlığı gözlemlendi.'
                                : 'Escrow kilitlendi; tamamlama henüz gözlemlenmedi.',
                            $metricSources,
                            $eventSources,
                            'medium'
                        );
                    }
                    break;

                case 'SIG-SET-003':
                    if (($derivedSettlement['settlement_failed_observed'] ?? false) === true) {
                        $metricSources = [
                            'settlement.completion_status' => (string)($settlement['completion_status'] ?? 'settlement_failed'),
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            'Settlement başarısızlığı kaydı mevcut.',
                            $metricSources,
                            $eventSources,
                            'high'
                        );
                    }
                    break;

                case 'SIG-BEH-001':
                    if (is_array($actorMetrics)) {
                        $tx = (int)($actorMetrics['transactions'] ?? 0);
                        if ($tx <= 1) {
                            $metricSources = ['actor.transactions' => $tx];
                            $emitted[] = zinesh_trust_signal_build_emitted(
                                $signalId,
                                $def,
                                $tx === 0
                                    ? 'Actor için kayıtlı tamamlanmış işlem sayısı 0.'
                                    : 'Actor için kayıtlı işlem sayısı 1 (ilk işlem bandı).',
                                $metricSources,
                                $eventSources,
                                'high'
                            );
                        }
                    }
                    break;

                case 'SIG-BEH-002':
                    if ($peerPairCount >= ZINESH_SIG_BEH002_MIN_PAIR_ROOMS) {
                        $metricSources = ['peer_pair.room_count' => $peerPairCount];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Aynı employer-worker çifti için {$peerPairCount} kayıtlı oda var.",
                            $metricSources,
                            $eventSources,
                            'high'
                        );
                    }
                    break;

                case 'SIG-BEH-003':
                    if (is_array($actorMetrics)) {
                        $tx = (int)($actorMetrics['transactions'] ?? 0);
                        $avg = (int)($actorMetrics['history']['average_completion_time'] ?? 0);
                        if ($tx >= ZINESH_SIG_BEH003_MIN_TRANSACTIONS && $avg > 0) {
                            $metricSources = [
                                'actor.transactions' => $tx,
                                'actor.history.average_completion_time' => $avg,
                            ];
                            $emitted[] = zinesh_trust_signal_build_emitted(
                                $signalId,
                                $def,
                                "Actor tamamlama süresi ortalaması {$avg} saniye (n={$tx}).",
                                $metricSources,
                                $eventSources,
                                $tx >= 10 ? 'high' : 'medium'
                            );
                        }
                    }
                    break;

                case 'SIG-CTR-001':
                    $vc = (int)($contractMetrics['version_count'] ?? 0);
                    $ac = (int)($contractMetrics['amount_change_count'] ?? 0);
                    if ($vc >= ZINESH_SIG_CTR001_MIN_VERSIONS || $ac >= ZINESH_SIG_CTR001_MIN_AMOUNT_CHANGES) {
                        $metricSources = [
                            'contract.version_count' => $vc,
                            'contract.amount_change_count' => $ac,
                        ];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Sözleşme versiyon sayısı {$vc}; tutar değişim sayısı {$ac}.",
                            $metricSources,
                            $eventSources,
                            'medium'
                        );
                    }
                    break;

                case 'SIG-CTR-002':
                    $postAcceptChanges = (int)($emitContract['post_acceptance_change_count'] ?? -1);
                    $hasAcceptedContract = ($emitContract['has_accepted_contract'] ?? false) === true;
                    if ($postAcceptChanges === 0 && $hasAcceptedContract) {
                        $metricSources = ['contract.post_acceptance_change_count' => 0];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            'Kabul sonrası sözleşme değişikliği kaydı yok.',
                            $metricSources,
                            $eventSources,
                            'high'
                        );
                    }
                    break;

                case 'SIG-ACT-001':
                    $silenceGap = (int)($emitActivity['max_silence_gap_seconds'] ?? 0);
                    if ($silenceGap >= ZINESH_SIG_ACT001_MIN_SILENCE) {
                        $days = max(1, (int)round($silenceGap / 86400));
                        $metricSources = ['activity.max_silence_gap_seconds' => $silenceGap];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            "Oda içinde ardışık event'ler arasında yaklaşık {$days} gün kayıtlı aktivite aralığı var.",
                            $metricSources,
                            zinesh_trust_signal_event_sources_summary($events, []),
                            'high'
                        );
                    }
                    break;

                case 'SIG-ACT-002':
                    $recoveryCount = (int)($emitActivity['recovery_count'] ?? 0);
                    if ($recoveryCount >= 1) {
                        $metricSources = ['activity.recovery_count' => $recoveryCount];
                        $emitted[] = zinesh_trust_signal_build_emitted(
                            $signalId,
                            $def,
                            'Escrow recovery kaydı mevcut.',
                            $metricSources,
                            $eventSources,
                            'high'
                        );
                    }
                    break;
            }
        }

        return [
            'signal_runtime_version' => ZINESH_TRUST_SIGNAL_RUNTIME_VERSION,
            'catalog_version' => ZINESH_TRUST_SIGNAL_CATALOG_VERSION,
            'room_id' => $roomId,
            'actor_id' => $actorId,
            'signals' => $emitted,
        ];
    } catch (Throwable $e) {
        error_log('zinesh_trust_signal_runtime_for_room: ' . $e->getMessage());
        return zinesh_trust_signal_runtime_empty($roomId);
    }
}

/**
 * Determinism karşılaştırması için normalize.
 *
 * @return array<string,mixed>
 */
function zinesh_trust_signal_runtime_normalize_for_compare(array $pkg): array
{
    return $pkg;
}

/** @return list<string> */
function zinesh_trust_signal_runtime_signal_ids(array $pkg): array
{
    $ids = [];
    foreach ($pkg['signals'] ?? [] as $row) {
        if (is_array($row) && isset($row['signal_id'])) {
            $ids[] = (string)$row['signal_id'];
        }
    }
    return $ids;
}
