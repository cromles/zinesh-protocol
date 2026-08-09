<?php
declare(strict_types=1);

/**
 * Risk Observation Runtime v0.1 — Context → Risk Observation (salt okunur).
 * Architecture Freeze v1.0: Event → Metric → Signal → Context → Risk Observation.
 * AI Explanation, Human Decision yok (S3.3 kapsamı).
 */
require_once __DIR__ . '/risk_observation_catalog_lib.php';
require_once __DIR__ . '/context_resolver_lib.php';
require_once __DIR__ . '/ai_context_lib.php';

const ZINESH_RISK_OBSERVATION_RUNTIME_VERSION = '0.1';

/** @param array<string,mixed> $contextPkg */
function zinesh_risk_observation_index_signals(array $contextPkg): array
{
    $index = [];
    foreach ($contextPkg['contexts'] ?? [] as $ctx) {
        if (!is_array($ctx)) {
            continue;
        }
        foreach ($ctx['signals_used'] ?? [] as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $sid = (string)($frame['signal_id'] ?? '');
            if ($sid !== '') {
                $index[$sid] = $frame;
            }
        }
    }
    return $index;
}

/** @param array<string,mixed> $contextPkg */
function zinesh_risk_observation_index_contexts(array $contextPkg): array
{
    $index = [];
    foreach ($contextPkg['contexts'] ?? [] as $ctx) {
        if (!is_array($ctx)) {
            continue;
        }
        $cid = (string)($ctx['context_id'] ?? '');
        if ($cid !== '') {
            $index[$cid] = $ctx;
        }
    }
    return $index;
}

/** @param array<string,mixed> $aiContext */
function zinesh_risk_observation_structured_terms(array $aiContext): array
{
    $accepted = is_array($aiContext['accepted_contract_version'] ?? null)
        ? $aiContext['accepted_contract_version']
        : null;
    if ($accepted !== null && is_array($accepted['structured_terms_json'] ?? null)) {
        return $accepted['structured_terms_json'];
    }
    $latest = is_array($aiContext['latest_contract_version'] ?? null)
        ? $aiContext['latest_contract_version']
        : null;
    if ($latest !== null && is_array($latest['structured_terms_json'] ?? null)) {
        return $latest['structured_terms_json'];
    }
    return [];
}

/** @param array<string,mixed> $structured */
function zinesh_risk_observation_structured_incomplete(array $structured): bool
{
    if ($structured === []) {
        return true;
    }
    $required = ['delivery_date', 'deadline', 'scope', 'deliverable'];
    foreach ($required as $key) {
        $val = trim((string)($structured[$key] ?? ''));
        if ($val !== '') {
            return false;
        }
    }
    return true;
}

/** @param array<string,mixed> $contextPkg */
function zinesh_risk_observation_has_context_fallback(array $contextPkg): bool
{
    foreach ($contextPkg['contexts'] ?? [] as $ctx) {
        if (!is_array($ctx)) {
            continue;
        }
        $sources = is_array($ctx['explainability']['assignment_sources'] ?? null)
            ? $ctx['explainability']['assignment_sources']
            : [];
        if (isset($sources['fallback'])) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string,mixed>> $contextFrames
 * @param list<array<string,mixed>> $signalFrames
 */
function zinesh_risk_observation_confidence_level(array $contextFrames, array $signalFrames): string
{
    if ($contextFrames === [] && $signalFrames === []) {
        return 'low';
    }
    $low = 0;
    $high = 0;
    foreach ($contextFrames as $ctx) {
        $level = (string)($ctx['confidence'] ?? 'medium');
        if ($level === 'low') {
            $low++;
        } elseif ($level === 'high') {
            $high++;
        }
    }
    foreach ($signalFrames as $sig) {
        $level = (string)($sig['signal_confidence'] ?? $sig['confidence'] ?? 'medium');
        if ($level === 'low') {
            $low++;
        } elseif ($level === 'high') {
            $high++;
        }
    }
    if ($low > 0 && $high === 0) {
        return 'low';
    }
    if ($high >= 2 && $low === 0) {
        return 'high';
    }
    return 'medium';
}

/**
 * @param list<array<string,mixed>> $contextFrames
 * @param list<array<string,mixed>> $signalFrames
 * @return array<string,mixed>
 */
function zinesh_risk_observation_aggregate_metrics(array $contextFrames, array $signalFrames): array
{
    $metrics = [];
    foreach ($contextFrames as $ctx) {
        $src = is_array($ctx['metrics_used'] ?? null) ? $ctx['metrics_used'] : [];
        foreach ($src as $key => $value) {
            $metrics[(string)$key] = $value;
        }
    }
    foreach ($signalFrames as $frame) {
        $src = is_array($frame['metric_sources'] ?? null) ? $frame['metric_sources'] : [];
        foreach ($src as $key => $value) {
            $metrics[(string)$key] = $value;
        }
    }
    return $metrics;
}

/**
 * @param array<string,mixed> $def
 * @param array<string,array<string,mixed>> $signalIndex
 * @param array<string,array<string,mixed>> $contextIndex
 * @param array<string,mixed> $structured
 * @param array<string,mixed> $contextPkg
 */
function zinesh_risk_observation_rule_matches(
    string $obsId,
    array $def,
    array $signalIndex,
    array $contextIndex,
    array $structured,
    array $contextPkg
): bool {
    foreach ($def['required_signals'] ?? [] as $sid) {
        if (!isset($signalIndex[$sid])) {
            return false;
        }
    }

    $anyRequired = is_array($def['required_signals_any'] ?? null) ? $def['required_signals_any'] : [];
    if ($anyRequired !== []) {
        $hit = false;
        foreach ($anyRequired as $sid) {
            if (isset($signalIndex[$sid])) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
    }

    foreach ($def['required_signals_absent'] ?? [] as $sid) {
        if (isset($signalIndex[$sid])) {
            return false;
        }
    }

    if (($def['requires_incomplete_structured_terms'] ?? false) === true
        && !zinesh_risk_observation_structured_incomplete($structured)) {
        return false;
    }

    if (($def['requires_context_fallback'] ?? false) === true
        && !zinesh_risk_observation_has_context_fallback($contextPkg)) {
        return false;
    }

    if (($def['context_required'] ?? false) === true && $contextIndex === []) {
        return false;
    }

    return true;
}

/**
 * @param array<string,mixed> $def
 * @param array<string,array<string,mixed>> $signalIndex
 * @param array<string,array<string,mixed>> $contextIndex
 * @return list<array<string,mixed>>
 */
function zinesh_risk_observation_select_context_frames(
    array $def,
    array $signalIndex,
    array $contextIndex
): array {
    $preferred = is_array($def['optional_contexts'] ?? null)
        ? $def['optional_contexts']
        : (is_array($def['preferred_contexts'] ?? null) ? $def['preferred_contexts'] : []);
    $out = [];
    foreach ($preferred as $cid) {
        if (isset($contextIndex[$cid])) {
            $out[] = $contextIndex[$cid];
        }
    }
    if ($out === []) {
        foreach ($contextIndex as $ctx) {
            $out[] = $ctx;
        }
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $contextFrames
 * @return list<array<string,mixed>>
 */
function zinesh_risk_observation_select_signal_frames(
    array $def,
    array $signalIndex,
    array $contextFrames
): array {
    $needed = [];
    foreach ($def['required_signals'] ?? [] as $sid) {
        $needed[$sid] = true;
    }
    foreach ($def['required_signals_any'] ?? [] as $sid) {
        $needed[$sid] = true;
    }
    if ($needed === []) {
        foreach ($contextFrames as $ctx) {
            foreach ($ctx['signals_used'] ?? [] as $frame) {
                if (is_array($frame)) {
                    $sid = (string)($frame['signal_id'] ?? '');
                    if ($sid !== '') {
                        $needed[$sid] = true;
                    }
                }
            }
        }
    }

    $out = [];
    foreach (array_keys($needed) as $sid) {
        if (isset($signalIndex[$sid])) {
            $out[] = $signalIndex[$sid];
        }
    }
    return $out;
}

/**
 * @param list<array<string,mixed>> $contextFrames
 * @param list<array<string,mixed>> $signalFrames
 */
function zinesh_risk_observation_build_emitted(
    string $obsId,
    string $title,
    string $description,
    array $contextFrames,
    array $signalFrames
): array {
    $metricsUsed = zinesh_risk_observation_aggregate_metrics($contextFrames, $signalFrames);
    $confidence = zinesh_risk_observation_confidence_level($contextFrames, $signalFrames);

    $contextsUsed = [];
    foreach ($contextFrames as $ctx) {
        $contextsUsed[] = [
            'context_id' => (string)($ctx['context_id'] ?? ''),
            'title' => (string)($ctx['title'] ?? ''),
            'confidence' => (string)($ctx['confidence'] ?? 'medium'),
        ];
    }

    $evidenceChain = [
        'chain' => 'risk_observation → context → signal → metric → event',
        'observation_id' => $obsId,
        'contexts' => array_map(static function (array $ctx): array {
            return [
                'context_id' => $ctx['context_id'] ?? '',
                'signals' => $ctx['signals_used'] ?? [],
                'metrics' => $ctx['metrics_used'] ?? [],
            ];
        }, $contextFrames),
        'signals' => array_map(static function (array $frame): array {
            return [
                'signal_id' => $frame['signal_id'] ?? '',
                'metrics' => $frame['metric_sources'] ?? [],
                'events' => $frame['event_sources'] ?? [],
            ];
        }, $signalFrames),
    ];

    return [
        'observation_id' => $obsId,
        'title' => $title,
        'description' => $description,
        'signals_used' => $signalFrames,
        'contexts_used' => $contextsUsed,
        'metrics_used' => $metricsUsed,
        'evidence_chain' => $evidenceChain,
        'explainability' => [
            'chain' => 'risk_observation → context → signal → metric → event',
            'observation_id' => $obsId,
            'contexts' => $contextsUsed,
            'signals' => $signalFrames,
            'metrics' => $metricsUsed,
            'model_version' => ZINESH_RISK_OBSERVATION_MODEL_VERSION,
        ],
        'confidence' => $confidence,
        'observation_version' => ZINESH_RISK_OBSERVATION_RUNTIME_VERSION,
        'emitted' => true,
    ];
}

/** @return array<string,mixed> */
function zinesh_risk_observation_runtime_empty(string $roomId): array
{
    return [
        'risk_observation_runtime_version' => ZINESH_RISK_OBSERVATION_RUNTIME_VERSION,
        'model_version' => ZINESH_RISK_OBSERVATION_MODEL_VERSION,
        'room_id' => $roomId,
        'actor_id' => null,
        'observations' => [],
    ];
}

/**
 * Oda için Risk Observation paketi (yalnızca emit edilen observation'lar).
 *
 * @return array<string,mixed>
 */
function zinesh_risk_observation_runtime_for_room(string $roomId, ?string $actorId = null): array
{
    $roomId = trim($roomId);
    if ($roomId === '') {
        return zinesh_risk_observation_runtime_empty('');
    }

    try {
        $room = zinesh_ai_context_room_snapshot($roomId);
        if ($room === null) {
            return zinesh_risk_observation_runtime_empty($roomId);
        }

        $contextPkg = zinesh_context_resolver_for_room($roomId, $actorId);
        $aiContext = zinesh_escrow_room_ai_context($roomId);
        $structured = zinesh_risk_observation_structured_terms($aiContext);

        $signalIndex = zinesh_risk_observation_index_signals($contextPkg);
        $contextIndex = zinesh_risk_observation_index_contexts($contextPkg);

        if ($signalIndex === [] && $contextIndex === []) {
            return [
                'risk_observation_runtime_version' => ZINESH_RISK_OBSERVATION_RUNTIME_VERSION,
                'model_version' => ZINESH_RISK_OBSERVATION_MODEL_VERSION,
                'room_id' => $roomId,
                'actor_id' => $contextPkg['actor_id'] ?? $actorId,
                'observations' => [],
            ];
        }

        $definitions = zinesh_risk_observation_catalog_definitions();
        $descriptions = zinesh_risk_observation_catalog_descriptions();
        $emitted = [];

        foreach (zinesh_risk_observation_catalog_ids() as $obsId) {
            $def = $definitions[$obsId] ?? null;
            if ($def === null) {
                continue;
            }
            if (!zinesh_risk_observation_rule_matches(
                $obsId,
                $def,
                $signalIndex,
                $contextIndex,
                $structured,
                $contextPkg
            )) {
                continue;
            }

            $contextFrames = zinesh_risk_observation_select_context_frames($def, $signalIndex, $contextIndex);
            $signalFrames = zinesh_risk_observation_select_signal_frames($def, $signalIndex, $contextFrames);
            if ($signalFrames === [] && ($def['requires_context_fallback'] ?? false) !== true) {
                continue;
            }

            $emitted[] = zinesh_risk_observation_build_emitted(
                $obsId,
                (string)($def['title'] ?? $obsId),
                (string)($descriptions[$obsId] ?? ''),
                $contextFrames,
                $signalFrames
            );
        }

        return [
            'risk_observation_runtime_version' => ZINESH_RISK_OBSERVATION_RUNTIME_VERSION,
            'model_version' => ZINESH_RISK_OBSERVATION_MODEL_VERSION,
            'room_id' => $roomId,
            'actor_id' => $contextPkg['actor_id'] ?? $actorId,
            'observations' => $emitted,
        ];
    } catch (Throwable $e) {
        error_log('zinesh_risk_observation_runtime_for_room: ' . $e->getMessage());
        return zinesh_risk_observation_runtime_empty($roomId);
    }
}

/** @return list<string> */
function zinesh_risk_observation_runtime_observation_ids(array $pkg): array
{
    $ids = [];
    foreach ($pkg['observations'] ?? [] as $row) {
        if (is_array($row) && isset($row['observation_id'])) {
            $ids[] = (string)$row['observation_id'];
        }
    }
    return $ids;
}

/** @return array<string,mixed> */
function zinesh_risk_observation_runtime_normalize_for_compare(array $pkg): array
{
    return $pkg;
}
