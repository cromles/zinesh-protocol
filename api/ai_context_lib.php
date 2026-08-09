<?php
declare(strict_types=1);

require_once __DIR__ . '/escrow_memory_lib.php';
require_once __DIR__ . '/escrow_room_lib.php';

/** Recovery tetiklemeden oda okur (salt okunur). */
function zinesh_ai_context_room_snapshot(string $roomId): ?array
{
    foreach (zinesh_escrow_rooms_load() as $room) {
        if ((string)($room['id'] ?? '') === $roomId) {
            return $room;
        }
    }
    return null;
}

/** @param array<string,mixed>|null $version */
function zinesh_ai_context_sanitize_contract_version(?array $version): ?array
{
    if ($version === null) {
        return null;
    }

    $structured = is_array($version['structured_terms_json'] ?? null)
        ? $version['structured_terms_json']
        : [];

    return [
        'id' => (string)($version['id'] ?? ''),
        'version_number' => (int)($version['version_number'] ?? 0),
        'created_by_role' => (string)($version['created_by_role'] ?? ''),
        'title' => (string)($version['title'] ?? ''),
        'terms_content' => (string)($version['terms_content'] ?? ''),
        'amount_try' => round((float)($version['amount_try'] ?? 0), 2),
        'structured_terms_json' => $structured,
        'created_at' => (string)($version['created_at'] ?? ''),
        'accepted_at' => (string)($version['accepted_at'] ?? '') ?: null,
    ];
}

/** @return list<array<string,mixed>> */
function zinesh_ai_context_timeline_summary(string $roomId): array
{
    $out = [];
    foreach (zinesh_escrow_room_timeline($roomId) as $entry) {
        $out[] = [
            'event' => (string)($entry['event'] ?? ''),
            'actor_label' => (string)($entry['actor_label'] ?? ''),
            'date' => (string)($entry['date'] ?? ''),
            'description' => (string)($entry['description'] ?? ''),
        ];
    }
    return $out;
}

/** @param array<string,mixed> $raw */
function zinesh_ai_context_public_response(string $roomId, array $raw): array
{
    $versions = [];
    foreach ($raw['contract_versions'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sanitized = zinesh_ai_context_sanitize_contract_version($row);
        if ($sanitized !== null) {
            $versions[] = $sanitized;
        }
    }

    $accepted = is_array($raw['accepted_contract_version'] ?? null)
        ? zinesh_ai_context_sanitize_contract_version($raw['accepted_contract_version'])
        : null;

    $stats = is_array($raw['behavior_stats'] ?? null) ? $raw['behavior_stats'] : [];

    return [
        'ok' => true,
        'room_id' => $roomId,
        'contract_versions' => $versions,
        'accepted_contract_version' => $accepted,
        'timeline_summary' => zinesh_ai_context_timeline_summary($roomId),
        'behavior_stats' => [
            'terms_proposed_count' => (int)($stats['terms_proposed_count'] ?? 0),
            'terms_rejected_count' => (int)($stats['terms_rejected_count'] ?? 0),
            'changes_requested_count' => (int)($stats['changes_requested_count'] ?? 0),
            'counter_offer_count' => (int)($stats['counter_offer_count'] ?? 0),
            'dispute_opened_count' => (int)($stats['dispute_opened_count'] ?? 0),
            'dispute_resolved_count' => (int)($stats['dispute_resolved_count'] ?? 0),
        ],
        'generated_at' => date('c'),
    ];
}
