<?php
declare(strict_types=1);

require_once __DIR__ . '/actor_trust_lib.php';

/** @return array{sessionToken:string,uid:string,email:string} */
function zinesh_actor_trust_auth_input(): array
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
 * Kullanıcı yalnızca kendi actor trust verisine erişebilir.
 * actor_id boşsa oturum kullanıcısı kabul edilir; farklı actor_id engellenir.
 */
function zinesh_actor_trust_can_access_self(string $requestedActorId, string $sessionUid): bool
{
    $requestedActorId = trim($requestedActorId);
    $sessionUid = trim($sessionUid);
    if ($sessionUid === '') {
        return false;
    }
    if ($requestedActorId === '') {
        return true;
    }
    return $requestedActorId === $sessionUid;
}

/**
 * API yanıtı — yalnızca özet metrikler; oda/event/karşı taraf sızmaz.
 *
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function zinesh_actor_trust_public_response(array $raw): array
{
    $metrics = is_array($raw['metrics'] ?? null) ? $raw['metrics'] : [];
    $behavior = is_array($metrics['behavior'] ?? null) ? $metrics['behavior'] : [];
    $history = is_array($metrics['history'] ?? null) ? $metrics['history'] : [];

    return [
        'ok' => true,
        'trust_version' => (string)($raw['trust_version'] ?? ZINESH_ACTOR_TRUST_VERSION),
        'actor_id' => (string)($raw['actor_id'] ?? ''),
        'metrics' => [
            'transactions' => (int)($metrics['transactions'] ?? 0),
            'successful_settlements' => (int)($metrics['successful_settlements'] ?? 0),
            'disputes' => (int)($metrics['disputes'] ?? 0),
            'behavior' => [
                'cooperation' => (float)($behavior['cooperation'] ?? 0),
                'conflict' => (float)($behavior['conflict'] ?? 0),
                'reliability' => (float)($behavior['reliability'] ?? 0),
            ],
            'history' => [
                'average_completion_time' => (int)($history['average_completion_time'] ?? 0),
                'average_negotiation_rounds' => (float)($history['average_negotiation_rounds'] ?? 0),
            ],
        ],
    ];
}
