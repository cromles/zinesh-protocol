<?php
declare(strict_types=1);

/**
 * Intelligence Copilot API — salt okunur Risk Engine açıklaması.
 * Yalnızca zinesh_risk_engine_for_room() çıktısını tüketir; karar vermez.
 *
 * GET /api/copilot.php?room_id={id}&intent=why_observation&observation_id=OBS-...
 */
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/risk_engine_endpoint_lib.php';
require_once __DIR__ . '/copilot_lib.php';

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
    zinesh_json_response(['message' => 'Intelligence Copilot yalnızca TL modunda kullanılabilir.', 'tlMode' => false], 403);
}

$roomId = trim((string)($_GET['room_id'] ?? $_GET['roomId'] ?? ''));
if ($roomId === '') {
    zinesh_json_response(['message' => 'room_id gerekli.'], 400);
}

$user = zinesh_require_auth(zinesh_risk_engine_auth_input());
$uid = (string)($user['uid'] ?? '');

$room = zinesh_ai_context_room_snapshot($roomId);
if (!$room) {
    zinesh_json_response(['message' => 'Oda bulunamadı.'], 404);
}

if (!zinesh_escrow_room_is_participant($room, $uid)) {
    zinesh_json_response(['message' => 'Bu odaya erişimin yok.'], 403);
}

$actorId = trim((string)($_GET['actor_id'] ?? $_GET['actorId'] ?? ''));
if ($actorId === '') {
    $actorId = $uid;
}

$intent = trim((string)($_GET['intent'] ?? 'overview'));
$observationId = trim((string)($_GET['observation_id'] ?? $_GET['observationId'] ?? ''));

$engine = zinesh_risk_engine_for_room($roomId, $actorId);
$publicEngine = zinesh_risk_engine_public_response($engine);

zinesh_json_response(zinesh_copilot_build_response($engine, $intent, $observationId, $publicEngine));
