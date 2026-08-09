<?php
declare(strict_types=1);

/**
 * Actor Trust API — salt okunur kullanıcı davranış özeti.
 * Escrow motorundan izole; karar vermez, yalnızca gözlem sunar.
 *
 * GET /api/actor_trust.php?sessionToken=...
 */
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/actor_trust_endpoint_lib.php';

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
    zinesh_json_response(['message' => 'Actor trust yalnızca TL modunda kullanılabilir.', 'tlMode' => false], 403);
}

$user = zinesh_require_auth(zinesh_actor_trust_auth_input());
$uid = (string)($user['uid'] ?? '');

$requestedActorId = trim((string)($_GET['actor_id'] ?? $_GET['actorId'] ?? ''));
if (!zinesh_actor_trust_can_access_self($requestedActorId, $uid)) {
    zinesh_json_response(['message' => 'Bu actor trust verisine erişimin yok.'], 403);
}

$aggregate = zinesh_actor_trust_aggregate($uid);
zinesh_json_response(zinesh_actor_trust_public_response($aggregate));
