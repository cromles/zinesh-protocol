<?php
declare(strict_types=1);

/**
 * Copilot Evaluation e2e.
 * php api/scripts/e2e-copilot-evaluation-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$repoRoot = dirname($apiDir);
$simDir = sys_get_temp_dir() . '/zinesh_copilot_eval_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);
putenv('ZINESH_COPILOT_PROVIDER=mock');

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/risk_engine_lib.php';
require_once $apiDir . '/copilot_evaluation_lib.php';
require_once $apiDir . '/copilot_evaluation_runner.php';

zinesh_ensure_core_data_files();
zinesh_json_write('zinesh_events.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('escrow_rooms.json', []);

$passed = 0;
$failed = 0;

function assert_test(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
    }
}

echo "=== Dataset ===\n";
$datasetDir = $repoRoot . '/docs/copilot_eval';
$scenarios = zinesh_copilot_eval_load_scenarios($datasetDir);
assert_test('dataset loaded', count($scenarios) >= 7);
$datasetFp = zinesh_copilot_eval_dataset_fingerprint($scenarios, $datasetDir);
assert_test('dataset fingerprint', strlen($datasetFp) === 64);

echo "=== Evaluation Runner ===\n";
$report = zinesh_copilot_eval_run_all($datasetDir);
assert_test('report version', ($report['evaluation_version'] ?? '') === '1.0');
assert_test('prompt version set', strlen((string)($report['prompt_version'] ?? '')) === 64);
assert_test('provider mock', ($report['provider'] ?? '') === 'mock');
assert_test('dataset fingerprint in report', ($report['dataset_fingerprint'] ?? '') === $datasetFp);
assert_test('dataset version', ($report['dataset_version'] ?? '') === '1.0');
assert_test('execution ms', (int)($report['execution_ms'] ?? 0) >= 0);
assert_test('statistics', is_array($report['statistics'] ?? null) && (int)($report['statistics']['overview'] ?? 0) >= 1);
assert_test('scenario metrics', is_array($report['scenario_metrics'] ?? null) && count($report['scenario_metrics'] ?? []) >= 7);
assert_test('report fingerprint set', strlen((string)($report['report_fingerprint'] ?? '')) === 64);
assert_test('overall pass', ($report['overall'] ?? '') === 'PASS');
assert_test('validator regression pass', ($report['validator_regression'] ?? '') === 'PASS');
assert_test('explainability regression pass', ($report['explainability_regression'] ?? '') === 'PASS');
assert_test('human control pass', ($report['human_control_regression'] ?? '') === 'PASS');
assert_test('prompt regression pass', ($report['prompt_regression'] ?? '') === 'PASS');

foreach (ZINESH_COPILOT_EVAL_COVERAGE_INTENTS as $intent) {
    $row = is_array($report['coverage'][$intent] ?? null) ? $report['coverage'][$intent] : [];
    assert_test("coverage {$intent}", ($row['status'] ?? '') === 'PASS' && (int)($row['total'] ?? 0) >= 1);
}

foreach ($report['scenarios'] ?? [] as $scenarioRow) {
    $sid = (string)($scenarioRow['scenario_id'] ?? '');
    $status = (string)($scenarioRow['status'] ?? '');
    echo "  Scenario {$sid}: {$status}\n";
    assert_test("scenario {$sid} pass", $status === 'PASS');
}

echo "=== Determinism ===\n";
$rep2 = zinesh_copilot_eval_run_all($datasetDir);
assert_test('same report fingerprint', ($report['report_fingerprint'] ?? '') === ($rep2['report_fingerprint'] ?? ''));

echo "=== Replayability ===\n";
assert_test('replay fingerprint', ($rep2['report_fingerprint'] ?? '') === ($report['report_fingerprint'] ?? ''));

echo "=== Human Control Lib ===\n";
$hcBad = zinesh_copilot_eval_human_control_check('Bu kullanıcı dolandırıcı ve hesabı kapat');
assert_test('human control detects phrase', ($hcBad['pass'] ?? true) === false);
$hcGood = zinesh_copilot_eval_human_control_check('Risk Observation paketi açıklanmıştır');
assert_test('human control pass good', ($hcGood['pass'] ?? false) === true);

echo "=== Validator Regression Lib ===\n";
$vrBad = zinesh_copilot_eval_validator_regression_check('risk score ve prediction');
assert_test('validator regression detects', ($vrBad['pass'] ?? true) === false);

echo "=== Read Only ===\n";
$evalSource = (string)file_get_contents($apiDir . '/copilot_evaluation_runner.php');
assert_test('runner isolated bootstrap', str_contains($evalSource, 'zinesh_copilot_eval_bootstrap_room_setup'));
assert_test('evaluation lib no openai provider', !str_contains((string)file_get_contents($apiDir . '/copilot_evaluation_lib.php'), 'ZineshOpenAI'));

echo "=== Report format ===\n";
$lines = zinesh_copilot_eval_format_report_lines($report);
assert_test('report lines', count($lines) >= 14);

$telemetry = [
    'evaluation_version' => (string)($report['evaluation_version'] ?? ''),
    'prompt_version' => (string)($report['prompt_version'] ?? ''),
    'provider' => (string)($report['provider'] ?? ''),
    'dataset_version' => (string)($report['dataset_version'] ?? ''),
    'dataset_fingerprint' => (string)($report['dataset_fingerprint'] ?? ''),
    'report_fingerprint' => (string)($report['report_fingerprint'] ?? ''),
    'execution_ms' => (int)($report['execution_ms'] ?? 0),
    'overall' => (string)($report['overall'] ?? ''),
];
echo 'ZINESH_EVAL_TELEMETRY_JSON:'
    . json_encode($telemetry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    . "\n";

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
