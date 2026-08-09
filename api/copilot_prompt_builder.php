<?php
declare(strict_types=1);

require_once __DIR__ . '/copilot_context_builder.php';

const ZINESH_COPILOT_SYSTEM_PROMPT = <<<'PROMPT'
Sen Risk Engine değilsin.
Yeni Signal üretme.
Yeni Context üretme.
Yeni Observation üretme.
Yeni Event üretme.
Risk değerlendirmesi yapma.
Explainability zincirini bozma.
İnsan adına karar verme.
Human in Control ilkesine uy.
Sadece verilen Risk Engine paketini açıkla.
PROMPT;

/**
 * @param array<string,mixed> $context
 * @return array{system:string,user:string,full:string,intent:string}
 */
function zinesh_copilot_prompt_build(array $context): array
{
    $intent = (string)($context['intent'] ?? 'overview');
    $intentInstruction = match ($intent) {
        'why_observation' => 'Bu observation neden oluştu? Explainability zincirini açıkla.',
        'evidence' => 'Bu observation hangi kanıtlara dayanıyor? Evidence chain göster.',
        'context_chain' => 'Hangi context kullanıldı? Yalnızca focus_sources.contexts kullan.',
        'signal_chain' => 'Hangi signal tetikledi? Yalnızca focus_sources.signals kullan.',
        'event_chain' => 'Hangi eventler oluşturdu? Yalnızca focus_sources.events kullan.',
        default => 'Odadaki Risk Observation özetini ver.',
    };

    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $user = "Intent: {$intent}\nGörev: {$intentInstruction}\n\nRisk Engine Context JSON:\n{$contextJson}";

    return [
        'system' => ZINESH_COPILOT_SYSTEM_PROMPT,
        'user' => $user,
        'full' => ZINESH_COPILOT_SYSTEM_PROMPT . "\n\n" . $user,
        'intent' => $intent,
    ];
}
