<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/copilot_provider_interface.php';
require_once __DIR__ . '/openai_client.php';
require_once __DIR__ . '/openai_config.php';

/**
 * OpenAI Copilot Provider v1.0 — yalnızca hazır prompt gönderir; yeni intelligence üretmez.
 */
final class ZineshOpenAICopilotProvider implements ZineshCopilotProviderInterface
{
    public function __construct(
        private readonly ZineshOpenAIClientInterface $client,
        private readonly string $model,
    ) {
    }

    public function providerId(): string
    {
        return 'openai';
    }

    /** @param array<string,mixed> $context */
    private function sourcesFromContext(array $context): array
    {
        $intent = (string)($context['intent'] ?? 'overview');
        if ($intent !== 'overview' && is_array($context['focus_sources'] ?? null)) {
            return $context['focus_sources'];
        }
        if (is_array($context['observations'] ?? null)) {
            return ['observations' => $context['observations']];
        }
        return [];
    }

    /** @param array{system:string,user:string,full:string,intent:string} $prompt */
    public function complete(array $prompt, array $context): array
    {
        $result = $this->client->chatCompletion([
            'model' => $this->model,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => (string)($prompt['system'] ?? '')],
                ['role' => 'user', 'content' => (string)($prompt['user'] ?? '')],
            ],
        ]);

        if (!($result['ok'] ?? false)) {
            return [
                'provider' => $this->providerId(),
                'text' => '',
                'sources' => [],
                'error' => (string)($result['error'] ?? 'openai_failed'),
            ];
        }

        return [
            'provider' => $this->providerId(),
            'text' => (string)($result['text'] ?? ''),
            'sources' => $this->sourcesFromContext($context),
        ];
    }
}

function zinesh_openai_copilot_provider_create(?ZineshOpenAIClientInterface $client = null): ZineshOpenAICopilotProvider
{
    $client = $client ?? zinesh_openai_http_client_create();
    return new ZineshOpenAICopilotProvider($client, zinesh_openai_model());
}
