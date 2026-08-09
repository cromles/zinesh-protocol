<?php
declare(strict_types=1);

/**
 * Trust Metrics API — salt okunur güven metrikleri.
 * Escrow motorundan izole; karar vermez, yalnızca ölçüm sunar.
 *
 * GET /api/trust_metrics.php?room_id={id}&sessionToken=...
 */
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/trust_metrics_lib.php';

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
    zinesh_json_response(['message' => 'Trust metrics yalnızca TL modunda kullanılabilir.', 'tlMode' => false], 403);
}

$roomId = trim((string)($_GET['room_id'] ?? $_GET['roomId'] ?? ''));
if ($roomId === '') {
    zinesh_json_response(['message' => 'room_id gerekli.'], 400);
}

$user = zinesh_require_auth(zinesh_trust_metrics_auth_input());
$uid = (string)($user['uid'] ?? '');

$room = zinesh_ai_context_room_snapshot($roomId);
if (!$room) {
    zinesh_json_response(['message' => 'Oda bulunamadı.'], 404);
}

if (!zinesh_escrow_room_is_participant($room, $uid)) {
    zinesh_json_response(['message' => 'Bu odaya erişimin yok.'], 403);
}

$metrics = zinesh_trust_intelligence_metrics($roomId);
zinesh_json_response(zinesh_trust_metrics_public_response($metrics));
