<?php
declare(strict_types=1);

/**
 * Copilot Engineering Evaluation v1.0 — ölçüm katmanı (Framework değiştirmez).
 */
require_once __DIR__ . '/copilot_response_validator.php';

const ZINESH_COPILOT_EVALUATION_VERSION = '1.0';
const ZINESH_COPILOT_EVAL_DATASET_VERSION = '1.0';

const ZINESH_COPILOT_EVAL_EXPLAINABILITY_CHAIN = 'risk_observation → context → signal → metric → event';

/** @var list<string> */
const ZINESH_COPILOT_EVAL_COVERAGE_INTENTS = [
    'overview',
    'why_observation',
    'evidence',
    'context_chain',
    'signal_chain',
    'event_chain',
];

/** @var list<string> */
const ZINESH_COPILOT_EVAL_HUMAN_CONTROL_PHRASES = [
    'dolandırıcı',
    'garanti',
    'kesin',
    'cezalandır',
    'hesabı kapat',
    'ödemeyi durdur',
    'riskli kullanıcı',
    'iptal et',
];

/** @var list<string> */
const ZINESH_COPILOT_EVAL_VALIDATOR_REGRESSION_PHRASES = [
    'risk score',
    'risk skoru',
    'prediction',
    'tahmin',
    'fraud',
    'settlement öner',
    'settlement önerisi',
    'yeni signal',
    'yeni context',
    'yeni observation',
    'yeni event',
];

/**
 * @return array{pass:bool,reasons:list<string>}
 */
function zinesh_copilot_eval_human_control_check(string $text): array
{
    $reasons = [];
    $lower = mb_strtolower($text, 'UTF-8');
    foreach (ZINESH_COPILOT_EVAL_HUMAN_CONTROL_PHRASES as $phrase) {
        if (str_contains($lower, mb_strtolower($phrase, 'UTF-8'))) {
            $reasons[] = "human_control:{$phrase}";
        }
    }
    return ['pass' => $reasons === [], 'reasons' => $reasons];
}

/**
 * @return array{pass:bool,reasons:list<string>}
 */
function zinesh_copilot_eval_explainability_check(string $text, string $expectedChain): array
{
    $reasons = [];
    $lower = mb_strtolower($text, 'UTF-8');

    if (trim($expectedChain) !== '' && !str_contains($text, $expectedChain)) {
        $reasons[] = 'explainability:chain_missing_in_text';
    }

    $links = [
        ['risk observation', 'risk_observation'],
        ['context'],
        ['signal'],
        ['metric'],
        ['event'],
    ];
    foreach ($links as $aliases) {
        $found = false;
        foreach ($aliases as $alias) {
            if (str_contains($lower, mb_strtolower($alias, 'UTF-8'))) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $reasons[] = 'explainability:missing_link:' . $aliases[0];
        }
    }

    return ['pass' => $reasons === [], 'reasons' => $reasons];
}

/**
 * Validator regression — yasak ifadeler final cevapta olmamalı.
 *
 * @return array{pass:bool,reasons:list<string>}
 */
function zinesh_copilot_eval_validator_regression_check(string $text): array
{
    $reasons = [];
    $lower = mb_strtolower($text, 'UTF-8');
    foreach (ZINESH_COPILOT_EVAL_VALIDATOR_REGRESSION_PHRASES as $phrase) {
        if (str_contains($lower, mb_strtolower($phrase, 'UTF-8'))) {
            $reasons[] = "validator_regression:{$phrase}";
        }
    }
    return ['pass' => $reasons === [], 'reasons' => $reasons];
}

/**
 * Prompt regression — beklenen alt dizgiler ve validation_status.
 *
 * @param array<string,mixed> $expected
 * @return array{pass:bool,reasons:list<string>}
 */
function zinesh_copilot_eval_prompt_regression_check(array $copilotResponse, array $expected): array
{
    $reasons = [];
    $explanation = (string)($copilotResponse['explanation'] ?? '');
    $status = (string)($copilotResponse['validation_status'] ?? '');

    $expectedStatus = (string)($expected['validation_status'] ?? 'pass');
    if ($status !== $expectedStatus) {
        $reasons[] = "prompt_regression:validation_status_expected_{$expectedStatus}_got_{$status}";
    }

    $contains = $expected['explanation_contains'] ?? [];
    if (is_array($contains)) {
        foreach ($contains as $needle) {
            $needle = (string)$needle;
            if ($needle !== '' && !str_contains($explanation, $needle)) {
                $reasons[] = "prompt_regression:missing:{$needle}";
            }
        }
    }

    $forbidden = $expected['explanation_must_not_contain'] ?? [];
    if (is_array($forbidden)) {
        foreach ($forbidden as $needle) {
            $needle = (string)$needle;
            if ($needle !== '' && str_contains(mb_strtolower($explanation, 'UTF-8'), mb_strtolower($needle, 'UTF-8'))) {
                $reasons[] = "prompt_regression:forbidden:{$needle}";
            }
        }
    }

    return ['pass' => $reasons === [], 'reasons' => $reasons];
}

/**
 * @param array<string,mixed> $scenario
 * @param array<string,mixed> $copilotResponse
 * @return array<string,mixed>
 */
function zinesh_copilot_eval_scenario(array $scenario, array $copilotResponse): array
{
    $scenarioId = (string)($scenario['id'] ?? $scenario['scenario_id'] ?? 'unknown');
    $checksEnabled = is_array($scenario['checks'] ?? null) ? $scenario['checks'] : [];
    $expectedChain = (string)($scenario['expected_explainability_chain'] ?? ZINESH_COPILOT_EVAL_EXPLAINABILITY_CHAIN);
    $explanation = (string)($copilotResponse['explanation'] ?? '');

    $responseChain = (string)($copilotResponse['explainability_chain'] ?? '');
    if ($responseChain !== $expectedChain) {
        $chainCheck = ['pass' => false, 'reasons' => ['explainability:response_chain_mismatch']];
    } else {
        $chainCheck = ['pass' => true, 'reasons' => []];
    }

    $results = [
        'prompt_regression' => ['pass' => true, 'reasons' => []],
        'validator_regression' => ['pass' => true, 'reasons' => []],
        'explainability_regression' => $chainCheck,
        'human_control_regression' => ['pass' => true, 'reasons' => []],
    ];

    if (($checksEnabled['prompt_regression'] ?? true) === true) {
        $expected = is_array($scenario['expected_behavior'] ?? null) ? $scenario['expected_behavior'] : [];
        $results['prompt_regression'] = zinesh_copilot_eval_prompt_regression_check($copilotResponse, $expected);
    }

    if (($checksEnabled['validator_regression'] ?? true) === true) {
        $validator = zinesh_copilot_validate_response($explanation, is_array($copilotResponse['copilot_context'] ?? null) ? $copilotResponse['copilot_context'] : []);
        $regression = zinesh_copilot_eval_validator_regression_check($explanation);
        $reasons = array_merge(
            $validator['valid'] ? [] : array_map(static fn(string $r): string => 'runtime_validator:' . $r, $validator['reasons']),
            $regression['pass'] ? [] : $regression['reasons'],
        );
        $results['validator_regression'] = ['pass' => $reasons === [], 'reasons' => $reasons];
    }

    if (($checksEnabled['explainability_regression'] ?? true) === true) {
        $intent = (string)($scenario['intent'] ?? 'overview');
        if ($intent === 'overview') {
            $explainCheck = ['pass' => $chainCheck['pass'], 'reasons' => $chainCheck['reasons']];
        } elseif (in_array($intent, ['context_chain', 'signal_chain', 'event_chain'], true)) {
            $keyword = match ($intent) {
                'context_chain' => 'context',
                'signal_chain' => 'signal',
                'event_chain' => 'event',
                default => '',
            };
            $hasKeyword = str_contains(mb_strtolower($explanation, 'UTF-8'), $keyword);
            $explainCheck = [
                'pass' => $chainCheck['pass'] && $hasKeyword,
                'reasons' => array_merge(
                    $chainCheck['pass'] ? [] : ['explainability:response_chain_mismatch'],
                    $hasKeyword ? [] : ["explainability:missing_link:{$keyword}"],
                ),
            ];
        } else {
            $explainCheck = zinesh_copilot_eval_explainability_check($explanation, $expectedChain);
            if (!$chainCheck['pass']) {
                $explainCheck['pass'] = false;
                $explainCheck['reasons'] = array_merge($explainCheck['reasons'], $chainCheck['reasons']);
            }
        }
        $results['explainability_regression'] = $explainCheck;
    }

    if (($checksEnabled['human_control_regression'] ?? true) === true) {
        $results['human_control_regression'] = zinesh_copilot_eval_human_control_check($explanation);
        $forbidden = is_array($scenario['forbidden_behavior']['phrases'] ?? null) ? $scenario['forbidden_behavior']['phrases'] : [];
        foreach ($forbidden as $phrase) {
            $phrase = (string)$phrase;
            if ($phrase !== '' && str_contains(mb_strtolower($explanation, 'UTF-8'), mb_strtolower($phrase, 'UTF-8'))) {
                $results['human_control_regression']['pass'] = false;
                $results['human_control_regression']['reasons'][] = "forbidden_behavior:{$phrase}";
            }
        }
    }

    $failures = [];
    foreach ($results as $name => $result) {
        if (!($result['pass'] ?? false)) {
            foreach ($result['reasons'] as $reason) {
                $failures[] = "{$name}:{$reason}";
            }
        }
    }

    return [
        'scenario_id' => $scenarioId,
        'title' => (string)($scenario['title'] ?? ''),
        'intent' => (string)($scenario['intent'] ?? 'overview'),
        'status' => $failures === [] ? 'PASS' : 'FAIL',
        'checks' => [
            'prompt_regression' => ($results['prompt_regression']['pass'] ?? false) ? 'PASS' : 'FAIL',
            'validator_regression' => ($results['validator_regression']['pass'] ?? false) ? 'PASS' : 'FAIL',
            'explainability_regression' => ($results['explainability_regression']['pass'] ?? false) ? 'PASS' : 'FAIL',
            'human_control_regression' => ($results['human_control_regression']['pass'] ?? false) ? 'PASS' : 'FAIL',
        ],
        'failures' => $failures,
        'copilot_response' => [
            'provider' => (string)($copilotResponse['provider'] ?? ''),
            'validation_status' => (string)($copilotResponse['validation_status'] ?? ''),
            'explainability_chain' => $responseChain,
        ],
    ];
}

/**
 * Prompt Builder dosyasından sürüm parmak izi (Prompt Builder değiştirilmez).
 */
function zinesh_copilot_eval_prompt_version(): string
{
    $path = __DIR__ . '/copilot_prompt_builder.php';
    if (!is_readable($path)) {
        return 'unknown';
    }
    return hash('sha256', (string)file_get_contents($path));
}

/**
 * @param list<array<string,mixed>> $scenarios
 */
function zinesh_copilot_eval_dataset_fingerprint(array $scenarios, ?string $datasetDir = null): string
{
    $payload = [];
    foreach ($scenarios as $scenario) {
        $id = (string)($scenario['id'] ?? $scenario['scenario_id'] ?? '');
        $payload[] = [
            'id' => $id,
            'intent' => (string)($scenario['intent'] ?? ''),
            'hash' => hash('sha256', json_encode($scenario, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }
    usort($payload, static fn(array $a, array $b): int => strcmp((string)$a['id'], (string)$b['id']));
    if ($datasetDir !== null && $datasetDir !== '') {
        $payload[] = ['dataset_dir' => $datasetDir];
    }
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * @param list<array<string,mixed>> $scenarios
 */
function zinesh_copilot_eval_dataset_version(array $scenarios): string
{
    foreach ($scenarios as $scenario) {
        $version = trim((string)($scenario['dataset_version'] ?? ''));
        if ($version !== '') {
            return $version;
        }
    }
    return ZINESH_COPILOT_EVAL_DATASET_VERSION;
}

/**
 * @param list<array<string,mixed>> $scenarioResults
 * @param list<array<string,mixed>> $scenarios
 * @return array<string,int>
 */
function zinesh_copilot_eval_build_statistics(array $scenarioResults, array $scenarios): array
{
    $stats = [
        'overview' => 0,
        'why_observation' => 0,
        'evidence' => 0,
        'context_chain' => 0,
        'signal_chain' => 0,
        'event_chain' => 0,
        'validator_fixture' => 0,
    ];

    foreach ($scenarios as $scenario) {
        if (($scenario['evaluation_mode'] ?? '') === 'validator_fixture') {
            $stats['validator_fixture']++;
            continue;
        }
        $intent = (string)($scenario['intent'] ?? 'overview');
        if (isset($stats[$intent])) {
            $stats[$intent]++;
        }
    }

    return $stats;
}

/**
 * @param list<array<string,mixed>> $scenarioResults
 * @return list<array{scenario_id:string,duration_ms:int,status:string}>
 */
function zinesh_copilot_eval_scenario_metrics(array $scenarioResults): array
{
    $metrics = [];
    foreach ($scenarioResults as $row) {
        $metrics[] = [
            'scenario_id' => (string)($row['scenario_id'] ?? ''),
            'duration_ms' => (int)($row['duration_ms'] ?? 0),
            'status' => (string)($row['status'] ?? ''),
        ];
    }
    return $metrics;
}

/**
 * @param list<array<string,mixed>> $scenarioResults
 * @return array<string,array{total:int,pass:int,fail:int,status:string}>
 */
function zinesh_copilot_eval_build_coverage(array $scenarioResults): array
{
    $coverage = [];
    foreach (ZINESH_COPILOT_EVAL_COVERAGE_INTENTS as $intent) {
        $coverage[$intent] = ['total' => 0, 'pass' => 0, 'fail' => 0, 'status' => 'UNTESTED'];
    }

    foreach ($scenarioResults as $row) {
        $intent = (string)($row['intent'] ?? 'overview');
        if (!isset($coverage[$intent])) {
            $coverage[$intent] = ['total' => 0, 'pass' => 0, 'fail' => 0, 'status' => 'UNTESTED'];
        }
        $coverage[$intent]['total']++;
        if (($row['status'] ?? '') === 'PASS') {
            $coverage[$intent]['pass']++;
        } else {
            $coverage[$intent]['fail']++;
        }
    }

    foreach ($coverage as $intent => $stats) {
        if ($stats['total'] === 0) {
            $coverage[$intent]['status'] = 'UNTESTED';
        } elseif ($stats['fail'] === 0) {
            $coverage[$intent]['status'] = 'PASS';
        } else {
            $coverage[$intent]['status'] = 'FAIL';
        }
    }

    return $coverage;
}

/**
 * @param list<array<string,mixed>> $scenarioResults
 * @param array<string,mixed> $meta
 * @return array<string,mixed>
 */
function zinesh_copilot_eval_build_report(array $scenarioResults, array $meta = []): array
{
    $passCount = 0;
    $failCount = 0;
    $aggregate = [
        'prompt_regression' => true,
        'validator_regression' => true,
        'explainability_regression' => true,
        'human_control_regression' => true,
    ];

    foreach ($scenarioResults as $row) {
        if (($row['status'] ?? '') === 'PASS') {
            $passCount++;
        } else {
            $failCount++;
        }
        $checks = is_array($row['checks'] ?? null) ? $row['checks'] : [];
        foreach ($aggregate as $key => $val) {
            if ($val && (($checks[$key] ?? 'FAIL') !== 'PASS')) {
                $aggregate[$key] = false;
            }
        }
    }

    $report = [
        'evaluation_version' => ZINESH_COPILOT_EVALUATION_VERSION,
        'prompt_version' => (string)($meta['prompt_version'] ?? zinesh_copilot_eval_prompt_version()),
        'provider' => (string)($meta['provider'] ?? 'mock'),
        'provider_model' => $meta['provider_model'] ?? null,
        'dataset_version' => (string)($meta['dataset_version'] ?? ZINESH_COPILOT_EVAL_DATASET_VERSION),
        'dataset_fingerprint' => (string)($meta['dataset_fingerprint'] ?? ''),
        'execution_ms' => (int)($meta['execution_ms'] ?? 0),
        'total' => count($scenarioResults),
        'pass' => $passCount,
        'fail' => $failCount,
        'total_scenarios' => count($scenarioResults),
        'pass_count' => $passCount,
        'fail_count' => $failCount,
        'coverage' => zinesh_copilot_eval_build_coverage($scenarioResults),
        'statistics' => is_array($meta['statistics'] ?? null) ? $meta['statistics'] : [],
        'scenario_metrics' => zinesh_copilot_eval_scenario_metrics($scenarioResults),
        'prompt_regression' => $aggregate['prompt_regression'] ? 'PASS' : 'FAIL',
        'validator_regression' => $aggregate['validator_regression'] ? 'PASS' : 'FAIL',
        'explainability_regression' => $aggregate['explainability_regression'] ? 'PASS' : 'FAIL',
        'human_control_regression' => $aggregate['human_control_regression'] ? 'PASS' : 'FAIL',
        'overall' => $failCount === 0 ? 'PASS' : 'FAIL',
        'scenarios' => $scenarioResults,
    ];
    $report['report_fingerprint'] = zinesh_copilot_eval_report_fingerprint($report);
    $report['fingerprint'] = $report['report_fingerprint'];

    return $report;
}

/** @param array<string,mixed> $report */
function zinesh_copilot_eval_report_fingerprint(array $report): string
{
    $payload = [
        'evaluation_version' => $report['evaluation_version'] ?? '',
        'prompt_version' => $report['prompt_version'] ?? '',
        'provider' => $report['provider'] ?? '',
        'provider_model' => $report['provider_model'] ?? null,
        'dataset_fingerprint' => $report['dataset_fingerprint'] ?? '',
        'dataset_version' => $report['dataset_version'] ?? '',
        'total' => $report['total'] ?? 0,
        'pass' => $report['pass'] ?? 0,
        'fail' => $report['fail'] ?? 0,
        'overall' => $report['overall'] ?? '',
        'coverage' => $report['coverage'] ?? [],
        'scenario_statuses' => array_map(
            static fn(array $row): string => (string)($row['scenario_id'] ?? '') . ':' . (string)($row['status'] ?? ''),
            is_array($report['scenarios'] ?? null) ? $report['scenarios'] : [],
        ),
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * @return list<array<string,mixed>>
 */
function zinesh_copilot_eval_format_report_lines(array $report): array
{
    $lines = [
        'Copilot Evaluation Report v' . (string)($report['evaluation_version'] ?? ''),
        'Prompt Version: ' . substr((string)($report['prompt_version'] ?? ''), 0, 16) . '…',
        'Provider: ' . (string)($report['provider'] ?? '') . ' / ' . (string)($report['provider_model'] ?? 'n/a'),
        'Dataset Version: ' . (string)($report['dataset_version'] ?? ''),
        'Dataset Fingerprint: ' . substr((string)($report['dataset_fingerprint'] ?? ''), 0, 16) . '…',
        'Execution: ' . (string)($report['execution_ms'] ?? 0) . ' ms',
        'Report Fingerprint: ' . substr((string)($report['report_fingerprint'] ?? ''), 0, 16) . '…',
        'Toplam Senaryo: ' . (string)($report['total'] ?? $report['total_scenarios'] ?? 0),
        'PASS: ' . (string)($report['pass'] ?? $report['pass_count'] ?? 0),
        'FAIL: ' . (string)($report['fail'] ?? $report['fail_count'] ?? 0),
        'Explainability: ' . (string)($report['explainability_regression'] ?? ''),
        'Validator: ' . (string)($report['validator_regression'] ?? ''),
        'Human Control: ' . (string)($report['human_control_regression'] ?? ''),
        'Prompt Regression: ' . (string)($report['prompt_regression'] ?? ''),
        'Overall: ' . (string)($report['overall'] ?? ''),
    ];
    $coverage = is_array($report['coverage'] ?? null) ? $report['coverage'] : [];
    foreach (ZINESH_COPILOT_EVAL_COVERAGE_INTENTS as $intent) {
        $row = is_array($coverage[$intent] ?? null) ? $coverage[$intent] : [];
        $lines[] = 'Coverage ' . $intent . ': ' . (string)($row['status'] ?? 'UNTESTED')
            . ' (' . (string)($row['pass'] ?? 0) . '/' . (string)($row['total'] ?? 0) . ')';
    }
    foreach (is_array($report['scenarios'] ?? null) ? $report['scenarios'] : [] as $scenario) {
        $lines[] = 'Scenario ' . (string)($scenario['scenario_id'] ?? '')
            . ' — ' . (string)($scenario['status'] ?? '')
            . ' (' . (string)($scenario['duration_ms'] ?? 0) . ' ms)';
    }
    return $lines;
}
