<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/founder_health_lib.php';
require_once __DIR__ . '/founder_platform_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/campaign_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

$input = zinesh_founder_health_auth_input();
if ($method === 'POST') {
    $body = zinesh_input();
    if (is_array($body)) {
        if (empty($input['sessionToken']) && !empty($body['sessionToken'])) {
            $input['sessionToken'] = trim((string)$body['sessionToken']);
        }
        $input = array_merge($input, $body);
    }
}

$user = null;
try {
    $user = zinesh_founder_health_resolve_user($input);
} catch (Throwable) {
    $user = null;
}
if (!$user) {
    zinesh_json_response(['success' => false, 'message' => 'Oturum gerekli.'], 401);
}
if (!zinesh_is_founder($user)) {
    zinesh_json_response(['success' => false, 'message' => 'Yalnızca kurucu hesabı.'], 403);
}

if ($method === 'POST') {
    $action = trim((string)($input['action'] ?? ''));
    if ($action === 'set_balance') {
        $lookup = trim((string)($input['lookup'] ?? $input['user'] ?? ''));
        $amount = (float)($input['amount'] ?? 0);
        $mode = trim((string)($input['mode'] ?? 'add'));
        $note = trim((string)($input['note'] ?? ''));
        $result = zinesh_admin_set_user_balance($lookup, $amount, $mode, $note);
        zinesh_json_response([
            'success' => !empty($result['ok']),
            'message' => (string)($result['message'] ?? ''),
            'wallet' => $result['wallet'] ?? null,
            'user' => $result['user'] ?? null,
            'stats' => zinesh_founder_platform_stats(),
        ], !empty($result['ok']) ? 200 : 400);
    }
    zinesh_json_response(['success' => false, 'message' => 'Bilinmeyen işlem.'], 400);
}

zinesh_json_response([
    'success' => true,
    'stats' => zinesh_founder_platform_stats(),
]);
