<?php
declare(strict_types=1);

require_once __DIR__ . '/oauth_lib.php';

/** Firebase Identity Toolkit — idToken doğrulama (accounts:lookup). */
function zinesh_firebase_lookup_id_token(string $idToken): ?array
{
    $oauth = zinesh_google_oauth_config();
    $apiKey = trim((string)($oauth['firebase_web_api_key'] ?? ''));
    if ($apiKey === '' || $idToken === '') {
        return null;
    }

    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . urlencode($apiKey);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['idToken' => $idToken]),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['users'][0]) || !is_array($data['users'][0])) {
        return null;
    }

    return $data['users'][0];
}
