<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';

/** @return string[] */
function zinesh_events_allowed(): array
{
    $cfg = zinesh_config()['events'] ?? [];
    $allowed = $cfg['allowed'] ?? [
        'app_loaded',
        'landing_view',
        'console_open',
        'register_success',
        'login_success',
        'deposit_success',
        'swap_success',
        'referral_landing',
    ];
    return is_array($allowed) ? array_values(array_map('strval', $allowed)) : [];
}

function zinesh_events_jsonl_path(): string
{
    $dir = zinesh_data_path('marketing');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir . '/frontend_events.jsonl';
}

function zinesh_events_append(array $row): void
{
    $line = json_encode($row, JSON_UNESCAPED_UNICODE);
    file_put_contents(zinesh_events_jsonl_path(), $line . "\n", FILE_APPEND | LOCK_EX);
    @chmod(zinesh_events_jsonl_path(), 0640);
}

function zinesh_events_rate_limit_ok(string $ip): bool
{
    $max = (int)((zinesh_config()['events']['rate_limit_per_minute'] ?? 120));
    $key = 'events:' . $ip;
    $blocked = false;
    zinesh_json_atomic('rate_limits.json', static function (array &$limits) use ($key, $max, &$blocked) {
        $now = time();
        $entry = $limits[$key] ?? ['count' => 0, 'reset' => $now + 60];
        if ($now > (int)($entry['reset'] ?? 0)) {
            $entry = ['count' => 0, 'reset' => $now + 60];
        }
        $entry['count'] = (int)($entry['count'] ?? 0) + 1;
        $limits[$key] = $entry;
        $blocked = $entry['count'] > $max;
        return true;
    });
    return !$blocked;
}

function zinesh_events_str_limit(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function zinesh_events_sanitize_payload(array $payload): array
{
    $out = [];
    foreach ($payload as $key => $value) {
        $k = preg_replace('/[^a-z0-9_]/i', '', (string)$key);
        if ($k === '') {
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $out[$k] = $value;
            continue;
        }
        if (is_string($value)) {
            $out[$k] = zinesh_events_str_limit($value, 500);
        }
    }
    return $out;
}

function zinesh_events_handle(array $input): array
{
    $event = trim((string)($input['event'] ?? ''));
    if ($event === '' || !in_array($event, zinesh_events_allowed(), true)) {
        return ['ok' => false, 'message' => 'invalid_event'];
    }

    $ip = zinesh_client_ip();
    if (!zinesh_events_rate_limit_ok($ip)) {
        return ['ok' => false, 'message' => 'rate_limited'];
    }

    $payload = zinesh_events_sanitize_payload(is_array($input['payload'] ?? null) ? $input['payload'] : []);
    $row = [
        'at' => date('c'),
        'event' => $event,
        'payload' => $payload,
        'path' => zinesh_events_str_limit(trim((string)($input['path'] ?? '')), 200),
        'ip' => $ip,
        'ua' => zinesh_events_str_limit((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 200),
    ];
    zinesh_events_append($row);

    return ['ok' => true];
}
