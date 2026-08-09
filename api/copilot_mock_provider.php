<?php
declare(strict_types=1);

require_once __DIR__ . '/copilot_provider_interface.php';
require_once __DIR__ . '/copilot_context_builder.php';

function zinesh_copilot_mock_event_label(string $eventType): string
{
    $map = [
        'terms_proposed' => 'Müzakere başladı',
        'changes_requested' => 'Revizyon talep edildi',
        'counter_offer_created' => 'Karşı teklif',
        'terms_accepted' => 'Sözleşme kabul edildi',
        'escrow_locked' => 'Emanet kilitlendi',
        'settlement_completed' => 'Ödeme tamamlandı',
        'dispute_opened' => 'Uyuşmazlık açıldı',
    ];
    $key = trim($eventType);
    return $map[$key] ?? ($key !== '' ? $key : 'Olay');
}

/** @param array<string,mixed> $context */
function zinesh_copilot_mock_explain_overview(array $context): array
{
    $rows = is_array($context['observations'] ?? null) ? $context['observations'] : [];
    if ($rows === []) {
        return [
            'text' => 'Bu oda için Risk Engine paketinde henüz Risk Observation bulunmuyor.',
            'sources' => ['observations' => []],
        ];
    }
    $lines = ['Bu odada Risk Engine tarafından üretilmiş gözlemler:'];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['observation_id'] ?? '');
        $title = (string)($row['title'] ?? '');
        $confidence = (string)($row['confidence'] ?? '');
        $lines[] = "- {$id}" . ($title !== '' ? " — {$title}" : '')
            . ($confidence !== '' ? " (kanıt tamlığı: {$confidence})" : '');
    }
    return [
        'text' => implode("\n", $lines),
        'sources' => ['observations' => $rows],
    ];
}

/** @param array<string,mixed> $sources */
function zinesh_copilot_mock_explain_why(array $sources): array
{
    $obs = is_array($sources['observation'] ?? null) ? $sources['observation'] : [];
    $id = (string)($obs['observation_id'] ?? '');
    $title = (string)($obs['title'] ?? '');
    $description = (string)($obs['description'] ?? '');

    $lines = ["Risk Observation {$id}" . ($title !== '' ? " ({$title})" : '') . ' şu kanıt zincirine dayanır:'];
    $lines[] = ZINESH_COPILOT_EXPLAINABILITY_CHAIN;
    if ($description !== '') {
        $lines[] = "Açıklama: {$description}";
    }

    foreach (['contexts' => 'Context', 'signals' => 'Signal'] as $key => $label) {
        $items = is_array($sources[$key] ?? null) ? $sources[$key] : [];
        if ($items === []) {
            continue;
        }
        $lines[] = "{$label} katmanı:";
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = (string)($item['context_id'] ?? $item['signal_id'] ?? '');
            $itemTitle = (string)($item['title'] ?? $item['description'] ?? '');
            $lines[] = '- ' . $itemId . ($itemTitle !== '' ? " — {$itemTitle}" : '');
        }
    }

    $metrics = is_array($sources['metrics'] ?? null) ? $sources['metrics'] : [];
    if ($metrics !== []) {
        $lines[] = 'Metric katmanı:';
        foreach ($metrics as $metricKey => $value) {
            $lines[] = "- {$metricKey}: " . (is_scalar($value) ? (string)$value : json_encode($value));
        }
    }

    $events = is_array($sources['events'] ?? null) ? $sources['events'] : [];
    if ($events !== []) {
        $lines[] = 'Event katmanı:';
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $type = (string)($event['event_type'] ?? $event['type'] ?? '');
            $at = (string)($event['created_at'] ?? '');
            $lines[] = '- ' . zinesh_copilot_mock_event_label($type) . ($at !== '' ? " ({$at})" : '');
        }
    }

    return ['text' => implode("\n", $lines), 'sources' => $sources];
}

final class ZineshCopilotMockProvider implements ZineshCopilotProviderInterface
{
    public function providerId(): string
    {
        return 'mock';
    }

    /** @param array<string,mixed> $context */
    public function complete(array $prompt, array $context): array
    {
        $intent = (string)($prompt['intent'] ?? $context['intent'] ?? 'overview');
        $sources = is_array($context['focus_sources'] ?? null) ? $context['focus_sources'] : [];

        $payload = match ($intent) {
            'overview' => zinesh_copilot_mock_explain_overview($context),
            'why_observation' => zinesh_copilot_mock_explain_why($sources),
            'evidence' => [
                'text' => "Kanıt zinciri:\n" . ZINESH_COPILOT_EXPLAINABILITY_CHAIN,
                'sources' => $sources,
            ],
            'context_chain' => [
                'text' => 'Context katmanı (runtime): ' . json_encode($sources['contexts'] ?? [], JSON_UNESCAPED_UNICODE),
                'sources' => ['contexts' => $sources['contexts'] ?? []],
            ],
            'signal_chain' => [
                'text' => 'Signal katmanı (runtime): ' . json_encode($sources['signals'] ?? [], JSON_UNESCAPED_UNICODE),
                'sources' => ['signals' => $sources['signals'] ?? []],
            ],
            'event_chain' => [
                'text' => 'Event katmanı (runtime): ' . json_encode($sources['events'] ?? [], JSON_UNESCAPED_UNICODE),
                'sources' => ['events' => $sources['events'] ?? []],
            ],
            default => zinesh_copilot_mock_explain_overview($context),
        };

        if ($intent !== 'overview' && $sources === []) {
            $payload = [
                'text' => 'Seçilen Risk Observation pakette bulunamadı.',
                'sources' => [],
            ];
        }

        return [
            'provider' => $this->providerId(),
            'text' => (string)($payload['text'] ?? ''),
            'sources' => is_array($payload['sources'] ?? null) ? $payload['sources'] : [],
        ];
    }
}
