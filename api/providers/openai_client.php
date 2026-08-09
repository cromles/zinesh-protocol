<?php
declare(strict_types=1);

/**
 * OpenAI HTTP istemcisi — Provider katmanından ayrıdır.
 */
interface ZineshOpenAIClientInterface
{
    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,text?:string,error?:string,http_status?:int}
     */
    public function chatCompletion(array $payload): array;
}

final class ZineshOpenAIHttpClient implements ZineshOpenAIClientInterface
{
    public function __construct(
        private readonly string $apiBase,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function chatCompletion(array $payload): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'error' => 'missing_api_key', 'http_status' => 0];
        }

        $url = $this->apiBase . '/chat/completions';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'encode_failed', 'http_status' => 0];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl_unavailable', 'http_status' => 0];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'curl_failed', 'http_status' => $status];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'error' => 'invalid_json', 'http_status' => $status];
        }

        if ($status < 200 || $status >= 300) {
            $message = (string)($decoded['error']['message'] ?? 'openai_http_error');
            return ['ok' => false, 'error' => $message, 'http_status' => $status];
        }

        $choices = $decoded['choices'] ?? [];
        $first = is_array($choices[0] ?? null) ? $choices[0] : [];
        $message = is_array($first['message'] ?? null) ? $first['message'] : [];
        $text = trim((string)($message['content'] ?? ''));

        if ($text === '') {
            return ['ok' => false, 'error' => 'empty_response', 'http_status' => $status];
        }

        return ['ok' => true, 'text' => $text, 'http_status' => $status];
    }
}

/** Test ve deterministik replay için stub istemci. */
final class ZineshOpenAIStubClient implements ZineshOpenAIClientInterface
{
    /** @param array<string,mixed> $responses */
    public function __construct(
        private readonly array $responses = [],
    ) {
    }

    private int $callIndex = 0;

    /** @param array<string,mixed> $payload */
    public function chatCompletion(array $payload): array
    {
        $response = $this->responses[$this->callIndex] ?? $this->responses['default'] ?? [
            'ok' => true,
            'text' => 'Risk Observation paketi mevcut explainability zinciri üzerinden açıklanmıştır.',
        ];
        $this->callIndex++;

        if (!is_array($response)) {
            return ['ok' => false, 'error' => 'stub_invalid', 'http_status' => 0];
        }

        return $response;
    }
}

function zinesh_openai_http_client_create(): ZineshOpenAIHttpClient
{
    require_once __DIR__ . '/openai_config.php';
    return new ZineshOpenAIHttpClient(
        zinesh_openai_api_base(),
        zinesh_openai_api_key(),
        zinesh_openai_timeout_seconds(),
    );
}
