<?php
declare(strict_types=1);

/**
 * Bildirim gibi hafif uç noktalar için oturum çözümleme — wallet_lib yüklenmez.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';

function zinesh_session_auth_idle_seconds(): int
{
    $seconds = (int)(zinesh_config()['session_idle_seconds'] ?? 300);
    if ($seconds <= 0) {
        return 0;
    }
    return max(10, min($seconds, 86400));
}

function zinesh_session_auth_touch_interval(): int
{
    return max(5, (int)(zinesh_config()['session_touch_interval'] ?? 30));
}

/** @param array<string,mixed> $session */
function zinesh_session_auth_last_activity(array $session): int
{
    if (isset($session['lastActivity']) && is_numeric($session['lastActivity'])) {
        return (int)$session['lastActivity'];
    }
    if (!empty($session['createdAt'])) {
        $parsed = strtotime((string)$session['createdAt']);
        if ($parsed !== false) {
            return $parsed;
        }
    }
    return 0;
}

function zinesh_session_auth_find_user(string $uid): ?array
{
    if ($uid === '') {
        return null;
    }
    $users = zinesh_json_read('users.json');
    if (!is_array($users)) {
        return null;
    }
    foreach ($users as $u) {
        if (is_array($u) && ($u['uid'] ?? '') === $uid) {
            return $u;
        }
    }
    return null;
}

/** @return array{ok:bool, uid?:string, code?:string} */
function zinesh_session_auth_validate_token(string $token, bool $touch = true): array
{
    if ($token === '') {
        return ['ok' => false, 'code' => 'missing'];
    }

    $idle = zinesh_session_auth_idle_seconds();
    $touchInterval = zinesh_session_auth_touch_interval();
    $now = time();

    $sessions = zinesh_json_read('sessions.json');
    $session = is_array($sessions[$token] ?? null) ? $sessions[$token] : null;
    if (!is_array($session)) {
        return ['ok' => false, 'code' => 'missing'];
    }
    if (($session['expires'] ?? 0) < $now) {
        return ['ok' => false, 'code' => 'session_expired'];
    }
    $last = zinesh_session_auth_last_activity($session);
    if ($idle > 0 && $last > 0 && ($now - $last) > $idle) {
        return ['ok' => false, 'code' => 'session_expired'];
    }
    $uid = (string)($session['uid'] ?? '');
    if ($uid === '') {
        return ['ok' => false, 'code' => 'missing'];
    }

    if ($touch && ($now - $last) >= $touchInterval) {
        try {
            zinesh_json_atomic('sessions.json', static function (array &$rows) use ($token, $now): bool {
                if (!isset($rows[$token]) || !is_array($rows[$token])) {
                    return false;
                }
                $rows[$token]['lastActivity'] = $now;
                return true;
            });
        } catch (Throwable) {
            /* ignore */
        }
    }

    return ['ok' => true, 'uid' => $uid];
}

/** @param array<string,mixed> $input */
function zinesh_session_auth_resolve_user(array $input): ?array
{
    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    }
    if ($token === '') {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
    }
    if ($token === '') {
        return null;
    }
    $validated = zinesh_session_auth_validate_token($token, true);
    if (!($validated['ok'] ?? false)) {
        return null;
    }
    return zinesh_session_auth_find_user((string)($validated['uid'] ?? ''));
}
