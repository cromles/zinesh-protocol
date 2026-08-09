<?php
declare(strict_types=1);

const ZINESH_COPILOT_EXPLAINABILITY_CHAIN = 'risk_observation → context → signal → metric → event';

/** @var list<string> */
const ZINESH_COPILOT_ALLOWED_INTENTS = [
    'overview',
    'why_observation',
    'evidence',
    'context_chain',
    'signal_chain',
    'event_chain',
];

function zinesh_copilot_normalize_intent(string $intent): string
{
    $intent = strtolower(trim($intent));
    if ($intent === '') {
        return 'overview';
    }
    return in_array($intent, ZINESH_COPILOT_ALLOWED_INTENTS, true) ? $intent : 'overview';
}

/** @param array<string,mixed> $engine */
function zinesh_copilot_context_observation_rows(array $engine): array
{
    $pkg = $engine['observations'] ?? [];
    if (!is_array($pkg)) {
        return [];
    }
    $rows = $pkg['observations'] ?? [];
    return is_array($rows) ? $rows : [];
}

/** @param array<string,mixed> $engine */
function zinesh_copilot_context_find_observation(array $engine, string $observationId): ?array
{
    $observationId = trim($observationId);
    if ($observationId === '') {
        return null;
    }
    foreach (zinesh_copilot_context_observation_rows($engine) as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string)($row['observation_id'] ?? '') === $observationId) {
            return $row;
        }
    }
    return null;
}

/** @param array<string,mixed> $obs */
function zinesh_copilot_context_collect_events(array $obs): array
{
    $events = [];
    $signals = is_array($obs['signals_used'] ?? null) ? $obs['signals_used'] : [];
    foreach ($signals as $signal) {
        if (!is_array($signal)) {
            continue;
        }
        $sources = is_array($signal['event_sources'] ?? null) ? $signal['event_sources'] : [];
        foreach ($sources as $raw) {
            if (is_array($raw)) {
                $events[] = $raw;
            }
        }
    }
    usort($events, static function (array $a, array $b): int {
        return strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? ''));
    });
    return $events;
}

/** @param array<string,mixed> $obs */
function zinesh_copilot_context_source_bundle(array $obs): array
{
    return [
        'observation' => [
            'observation_id' => (string)($obs['observation_id'] ?? ''),
            'title' => (string)($obs['title'] ?? ''),
            'description' => (string)($obs['description'] ?? ''),
            'confidence' => (string)($obs['confidence'] ?? ''),
        ],
        'contexts' => is_array($obs['contexts_used'] ?? null) ? $obs['contexts_used'] : [],
        'signals' => is_array($obs['signals_used'] ?? null) ? $obs['signals_used'] : [],
        'metrics' => is_array($obs['metrics_used'] ?? null) ? $obs['metrics_used'] : [],
        'events' => zinesh_copilot_context_collect_events($obs),
        'explainability' => is_array($obs['explainability'] ?? null) ? $obs['explainability'] : [],
        'evidence_chain' => is_array($obs['evidence_chain'] ?? null) ? $obs['evidence_chain'] : [],
    ];
}

/**
 * Risk Engine paketini deterministik Copilot context JSON'una dönüştürür.
 *
 * @param array<string,mixed> $engine
 * @return array<string,mixed>
 */
function zinesh_copilot_context_build_from_engine(
    array $engine,
    string $intent,
    ?string $observationId = null
): array {
    $intent = zinesh_copilot_normalize_intent($intent);
    $observationId = trim((string)$observationId);

    $observations = [];
    foreach (zinesh_copilot_context_observation_rows($engine) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $observations[] = [
            'observation_id' => (string)($row['observation_id'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'description' => (string)($row['description'] ?? ''),
            'confidence' => (string)($row['confidence'] ?? ''),
        ];
    }

    $focusObservation = null;
    $sources = [];
    if ($intent !== 'overview') {
        if ($observationId === '') {
            $first = zinesh_copilot_context_observation_rows($engine)[0] ?? null;
            $observationId = is_array($first) ? (string)($first['observation_id'] ?? '') : '';
        }
        $obs = zinesh_copilot_context_find_observation($engine, $observationId);
        if ($obs) {
            $focusObservation = (string)($obs['observation_id'] ?? '');
            $sources = zinesh_copilot_context_source_bundle($obs);
        }
    }

    return [
        'framework_version' => '1.0',
        'explainability_chain' => ZINESH_COPILOT_EXPLAINABILITY_CHAIN,
        'room_id' => (string)($engine['room_id'] ?? ''),
        'actor_id' => $engine['actor_id'] ?? null,
        'risk_engine_version' => (string)($engine['risk_engine_version'] ?? ''),
        'intent' => $intent,
        'observation_id' => $focusObservation,
        'observations' => $observations,
        'focus_sources' => $sources,
        'runtime_packages' => [
            'metrics' => is_array($engine['metrics'] ?? null) ? $engine['metrics'] : [],
            'signals' => is_array($engine['signals'] ?? null) ? $engine['signals'] : [],
            'contexts' => is_array($engine['contexts'] ?? null) ? $engine['contexts'] : [],
            'observations' => is_array($engine['observations'] ?? null) ? $engine['observations'] : [],
        ],
    ];
}
