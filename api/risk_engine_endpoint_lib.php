<?php
declare(strict_types=1);

require_once __DIR__ . '/risk_engine_lib.php';
require_once __DIR__ . '/ai_context_lib.php';

const ZINESH_RISK_ENGINE_ENDPOINT_VERSION = '1.0';

/** @return array{sessionToken:string,uid:string,email:string} */
function zinesh_risk_engine_auth_input(): array
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

/**
 * API yanıtı — mevcut Risk Engine paketi değiştirilmeden taşınır (read-only).
 *
 * @param array<string,mixed> $raw zinesh_risk_engine_for_room() çıktısı
 * @return array<string,mixed>
 */
function zinesh_risk_engine_public_response(array $raw): array
{
    return [
        'endpoint_version' => ZINESH_RISK_ENGINE_ENDPOINT_VERSION,
        'risk_engine_version' => (string)($raw['risk_engine_version'] ?? ZINESH_RISK_ENGINE_VERSION),
        'room_id' => (string)($raw['room_id'] ?? ''),
        'actor_id' => $raw['actor_id'] ?? null,
        'metrics' => is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [],
        'signals' => is_array($raw['signals'] ?? null) ? $raw['signals'] : [],
        'contexts' => is_array($raw['contexts'] ?? null) ? $raw['contexts'] : [],
        'observations' => is_array($raw['observations'] ?? null) ? $raw['observations'] : [],
    ];
}

/** Katılımcı erişim kontrolü (endpoint ile aynı mantık). */
function zinesh_risk_engine_can_access(string $roomId, string $uid): bool
{
    if ($roomId === '' || $uid === '') {
        return false;
    }
    $room = zinesh_ai_context_room_snapshot($roomId);
    if (!$room) {
        return false;
    }
    return zinesh_escrow_room_is_participant($room, $uid);
}
