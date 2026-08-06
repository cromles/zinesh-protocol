<?php
declare(strict_types=1);

function zinesh_brevo_api_key(): string {
    if (!function_exists('zinesh_load_server_secrets')) {
        return '';
    }
    $secrets = zinesh_load_server_secrets();
    return trim((string)($secrets['brevo_api_key'] ?? ''));
}

/**
 * @return array{ok:bool, error:?string, messageId:?string}
 */
function zinesh_brevo_send_transactional(
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $textBody,
    string $fromEmail,
    string $fromName,
    string $replyToEmail = ''
): array {
    $apiKey = zinesh_brevo_api_key();
    if ($apiKey === '' || $toEmail === '' || $fromEmail === '') {
        return ['ok' => false, 'error' => 'Brevo API anahtarı yok.', 'messageId' => null];
    }

    $payload = [
        'sender' => ['name' => $fromName, 'email' => $fromEmail],
        'to' => [['email' => $toEmail]],
        'subject' => $subject,
        'htmlContent' => $htmlBody,
        'textContent' => $textBody !== '' ? $textBody : strip_tags($htmlBody),
        'tags' => ['zinesh', 'verification'],
    ];
    if ($replyToEmail !== '') {
        $payload['replyTo'] = ['email' => $replyToEmail, 'name' => 'Zinesh Destek'];
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl başlatılamadı', 'messageId' => null];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $raw = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'error' => 'Brevo API bağlantı hatası: ' . $curlErr, 'messageId' => null];
    }

    $data = json_decode((string)$raw, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'ok' => true,
            'error' => null,
            'messageId' => (string)($data['messageId'] ?? ''),
        ];
    }

    $msg = is_array($data) ? (string)($data['message'] ?? $data['code'] ?? $raw) : (string)$raw;
    return ['ok' => false, 'error' => 'Brevo API (' . $httpCode . '): ' . $msg, 'messageId' => null];
}

function zinesh_brevo_api_configured(): bool {
    return zinesh_brevo_api_key() !== '' && function_exists('curl_init');
}
