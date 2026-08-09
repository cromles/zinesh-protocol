<?php
declare(strict_types=1);

/**
 * AI Context API — salt okunur escrow memory görünümü.
 * Escrow motorundan izole; hiçbir state/event dosyasına yazmaz.
 *
 * GET /api/ai_context.php?room_id={id}&sessionToken=...
 */
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/ai_context_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

if (!zinesh_tl_mode_enabled()) {
    zinesh_json_response(['message' => 'AI context yalnızca TL modunda kullanılabilir.', 'tlMode' => false], 403);
}

/** @return array{sessionToken:string,uid:string,email:string} */
function zinesh_ai_context_auth_input(): array
{
    $token = trim((string)($_GET['sessionToken'] ?? ''));
    if ($token === '') {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
    }
    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    }
    if ($token === '' && !empty($_COOKIE['zinesh_session'])) {
        $token = trim((string)$_COOKIE['zinesh_session']);
    }

    return [
        'sessionToken' => $token,
        'uid' => trim((string)($_GET['uid'] ?? '')),
        'email' => trim((string)($_GET['email'] ?? '')),
    ];
}

$roomId = trim((string)($_GET['room_id'] ?? $_GET['roomId'] ?? ''));
if ($roomId === '') {
    zinesh_json_response(['message' => 'room_id gerekli.'], 400);
}

$user = zinesh_require_auth(zinesh_ai_context_auth_input());
$uid = (string)($user['uid'] ?? '');

$room = zinesh_ai_context_room_snapshot($roomId);
if (!$room) {
    zinesh_json_response(['message' => 'Oda bulunamadı.'], 404);
}

if (!zinesh_escrow_room_is_participant($room, $uid)) {
    zinesh_json_response(['message' => 'Bu odaya erişimin yok.'], 403);
}

$context = zinesh_escrow_room_ai_context($roomId);
zinesh_json_response(zinesh_ai_context_public_response($roomId, $context));
