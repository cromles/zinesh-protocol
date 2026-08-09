<?php
declare(strict_types=1);

/**
 * Risk Engine Orchestrator v0.1 — Metric → Signal → Context → Risk Observation (salt okunur).
 * Architecture Freeze v1.0: yalnızca mevcut runtime katmanlarını sırayla çağırır.
 * AI Explanation, Human Decision, Risk Score yok (S3.4 kapsamı).
 */
require_once __DIR__ . '/trust_intelligence_lib.php';
require_once __DIR__ . '/trust_signal_runtime_lib.php';
require_once __DIR__ . '/context_resolver_lib.php';
require_once __DIR__ . '/risk_observation_runtime_lib.php';

const ZINESH_RISK_ENGINE_VERSION = '0.1';

/**
 * @return array<string,mixed>
 */
function zinesh_risk_engine_empty(string $roomId, ?string $actorId = null): array
{
    $metricsPkg = zinesh_trust_intelligence_metrics($roomId);
    $signalsPkg = zinesh_trust_signal_runtime_for_room($roomId, $actorId);
    $contextsPkg = zinesh_context_resolver_for_room($roomId, $actorId);
    $observationsPkg = zinesh_risk_observation_runtime_for_room($roomId, $actorId);

    return zinesh_risk_engine_assemble(
        $roomId,
        $actorId,
        $metricsPkg,
        $signalsPkg,
        $contextsPkg,
        $observationsPkg
    );
}

/**
 * @param array<string,mixed> $metricsPkg
 * @param array<string,mixed> $signalsPkg
 * @param array<string,mixed> $contextsPkg
 * @param array<string,mixed> $observationsPkg
 * @return array<string,mixed>
 */
function zinesh_risk_engine_assemble(
    string $roomId,
    ?string $actorId,
    array $metricsPkg,
    array $signalsPkg,
    array $contextsPkg,
    array $observationsPkg
): array {
    $resolvedActorId = $observationsPkg['actor_id']
        ?? $contextsPkg['actor_id']
        ?? $signalsPkg['actor_id']
        ?? $actorId;

    return [
        'risk_engine_version' => ZINESH_RISK_ENGINE_VERSION,
        'room_id' => $roomId,
        'actor_id' => $resolvedActorId,
        'metrics' => $metricsPkg,
        'signals' => $signalsPkg,
        'contexts' => $contextsPkg,
        'observations' => $observationsPkg,
    ];
}

/**
 * Oda için Risk Engine paketi — tüm runtime katmanlarının orchestrator çıktısı.
 *
 * Akış: Trust Metrics → Trust Signal Runtime → Context Resolver → Risk Observation Runtime
 *
 * @return array<string,mixed>
 */
function zinesh_risk_engine_for_room(string $roomId, ?string $actorId = null): array
{
    $roomId = trim($roomId);
    if ($roomId === '') {
        return zinesh_risk_engine_empty('');
    }

    try {
        $metricsPkg = zinesh_trust_intelligence_metrics($roomId);
        $signalsPkg = zinesh_trust_signal_runtime_for_room($roomId, $actorId);
        $contextsPkg = zinesh_context_resolver_for_room($roomId, $actorId);
        $observationsPkg = zinesh_risk_observation_runtime_for_room($roomId, $actorId);

        return zinesh_risk_engine_assemble(
            $roomId,
            $actorId,
            $metricsPkg,
            $signalsPkg,
            $contextsPkg,
            $observationsPkg
        );
    } catch (Throwable $e) {
        error_log('zinesh_risk_engine_for_room: ' . $e->getMessage());
        return zinesh_risk_engine_empty($roomId, $actorId);
    }
}

/**
 * Karşılaştırma için orchestrator paketini normalize eder (determinism / replay testleri).
 *
 * @return array<string,mixed>
 */
function zinesh_risk_engine_normalize_for_compare(array $pkg): array
{
    return $pkg;
}
