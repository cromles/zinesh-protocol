<?php
declare(strict_types=1);

/**
 * OpenAI Provider e2e (stub client — gerçek API çağrısı yok).
 * php api/scripts/e2e-openai-provider-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_openai_provider_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);
putenv('ZINESH_COPILOT_PROVIDER=openai');
putenv('OPENAI_API_KEY=test-key-stub');
putenv('ZINESH_OPENAI_MODEL=gpt-test');

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/risk_engine_lib.php';
require_once $apiDir . '/risk_engine_endpoint_lib.php';
require_once $apiDir . '/copilot_lib.php';
require_once $apiDir . '/copilot_context_builder.php';
require_once $apiDir . '/copilot_prompt_builder.php';
require_once $apiDir . '/copilot_response_validator.php';
require_once $apiDir . '/providers/openai_config.php';
require_once $apiDir . '/providers/openai_client.php';
require_once $apiDir . '/providers/openai_provider.php';
require_once $apiDir . '/copilot_provider_registry.php';

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

echo "=== Config ===\n";
putenv('ZINESH_COPILOT_PROVIDER=');
$defaultProvider = zinesh_copilot_resolve_provider();
assert_test('default provider mock', $defaultProvider->providerId() === 'mock');

putenv('ZINESH_COPILOT_PROVIDER=openai');
putenv('OPENAI_API_KEY=test-key-stub');
putenv('ZINESH_OPENAI_MODEL=gpt-test');
assert_test('provider name from env', zinesh_copilot_provider_name() === 'openai');
assert_test('api key from env', zinesh_openai_api_key() === 'test-key-stub');
assert_test('model from env', zinesh_openai_model() === 'gpt-test');
assert_test('provider configured', zinesh_openai_provider_is_configured());

zinesh_ensure_core_data_files();
zinesh_json_write('server_secrets.json', [
    'openai_api_key' => 'secret-key',
    'openai_model' => 'gpt-secret',
]);
putenv('OPENAI_API_KEY=');
putenv('ZINESH_OPENAI_MODEL=');
assert_test('api key from server_secrets', zinesh_openai_api_key() === 'secret-key');
assert_test('model from server_secrets', zinesh_openai_model() === 'gpt-secret');
putenv('OPENAI_API_KEY=test-key-stub');
putenv('ZINESH_OPENAI_MODEL=gpt-test');

echo "=== Provider Interface ===\n";
$stub = new ZineshOpenAIStubClient([
    'default' => [
        'ok' => true,
        'text' => 'Risk Observation paketi mevcut explainability zinciri üzerinden açıklanmıştır.',
    ],
]);
$openaiProvider = new ZineshOpenAICopilotProvider($stub, 'gpt-test');
assert_test('implements interface', $openaiProvider instanceof ZineshCopilotProviderInterface);
assert_test('provider id openai', $openaiProvider->providerId() === 'openai');

echo "=== Prompt / Context unchanged ===\n";
$contextBuilderHash = hash_file('sha256', $apiDir . '/copilot_context_builder.php');
$promptBuilderHash = hash_file('sha256', $apiDir . '/copilot_prompt_builder.php');
$validatorHash = hash_file('sha256', $apiDir . '/copilot_response_validator.php');
assert_test('context builder present', is_string($contextBuilderHash) && $contextBuilderHash !== '');
assert_test('prompt builder present', is_string($promptBuilderHash) && $promptBuilderHash !== '');
assert_test('response validator present', is_string($validatorHash) && $validatorHash !== '');

zinesh_ensure_core_data_files();
zinesh_json_write('zinesh_events.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('escrow_rooms.json', []);

$employerUid = 'emp-openai-1';
$workerUid = 'wrk-openai-1';
$roomId = 'room-openai-1';

zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'OpenAI provider test',
    'description' => str_repeat('Test açıklama. ', 8),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    ['id' => 'evt-oai-1', 'room_id' => $roomId, 'event_type' => 'terms_proposed', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-01T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
]);

$engine = zinesh_risk_engine_for_room($roomId, $employerUid);
$public = zinesh_risk_engine_public_response($engine);
$context = zinesh_copilot_context_build_from_engine($engine, 'overview', null);
$prompt = zinesh_copilot_prompt_build($context);
assert_test('prompt system unchanged', str_contains($prompt['system'], 'Risk Engine değilsin'));

echo "=== OpenAI Provider + Validator ===\n";
$good = $openaiProvider->complete($prompt, $context);
$validation = zinesh_copilot_validate_response((string)$good['text'], $context);
assert_test('stub response valid', ($validation['valid'] ?? false) === true);

$badStub = new ZineshOpenAIStubClient([
    'default' => ['ok' => true, 'text' => 'risk score yüksek, settlement öner'],
]);
$badProvider = new ZineshOpenAICopilotProvider($badStub, 'gpt-test');
$bad = $badProvider->complete($prompt, $context);
$badValidation = zinesh_copilot_validate_response((string)$bad['text'], $context);
assert_test('validator rejects bad openai text', ($badValidation['valid'] ?? true) === false);

echo "=== Public Contract ===\n";
$response = zinesh_copilot_build_response($engine, 'overview', null, $public, $openaiProvider);
assert_test('provider openai in response', ($response['provider'] ?? '') === 'openai');
assert_test('validation pass', ($response['validation_status'] ?? '') === 'pass');
assert_test('human disclaimer', str_contains((string)($response['human_disclaimer'] ?? ''), 'karar'));
assert_test('risk_engine passthrough', json_encode($response['risk_engine']) === json_encode($public));

$rejectedResponse = zinesh_copilot_build_response($engine, 'overview', null, $public, $badProvider);
assert_test('rejected uses fallback', ($rejectedResponse['validation_status'] ?? '') === 'rejected');
assert_test('fallback not raw openai', !str_contains((string)($rejectedResponse['explanation'] ?? ''), 'settlement öner'));

echo "=== Mock fallback ===\n";
putenv('ZINESH_COPILOT_PROVIDER=mock');
$mockResolved = zinesh_copilot_resolve_provider();
assert_test('mock default when configured', $mockResolved->providerId() === 'mock');

putenv('ZINESH_COPILOT_PROVIDER=openai');
putenv('OPENAI_API_KEY=');
$fallback = zinesh_copilot_resolve_provider();
assert_test('missing key falls back to mock', $fallback->providerId() === 'mock');

echo "=== Determinism ===\n";
$rep1 = zinesh_copilot_build_response($engine, 'overview', null, $public, $openaiProvider);
$rep2 = zinesh_copilot_build_response($engine, 'overview', null, $public, $openaiProvider);
assert_test('deterministic with stub', json_encode($rep1) === json_encode($rep2));

echo "=== Runtime immutability ===\n";
$engineBefore = json_encode($engine);
zinesh_copilot_build_response($engine, 'overview', null, $public, $openaiProvider);
assert_test('engine not mutated', json_encode($engine) === $engineBefore);

echo "=== Read Only ===\n";
$openaiProviderSource = (string)file_get_contents($apiDir . '/providers/openai_provider.php');
assert_test('no json write in provider', !str_contains($openaiProviderSource, 'zinesh_json_write'));

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
