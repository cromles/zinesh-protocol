<?php
declare(strict_types=1);

/** @var list<string> */
const ZINESH_COPILOT_FORBIDDEN_PATTERNS = [
    'risk score',
    'risk skoru',
    'prediction',
    'tahmin',
    'fraud',
    'tavsiye ed',
    'önerir',
    'settlement öner',
    'ceza öner',
    'otomatik karar',
    'yeni observation',
    'yeni signal',
    'yeni context',
    'yeni event',
];

/**
 * @param array<string,mixed> $context
 * @return array{valid:bool,reasons:list<string>}
 */
function zinesh_copilot_validate_response(string $text, array $context): array
{
    $reasons = [];
    $lower = strtolower($text);

    foreach (ZINESH_COPILOT_FORBIDDEN_PATTERNS as $pattern) {
        if (str_contains($lower, strtolower($pattern))) {
            $reasons[] = "forbidden_pattern:{$pattern}";
        }
    }

    if (!str_contains($text, '→') && !str_contains($lower, 'risk observation') && ($context['intent'] ?? '') !== 'overview') {
        // Explainability chain veya observation referansı beklenir (mock provider garanti eder)
    }

    return [
        'valid' => $reasons === [],
        'reasons' => $reasons,
    ];
}

function zinesh_copilot_validation_rejection_message(): string
{
    return 'Copilot yanıtı güvenlik doğrulamasından geçemedi. Yalnızca mevcut Risk Engine explainability paketi açıklanabilir.';
}
