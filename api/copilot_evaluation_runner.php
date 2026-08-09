<?php
declare(strict_types=1);

/**
 * Copilot Evaluation Runner v1.0 — Dataset → Copilot (mock) → Validator → Evaluation → Report
 */
require_once __DIR__ . '/copilot_evaluation_lib.php';
require_once __DIR__ . '/copilot_lib.php';
require_once __DIR__ . '/copilot_mock_provider.php';
require_once __DIR__ . '/risk_engine_endpoint_lib.php';

function zinesh_copilot_eval_dataset_dir(): string
{
    return dirname(__DIR__) . '/docs/copilot_eval';
}

/**
 * @return list<array<string,mixed>>
 */
function zinesh_copilot_eval_load_scenarios(?string $datasetDir = null): array
{
    $dir = $datasetDir ?? zinesh_copilot_eval_dataset_dir();
    if (!is_dir($dir)) {
        return [];
    }

    $files = glob(rtrim($dir, '/\\') . '/*.json') ?: [];
    sort($files, SORT_STRING);

    $scenarios = [];
    foreach ($files as $file) {
        $raw = json_decode((string)file_get_contents($file), true);
        if (!is_array($raw)) {
            continue;
        }
        if (!isset($raw['id'])) {
            $raw['id'] = pathinfo($file, PATHINFO_FILENAME);
        }
        $scenarios[] = $raw;
    }

    return $scenarios;
}

/**
 * @param array<string,mixed> $scenario
 */
function zinesh_copilot_eval_bootstrap_room_setup(array $scenario): void
{
    $setup = $scenario['room_setup'] ?? null;
    if (!is_array($setup)) {
        return;
    }

    $rooms = is_array($setup['escrow_rooms'] ?? null) ? $setup['escrow_rooms'] : [];
    $events = is_array($setup['zinesh_events'] ?? null) ? $setup['zinesh_events'] : [];

    if ($rooms !== []) {
        zinesh_json_write('escrow_rooms.json', $rooms);
    }
    if ($events !== []) {
        zinesh_json_write('zinesh_events.json', $events);
    }
}

/**
 * @param array<string,mixed> $scenario
 * @return array<string,mixed>
 */
function zinesh_copilot_eval_resolve_engine(array $scenario): array
{
    if (is_array($scenario['risk_engine_output'] ?? null) && $scenario['risk_engine_output'] !== []) {
        return $scenario['risk_engine_output'];
    }

    $roomId = (string)($scenario['room_id'] ?? '');
    $actorId = (string)($scenario['actor_id'] ?? '');
    if ($roomId === '') {
        $setup = is_array($scenario['room_setup'] ?? null) ? $scenario['room_setup'] : [];
        $rooms = is_array($setup['escrow_rooms'] ?? null) ? $setup['escrow_rooms'] : [];
        $first = is_array($rooms[0] ?? null) ? $rooms[0] : [];
        $roomId = (string)($first['id'] ?? '');
        if ($actorId === '') {
            $actorId = (string)($first['employerUid'] ?? '');
        }
    }

    if ($roomId === '') {
        return [];
    }

    return zinesh_risk_engine_for_room($roomId, $actorId !== '' ? $actorId : null);
}

/**
 * @param array<string,mixed> $scenario
 */
function zinesh_copilot_eval_run_scenario(
    array $scenario,
    ?ZineshCopilotProviderInterface $provider = null,
): array {
    $started = hrtime(true);

    if (($scenario['evaluation_mode'] ?? '') === 'validator_fixture') {
        $fixture = is_array($scenario['fixture_response'] ?? null) ? $scenario['fixture_response'] : [];
        $rawText = (string)($fixture['explanation'] ?? '');
        $context = is_array($fixture['copilot_context'] ?? null) ? $fixture['copilot_context'] : [];
        $validation = zinesh_copilot_validate_response($rawText, $context);
        $explanation = $rawText;
        $validationStatus = 'pass';
        if (!$validation['valid'] || trim($explanation) === '') {
            $validationStatus = 'rejected';
            $explanation = zinesh_copilot_validation_rejection_message();
        }
        $response = array_merge($fixture, [
            'explanation' => $explanation,
            'validation_status' => $validationStatus,
            'explainability_chain' => (string)($fixture['explainability_chain'] ?? ZINESH_COPILOT_EVAL_EXPLAINABILITY_CHAIN),
        ]);
        return zinesh_copilot_eval_finish_scenario($scenario, zinesh_copilot_eval_scenario($scenario, $response), $started);
    }

    zinesh_copilot_eval_bootstrap_room_setup($scenario);

    $engine = zinesh_copilot_eval_resolve_engine($scenario);
    if ($engine === []) {
        return zinesh_copilot_eval_finish_scenario($scenario, zinesh_copilot_eval_scenario($scenario, [
            'explanation' => '',
            'validation_status' => 'rejected',
            'explainability_chain' => '',
            'provider' => 'mock',
            'copilot_context' => [],
        ]), $started);
    }

    $intent = (string)($scenario['intent'] ?? 'overview');
    $observationId = $scenario['observation_id'] ?? null;
    $observationId = is_string($observationId) ? $observationId : null;

    $public = zinesh_risk_engine_public_response($engine);
    $provider = $provider ?? new ZineshCopilotMockProvider();
    $response = zinesh_copilot_build_response($engine, $intent, $observationId, $public, $provider);

    return zinesh_copilot_eval_finish_scenario($scenario, zinesh_copilot_eval_scenario($scenario, $response), $started);
}

/**
 * @param array<string,mixed> $scenario
 * @param array<string,mixed> $result
 * @return array<string,mixed>
 */
function zinesh_copilot_eval_finish_scenario(array $scenario, array $result, int $startedNs): array
{
    $elapsedMs = (int)round((hrtime(true) - $startedNs) / 1_000_000);
    $result['duration_ms'] = max(0, $elapsedMs);
    return $result;
}

/**
 * @return array<string,mixed>
 */
function zinesh_copilot_eval_run_all(
    ?string $datasetDir = null,
    ?ZineshCopilotProviderInterface $provider = null,
): array {
    $dir = $datasetDir ?? zinesh_copilot_eval_dataset_dir();
    $scenarios = zinesh_copilot_eval_load_scenarios($dir);
    $provider = $provider ?? new ZineshCopilotMockProvider();

    $runStarted = hrtime(true);
    $results = [];
    foreach ($scenarios as $scenario) {
        $results[] = zinesh_copilot_eval_run_scenario($scenario, $provider);
    }
    $executionMs = (int)round((hrtime(true) - $runStarted) / 1_000_000);

    return zinesh_copilot_eval_build_report($results, [
        'prompt_version' => zinesh_copilot_eval_prompt_version(),
        'provider' => $provider->providerId(),
        'provider_model' => $provider->providerId() === 'mock' ? null : null,
        'dataset_version' => zinesh_copilot_eval_dataset_version($scenarios),
        'dataset_fingerprint' => zinesh_copilot_eval_dataset_fingerprint($scenarios, $dir),
        'execution_ms' => max(0, $executionMs),
        'statistics' => zinesh_copilot_eval_build_statistics($results, $scenarios),
    ]);
}
