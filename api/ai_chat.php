<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/ai_chat_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['ok' => false, 'message' => 'method_not_allowed'], 405);
}

$limits = zinesh_config()['rate_limits'] ?? [];
zinesh_rate_limit('ai_chat', (int)($limits['ai_chat'] ?? 20));

$body = zinesh_input();
$authInput = zinesh_ai_auth_input($body);
$user = zinesh_ai_resolve_user($authInput);
if ($user === null) {
    zinesh_json_response([
        'ok' => false,
        'message' => 'Yardımcıyı kullanmak için giriş yapmalısın.',
        'code' => 'auth_required',
    ], 401);
}
$isFounder = zinesh_is_founder($user);

$messages = $body['messages'] ?? null;
if (!is_array($messages)) {
    $single = trim((string)($body['message'] ?? ''));
    if ($single !== '') {
        $messages = [['role' => 'user', 'content' => $single]];
    } else {
        $messages = [];
    }
}

/** @var list<array{role:string,content:string}> $normalized */
$normalized = [];
foreach ($messages as $msg) {
    if (!is_array($msg)) {
        continue;
    }
    $content = trim((string)($msg['content'] ?? ''));
    if ($content === '') {
        continue;
    }
    $role = strtolower(trim((string)($msg['role'] ?? 'user')));
    if (!in_array($role, ['user', 'assistant'], true)) {
        $role = 'user';
    }
    $normalized[] = [
        'role' => $role,
        'content' => function_exists('mb_substr') ? mb_substr($content, 0, 2000) : substr($content, 0, 2000),
    ];
}

if (count($normalized) > 20) {
    $normalized = array_slice($normalized, -20);
}

$result = zinesh_ai_chat($normalized, $isFounder, $user);

if (empty($result['ok'])) {
    $code = (int)($result['http'] ?? 0);
    $status = $code >= 400 && $code < 600 ? $code : 503;
    if (($result['code'] ?? '') === 'empty_message') {
        $status = 400;
    }
    zinesh_json_response([
        'ok' => false,
        'message' => (string)($result['message'] ?? 'Yanıt alınamadı.'),
        'code' => $result['code'] ?? 'error',
    ], $status);
}

zinesh_json_response([
    'ok' => true,
    'reply' => (string)$result['reply'],
    'suggestions' => $result['suggestions'] ?? [],
    'source' => (string)($result['source'] ?? 'local'),
    'loggedIn' => !empty($result['loggedIn']),
    'isFounder' => (bool)$result['isFounder'],
]);
