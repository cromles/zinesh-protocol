<?php
declare(strict_types=1);

require_once __DIR__ . '/trust_intelligence_lib.php';
require_once __DIR__ . '/ai_context_lib.php';

/** @return array{sessionToken:string,uid:string,email:string} */
function zinesh_trust_metrics_auth_input(): array
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
 * API yanıtı — yalnızca ölçüm alanları; iç event / kullanıcı detayı sızmaz.
 *
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function zinesh_trust_metrics_public_response(array $raw): array
{
    $metrics = is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [];
    $negotiation = is_array($metrics['negotiation'] ?? null) ? $metrics['negotiation'] : [];
    $settlement = is_array($metrics['settlement'] ?? null) ? $metrics['settlement'] : [];
    $behavior = is_array($metrics['behavior'] ?? null) ? $metrics['behavior'] : [];
    $time = is_array($metrics['time'] ?? null) ? $metrics['time'] : [];

    return [
        'ok' => true,
        'trust_version' => (string)($raw['trust_version'] ?? ZINESH_TRUST_INTELLIGENCE_VERSION),
        'room_id' => (string)($raw['room_id'] ?? ''),
        'metrics' => [
            'negotiation' => [
                'terms_proposed_count' => (int)($negotiation['terms_proposed_count'] ?? 0),
                'terms_rejected_count' => (int)($negotiation['terms_rejected_count'] ?? 0),
                'changes_requested_count' => (int)($negotiation['changes_requested_count'] ?? 0),
                'counter_offer_count' => (int)($negotiation['counter_offer_count'] ?? 0),
                'negotiation_rounds' => (int)($negotiation['negotiation_rounds'] ?? 0),
            ],
            'settlement' => [
                'successful_settlement' => !empty($settlement['successful_settlement']),
                'dispute_count' => (int)($settlement['dispute_count'] ?? 0),
                'dispute_resolved' => !empty($settlement['dispute_resolved']),
                'completion_status' => (string)($settlement['completion_status'] ?? 'unknown'),
            ],
            'behavior' => [
                'cooperation_signal' => (int)($behavior['cooperation_signal'] ?? 0),
                'conflict_signal' => (int)($behavior['conflict_signal'] ?? 0),
                'reliability_signal' => (int)($behavior['reliability_signal'] ?? 0),
            ],
            'time' => [
                'contract_creation_time' => ($time['contract_creation_time'] ?? null) ?: null,
                'acceptance_time' => ($time['acceptance_time'] ?? null) ?: null,
                'settlement_time' => ($time['settlement_time'] ?? null) ?: null,
                'duration_seconds' => (int)($time['duration_seconds'] ?? 0),
            ],
        ],
        'generated_at' => (string)($raw['generated_at'] ?? date('c')),
    ];
}

/** Katılımcı erişim kontrolü (endpoint ile aynı mantık). */
function zinesh_trust_metrics_can_access(string $roomId, string $uid): bool
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
