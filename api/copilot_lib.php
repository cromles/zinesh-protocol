<?php
declare(strict_types=1);

/**
 * Copilot Framework v1.0 — orchestrator (LLM-ready, read-only).
 * Risk Engine → Context Builder → Prompt Builder → Provider → Validator → Public Response
 */
require_once __DIR__ . '/copilot_context_builder.php';
require_once __DIR__ . '/copilot_prompt_builder.php';
require_once __DIR__ . '/copilot_provider_interface.php';
require_once __DIR__ . '/copilot_mock_provider.php';
require_once __DIR__ . '/copilot_provider_registry.php';
require_once __DIR__ . '/copilot_response_validator.php';

const ZINESH_COPILOT_VERSION = '1.0';
const ZINESH_COPILOT_FRAMEWORK_VERSION = '1.0';
const ZINESH_COPILOT_ENDPOINT_VERSION = '1.0';

const ZINESH_COPILOT_HUMAN_DISCLAIMER =
    'Bu açıklama yalnızca bilgilendirme amaçlıdır. Karar, ceza veya işlem önerisi içermez; nihai karar size aittir.';

function zinesh_copilot_default_provider(): ZineshCopilotProviderInterface
{
    return zinesh_copilot_resolve_provider();
}

/**
 * @param array<string,mixed> $engine zinesh_risk_engine_for_room() çıktısı
 * @param array<string,mixed> $riskEnginePublic
 * @return array<string,mixed>
 */
function zinesh_copilot_build_response(
    array $engine,
    string $intent,
    ?string $observationId = null,
    array $riskEnginePublic = [],
    ?ZineshCopilotProviderInterface $provider = null
): array {
    $intent = zinesh_copilot_normalize_intent($intent);
    $observationId = trim((string)$observationId);

    $context = zinesh_copilot_context_build_from_engine($engine, $intent, $observationId);
    $prompt = zinesh_copilot_prompt_build($context);
    $provider = $provider ?? zinesh_copilot_default_provider();

    $providerResult = $provider->complete($prompt, $context);
    $validation = zinesh_copilot_validate_response((string)($providerResult['text'] ?? ''), $context);

    $explanation = (string)($providerResult['text'] ?? '');
    $validationStatus = 'pass';
    if (trim($explanation) === '') {
        $validationStatus = 'rejected';
        $validation['reasons'][] = 'empty_provider_response';
        $explanation = zinesh_copilot_validation_rejection_message();
    } elseif (!$validation['valid']) {
        $validationStatus = 'rejected';
        $explanation = zinesh_copilot_validation_rejection_message();
    }

    $resolvedObservationId = $context['observation_id'] ?? null;

    return [
        'copilot_version' => ZINESH_COPILOT_VERSION,
        'framework_version' => ZINESH_COPILOT_FRAMEWORK_VERSION,
        'endpoint_version' => ZINESH_COPILOT_ENDPOINT_VERSION,
        'provider' => $provider->providerId(),
        'validation_status' => $validationStatus,
        'validation_reasons' => $validation['reasons'],
        'room_id' => (string)($engine['room_id'] ?? ''),
        'actor_id' => $engine['actor_id'] ?? null,
        'intent' => $intent,
        'observation_id' => is_string($resolvedObservationId) && $resolvedObservationId !== '' ? $resolvedObservationId : null,
        'explainability_chain' => ZINESH_COPILOT_EXPLAINABILITY_CHAIN,
        'explanation' => $explanation,
        'sources' => is_array($providerResult['sources'] ?? null) ? $providerResult['sources'] : [],
        'copilot_context' => $context,
        'human_disclaimer' => ZINESH_COPILOT_HUMAN_DISCLAIMER,
        'risk_engine' => $riskEnginePublic,
    ];
}
