<?php
declare(strict_types=1);

/**
 * S7.0 — Intelligence Regression Orchestrator (consumer-only).
 * Intelligence regression slice — tek komut → tek rapor → deploy kararı.
 *
 * php api/scripts/run-intelligence-regression.php [--output=PATH] [--skip-frontend]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

const ZINESH_INTELLIGENCE_REGRESSION_VERSION = '1.0';
const ZINESH_INTELLIGENCE_REGRESSION_DIAG_LIMIT_PASS = 12000;
const ZINESH_INTELLIGENCE_REGRESSION_DEFAULT_TIMEOUT_SECONDS = 900;

$apiDir = dirname(__DIR__);
$repoRoot = dirname($apiDir);
$runStartedAt = gmdate('c');

$outputPath = $repoRoot . '/intelligence-regression.json';
$skipFrontend = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--output=')) {
        $outputPath = substr($arg, 9);
        continue;
    }
    if ($arg === '--skip-frontend') {
        $skipFrontend = true;
    }
}

/** @var list<string> */
$reliabilityIssues = [];

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function zinesh_intelligence_regression_section(
    string $id,
    string $label,
    string $status,
    int $exitCode,
    int $executionMs,
    array $details = [],
): array {
    return array_merge([
        'id' => $id,
        'label' => $label,
        'status' => $status,
        'exit_code' => $exitCode,
        'execution_ms' => $executionMs,
    ], $details);
}

function zinesh_intelligence_regression_subprocess_timeout_seconds(): int
{
    $raw = getenv('ZINESH_REGRESSION_SUBPROCESS_TIMEOUT_SECONDS');
    if (is_string($raw) && ctype_digit(trim($raw))) {
        $value = (int)trim($raw);
        if ($value > 0) {
            return $value;
        }
    }
    return ZINESH_INTELLIGENCE_REGRESSION_DEFAULT_TIMEOUT_SECONDS;
}

function zinesh_intelligence_regression_truncate_diagnostic(string $text, bool $failed): string
{
    if ($failed) {
        return $text;
    }
    if (strlen($text) <= ZINESH_INTELLIGENCE_REGRESSION_DIAG_LIMIT_PASS) {
        return $text;
    }
    return substr($text, 0, ZINESH_INTELLIGENCE_REGRESSION_DIAG_LIMIT_PASS)
        . "\n...[truncated at " . ZINESH_INTELLIGENCE_REGRESSION_DIAG_LIMIT_PASS . " bytes]";
}

/**
 * @return array{stdout:string,stderr:string}
 */
function zinesh_intelligence_regression_diagnostics(string $stdout, string $stderr, bool $failed): array
{
    return [
        'stdout' => zinesh_intelligence_regression_truncate_diagnostic($stdout, $failed),
        'stderr' => zinesh_intelligence_regression_truncate_diagnostic($stderr, $failed),
    ];
}

/**
 * @param array{exit_code:int,stdout:string,stderr:string,timed_out?:bool} $run
 * @return array{status:string,exit_code:int,anomalies:list<string>}
 */
function zinesh_intelligence_regression_resolve_section_status(array $run): array
{
    $anomalies = [];
    $exitCode = (int)($run['exit_code'] ?? 1);
    $stdout = (string)($run['stdout'] ?? '');
    $stderr = (string)($run['stderr'] ?? '');

    if (($run['timed_out'] ?? false) === true) {
        $anomalies[] = 'subprocess_timeout';
        $exitCode = 124;
    }
    if ($exitCode === 0 && preg_match('/Failed:\s*(\d+)/', $stdout, $matches) === 1 && (int)$matches[1] > 0) {
        $anomalies[] = 'exit_code_zero_but_summary_failed';
        $exitCode = 1;
    }
    if ($exitCode !== 0 && $stdout === '' && $stderr === '') {
        $anomalies[] = 'empty_subprocess_output';
    }

    $status = ($exitCode === 0 && $anomalies === []) ? 'PASS' : 'FAIL';

    return [
        'status' => $status,
        'exit_code' => $exitCode,
        'anomalies' => $anomalies,
    ];
}

/**
 * @param list<array<string,mixed>> $sections
 */
function zinesh_intelligence_regression_compute_overall(array $sections): string
{
    foreach ($sections as $section) {
        if (($section['status'] ?? '') !== 'PASS') {
            return 'FAIL';
        }
    }
    return 'PASS';
}

function zinesh_intelligence_regression_git_head(string $repoRoot): ?string
{
    $result = zinesh_intelligence_regression_run_command('git rev-parse HEAD', $repoRoot, 30);
    if ($result['exit_code'] !== 0) {
        return null;
    }
    $head = trim($result['stdout']);
    if ($head === '' || preg_match('/^[0-9a-f]{40}$/', $head) !== 1) {
        return null;
    }
    return $head;
}

/**
 * @param array<string,mixed> $report
 * @return array{ok:bool,path:string,error?:string}
 */
function zinesh_intelligence_regression_write_report(array $report, string $outputPath): array
{
    $fingerprintPayload = zinesh_intelligence_regression_strip_timing($report);
    unset($fingerprintPayload['report_fingerprint']);
    $report['report_fingerprint'] = hash('sha256', json_encode($fingerprintPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return ['ok' => false, 'path' => $outputPath, 'error' => 'json_encode_failed'];
    }

    $bytes = file_put_contents($outputPath, $encoded . "\n");
    if ($bytes === false) {
        return ['ok' => false, 'path' => $outputPath, 'error' => 'file_put_contents_failed'];
    }

    $decoded = json_decode((string)file_get_contents($outputPath), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'path' => $outputPath, 'error' => 'artifact_json_invalid'];
    }

    return ['ok' => true, 'path' => $outputPath];
}

/**
 * @return array{exit_code:int,execution_ms:int,stdout:string,stderr:string,timed_out:bool}
 */
function zinesh_intelligence_regression_run_command(string $command, string $cwd, ?int $timeoutSeconds = null): array
{
    $timeoutSeconds = $timeoutSeconds ?? zinesh_intelligence_regression_subprocess_timeout_seconds();
    $started = hrtime(true);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptor, $pipes, $cwd);
    if (!is_resource($proc)) {
        return [
            'exit_code' => 1,
            'execution_ms' => 0,
            'stdout' => '',
            'stderr' => 'proc_open failed',
            'timed_out' => false,
        ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $deadline = time() + $timeoutSeconds;

    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;
        stream_select($read, $write, $except, 1);

        foreach ($read as $pipe) {
            $chunk = stream_get_contents($pipe);
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }
            if ($pipe === $pipes[1]) {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
        }

        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if (time() >= $deadline) {
            proc_terminate($proc);
            $timedOut = true;
            break;
        }
    }

    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($proc);
    $executionMs = (int)round((hrtime(true) - $started) / 1_000_000);

    if ($timedOut) {
        $stderr .= "\n[orchestrator] subprocess timeout after {$timeoutSeconds}s";
        return [
            'exit_code' => 124,
            'execution_ms' => max(0, $executionMs),
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timed_out' => true,
        ];
    }

    return [
        'exit_code' => (int)$exitCode,
        'execution_ms' => max(0, $executionMs),
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => false,
    ];
}

/** @return array{exit_code:int,execution_ms:int,stdout:string,stderr:string,timed_out:bool} */
function zinesh_intelligence_regression_run_php_script(string $apiDir, string $scriptRel): array
{
    $script = $apiDir . '/' . ltrim($scriptRel, '/');
    if (!is_file($script)) {
        return [
            'exit_code' => 1,
            'execution_ms' => 0,
            'stdout' => '',
            'stderr' => "missing script: {$scriptRel}",
            'timed_out' => false,
        ];
    }
    $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    return zinesh_intelligence_regression_run_command(
        escapeshellarg($php) . ' ' . escapeshellarg($script),
        $apiDir
    );
}

/** @return array<string,mixed> */
function zinesh_intelligence_regression_parse_eval_telemetry(string $stdout): array
{
    foreach (explode("\n", $stdout) as $line) {
        if (!str_starts_with($line, 'ZINESH_EVAL_TELEMETRY_JSON:')) {
            continue;
        }
        $json = substr($line, strlen('ZINESH_EVAL_TELEMETRY_JSON:'));
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : ['error' => 'invalid_telemetry_json'];
    }

    return ['error' => 'telemetry_line_missing'];
}

/**
 * @param mixed $value
 * @return mixed
 */
function zinesh_intelligence_regression_strip_timing($value)
{
    if (!is_array($value)) {
        return $value;
    }
    $excludeKeys = [
        'execution_ms',
        'duration_ms',
        'generated_at',
        'started_at',
        'finished_at',
        'source_started_at',
        'source_git_head',
        'stdout',
        'stderr',
        'diagnostics',
        'reliability',
    ];
    $out = [];
    foreach ($value as $key => $child) {
        if (in_array((string)$key, $excludeKeys, true)) {
            continue;
        }
        $out[$key] = zinesh_intelligence_regression_strip_timing($child);
    }
    return $out;
}

/** @return array<string,mixed> */
function zinesh_intelligence_regression_collect_openai_smoke(string $apiDir): array
{
    require_once $apiDir . '/providers/openai_config.php';
    require_once $apiDir . '/providers/openai_client.php';
    require_once $apiDir . '/providers/openai_provider.php';
    require_once $apiDir . '/copilot_provider_registry.php';
    require_once $apiDir . '/copilot_response_validator.php';

    $prevProvider = getenv('ZINESH_COPILOT_PROVIDER');
    $prevKey = getenv('OPENAI_API_KEY');
    $prevModel = getenv('ZINESH_OPENAI_MODEL');

    putenv('ZINESH_COPILOT_PROVIDER=');
    $defaultProvider = zinesh_copilot_resolve_provider();
    $defaultId = $defaultProvider->providerId();

    putenv('ZINESH_COPILOT_PROVIDER=openai');
    putenv('OPENAI_API_KEY=smoke-stub-key');
    putenv('ZINESH_OPENAI_MODEL=gpt-smoke');
    $configured = zinesh_openai_provider_is_configured();
    $resolvedName = zinesh_copilot_provider_name();
    $resolved = zinesh_copilot_resolve_provider();
    $validatorPresent = function_exists('zinesh_copilot_validate_response');

    putenv('OPENAI_API_KEY=');
    $fallback = zinesh_copilot_resolve_provider();

    if ($prevProvider !== false) {
        putenv('ZINESH_COPILOT_PROVIDER=' . $prevProvider);
    } else {
        putenv('ZINESH_COPILOT_PROVIDER');
    }
    if ($prevKey !== false) {
        putenv('OPENAI_API_KEY=' . $prevKey);
    } else {
        putenv('OPENAI_API_KEY');
    }
    if ($prevModel !== false) {
        putenv('ZINESH_OPENAI_MODEL=' . $prevModel);
    } else {
        putenv('ZINESH_OPENAI_MODEL');
    }

    return [
        'default_provider' => $defaultId,
        'provider_resolved' => $resolved->providerId(),
        'provider_name' => $resolvedName,
        'config_loaded' => $configured,
        'validator_path' => $validatorPresent,
        'missing_key_fallback' => $fallback->providerId(),
    ];
}

/**
 * @param list<array<string,mixed>> $sections
 * @param list<string> $reliabilityIssues
 * @return array<string,mixed>
 */
function zinesh_intelligence_regression_build_report(
    string $apiDir,
    string $repoRoot,
    string $runStartedAt,
    array $sections,
    array $reliabilityIssues,
    array $evalTelemetry,
    array $openaiSmoke,
    ?string $openaiSmokeError,
    bool $skipFrontend,
): array {
    require_once $apiDir . '/architecture_validation_lib.php';

    $gitHead = zinesh_intelligence_regression_git_head($repoRoot);
    $githubRunId = getenv('GITHUB_RUN_ID');
    $githubWorkflow = getenv('GITHUB_WORKFLOW');
    $overall = zinesh_intelligence_regression_compute_overall($sections);
    if ($reliabilityIssues !== []) {
        $overall = 'FAIL';
    }

    return [
        'intelligence_regression_version' => ZINESH_INTELLIGENCE_REGRESSION_VERSION,
        'report_kind' => 'intelligence_regression_slice',
        'generated_at' => gmdate('c'),
        'source_started_at' => $runStartedAt,
        'source_git_head' => $gitHead,
        'overall' => $overall,
        'scope' => [
            'description' => 'S7 orchestrator paketleri; tam trust intelligence stack degil',
            'sections_executed' => array_map(
                static fn(array $row): string => (string)($row['id'] ?? ''),
                $sections
            ),
            'not_executed' => [
                'trust_metrics_e2e',
                'trust_signal_runtime_e2e',
                'context_resolver_e2e',
                'risk_observation_runtime_e2e',
                'actor_trust_e2e',
                'ai_context_e2e',
                'escrow_memory_e2e',
                'trust_timeline_frontend_e2e',
            ],
        ],
        'sections' => $sections,
        'reliability' => [
            'artifact_required' => true,
            'issues' => array_values($reliabilityIssues),
        ],
        'telemetry' => [
            'architecture_validation' => [
                'validation_suite_version' => ZINESH_ARCHITECTURE_VALIDATION_VERSION,
                'authority' => 'architecture_compliance',
            ],
            'copilot_evaluation' => $evalTelemetry,
            'openai_smoke' => $openaiSmokeError === null
                ? $openaiSmoke
                : ['error' => $openaiSmokeError],
            'ci' => array_filter([
                'run_id' => is_string($githubRunId) && $githubRunId !== '' ? $githubRunId : null,
                'workflow' => is_string($githubWorkflow) && $githubWorkflow !== '' ? $githubWorkflow : null,
            ], static fn($value): bool => $value !== null),
        ],
        'governance' => [
            'architecture_validation_authority' => 'architecture_compliance',
            'copilot_evaluation_authority' => 'copilot_quality_regression',
            'authorities_distinct' => true,
            'notes' => 'Architecture Validation mimari uyumu doğrular; Copilot Evaluation kalite regresyonunu doğrular. Birbirinin yerine geçmez.',
        ],
        'known_limitations' => [
            'freshness' => 'Deploy gate generated_at, report_fingerprint, section PASS listesi ve source_git_head dogrular',
            'scope' => 'Bu rapor intelligence regression slice kapsamındadır; Architecture Validation suite regression_packages dosya varlığını doğrular, tüm e2e paketlerini çalıştırmaz',
            'openai_smoke' => 'Gerçek OpenAI API çağrısı yapılmaz; yalnızca config resolve ve validator yolu doğrulanır',
            'eval_telemetry' => 'Copilot evaluation telemetry e2e stdout satırından alınır; LLM yanıtı artifact\'a yazılmaz',
            'frontend' => $skipFrontend ? 'Frontend contracts bu çalıştırmada atlandı' : 'Frontend contracts npm üzerinden çalıştırılır',
        ],
    ];
}

echo "=== Intelligence Regression Slice v" . ZINESH_INTELLIGENCE_REGRESSION_VERSION . " ===\n\n";

$pipeline = [
    ['id' => 'architecture_validation', 'label' => 'Architecture Validation', 'script' => 'scripts/e2e-architecture-validation-test.php'],
    ['id' => 'risk_engine', 'label' => 'Risk Engine', 'script' => 'scripts/e2e-risk-engine-test.php'],
    ['id' => 'risk_engine_backend', 'label' => 'Risk Engine Backend', 'script' => 'scripts/e2e-risk-engine-backend-test.php'],
    ['id' => 'copilot_framework', 'label' => 'Copilot Framework', 'script' => 'scripts/e2e-copilot-framework-test.php'],
    ['id' => 'openai_provider_stub', 'label' => 'OpenAI Provider (Stub)', 'script' => 'scripts/e2e-openai-provider-test.php'],
    ['id' => 'copilot_evaluation', 'label' => 'Copilot Evaluation', 'script' => 'scripts/e2e-copilot-evaluation-test.php'],
];

$sections = [];
$evalTelemetry = ['error' => 'copilot_evaluation_not_run'];
$openaiSmoke = [];
$openaiSmokeError = null;
$artifactWritten = false;
$processExitCode = 1;

try {
    foreach ($pipeline as $step) {
        try {
            $run = zinesh_intelligence_regression_run_php_script($apiDir, $step['script']);
        } catch (Throwable $e) {
            $run = [
                'exit_code' => 1,
                'execution_ms' => 0,
                'stdout' => '',
                'stderr' => $e->getMessage(),
                'timed_out' => false,
            ];
            $reliabilityIssues[] = 'section_exception:' . $step['id'];
        }

        $resolved = zinesh_intelligence_regression_resolve_section_status($run);
        $status = $resolved['status'];
        foreach ($resolved['anomalies'] as $anomaly) {
            $reliabilityIssues[] = $step['id'] . ':' . $anomaly;
        }

        if ($status === 'FAIL') {
            fwrite(STDERR, "\n=== FAIL: {$step['label']} ===\n");
            fwrite(STDERR, (string)$run['stderr']);
            fwrite(STDERR, (string)$run['stdout']);
        }
        if ($step['id'] === 'copilot_evaluation') {
            $evalTelemetry = zinesh_intelligence_regression_parse_eval_telemetry((string)$run['stdout']);
            if (($evalTelemetry['error'] ?? null) !== null && $status === 'PASS') {
                $status = 'FAIL';
                $reliabilityIssues[] = 'copilot_evaluation:telemetry_missing';
            }
        }

        $pad = str_repeat('.', max(1, 32 - strlen($step['label'])));
        echo $step['label'] . ' ' . $pad . ' ' . $status . "\n";

        $sectionDetails = [
            'script' => $step['script'],
            'diagnostics' => zinesh_intelligence_regression_diagnostics(
                (string)$run['stdout'],
                (string)$run['stderr'],
                $status === 'FAIL'
            ),
        ];
        if ($resolved['anomalies'] !== []) {
            $sectionDetails['anomalies'] = $resolved['anomalies'];
        }
        if (($run['timed_out'] ?? false) === true) {
            $sectionDetails['timed_out'] = true;
        }

        $sections[] = zinesh_intelligence_regression_section(
            $step['id'],
            $step['label'],
            $status,
            (int)$resolved['exit_code'],
            (int)$run['execution_ms'],
            $sectionDetails
        );
    }

    $frontendStatus = 'SKIP';
    $frontendExit = 0;
    $frontendMs = 0;
    $frontendDiagnostics = ['stdout' => '', 'stderr' => ''];
    if (!$skipFrontend) {
        try {
            $npmCmd = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'npm.cmd' : 'npm';
            $frontendRun = zinesh_intelligence_regression_run_command(
                escapeshellarg($npmCmd) . ' run test:intelligence-ops-frontend --silent',
                $repoRoot
            );
        } catch (Throwable $e) {
            $frontendRun = [
                'exit_code' => 1,
                'execution_ms' => 0,
                'stdout' => '',
                'stderr' => $e->getMessage(),
                'timed_out' => false,
            ];
            $reliabilityIssues[] = 'frontend_contracts:section_exception';
        }

        $frontendResolved = zinesh_intelligence_regression_resolve_section_status($frontendRun);
        $frontendStatus = $frontendResolved['status'];
        $frontendExit = (int)$frontendResolved['exit_code'];
        $frontendMs = (int)$frontendRun['execution_ms'];
        foreach ($frontendResolved['anomalies'] as $anomaly) {
            $reliabilityIssues[] = 'frontend_contracts:' . $anomaly;
        }
        $frontendDiagnostics = zinesh_intelligence_regression_diagnostics(
            (string)$frontendRun['stdout'],
            (string)$frontendRun['stderr'],
            $frontendStatus === 'FAIL'
        );
        if ($frontendStatus === 'FAIL') {
            fwrite(STDERR, "\n=== FAIL: Frontend Contracts ===\n");
            fwrite(STDERR, $frontendDiagnostics['stderr']);
            fwrite(STDERR, $frontendDiagnostics['stdout']);
        }
    } else {
        $reliabilityIssues[] = 'frontend_contracts:skipped';
    }

    $pad = str_repeat('.', max(1, 32 - strlen('Frontend Contracts')));
    echo 'Frontend Contracts ' . $pad . ' ' . $frontendStatus . "\n";

    $frontendSection = [
        'skipped' => $skipFrontend,
        'diagnostics' => $frontendDiagnostics,
    ];
    if ($skipFrontend) {
        $frontendSection['anomalies'] = ['frontend_skipped'];
    }
    $sections[] = zinesh_intelligence_regression_section(
        'frontend_contracts',
        'Frontend Contracts',
        $frontendStatus,
        $frontendExit,
        $frontendMs,
        $frontendSection
    );

    try {
        $openaiSmoke = zinesh_intelligence_regression_collect_openai_smoke($apiDir);
    } catch (Throwable $e) {
        $openaiSmokeError = $e->getMessage();
        $reliabilityIssues[] = 'openai_smoke:' . $openaiSmokeError;
    }
} catch (Throwable $e) {
    $reliabilityIssues[] = 'orchestrator_exception:' . $e->getMessage();
} finally {
    $report = zinesh_intelligence_regression_build_report(
        $apiDir,
        $repoRoot,
        $runStartedAt,
        $sections,
        $reliabilityIssues,
        $evalTelemetry,
        $openaiSmoke,
        $openaiSmokeError,
        $skipFrontend
    );

    $overall = (string)($report['overall'] ?? 'FAIL');
    echo "\nOverall: {$overall}\n";

    $writeResult = zinesh_intelligence_regression_write_report($report, $outputPath);
    if (($writeResult['ok'] ?? false) === true) {
        $artifactWritten = true;
        echo "Report: {$outputPath}\n";
    } else {
        $reliabilityIssues[] = 'artifact_write:' . (string)($writeResult['error'] ?? 'unknown');
        fwrite(STDERR, "Failed to write regression report: " . (string)($writeResult['error'] ?? 'unknown') . "\n");
    }

    $processExitCode = ($overall === 'PASS' && $artifactWritten) ? 0 : 1;
}

exit($processExitCode);
