<?php
declare(strict_types=1);

const ZINESH_CONTRACT_VERSIONS_FILE = 'contract_versions.json';
const ZINESH_CONTRACT_VERSIONS_MAX_ROWS = 50000;

/** @return array<string,mixed> */
function zinesh_contract_structured_terms_skeleton(array $roomOrTerms = []): array
{
    return [
        'category' => (string)($roomOrTerms['category'] ?? ''),
        'delivery_date' => (string)($roomOrTerms['delivery_date'] ?? ''),
        'scope' => is_array($roomOrTerms['scope'] ?? null) ? $roomOrTerms['scope'] : [],
        'revision_count' => (int)($roomOrTerms['revision_count'] ?? 0),
        'payment_terms' => (string)($roomOrTerms['payment_terms'] ?? ''),
        'warranty' => (string)($roomOrTerms['warranty'] ?? ''),
        'acceptance_criteria' => is_array($roomOrTerms['acceptance_criteria'] ?? null)
            ? $roomOrTerms['acceptance_criteria']
            : [],
        'amount_try' => round((float)($roomOrTerms['amount_try'] ?? $roomOrTerms['agreedAmountTry'] ?? 0), 2),
        'title' => (string)($roomOrTerms['title'] ?? ''),
    ];
}

function zinesh_contract_version_new_id(): string
{
    return 'cv-' . substr(hash('sha256', microtime(true) . random_bytes(8)), 0, 16);
}

/** @return list<array<string,mixed>> */
function zinesh_contract_versions_load(): array
{
    $rows = zinesh_json_read(ZINESH_CONTRACT_VERSIONS_FILE);
    return is_array($rows) ? $rows : [];
}

/** @return list<array<string,mixed>> */
function zinesh_contract_versions_for_room(string $roomId): array
{
    $out = [];
    foreach (zinesh_contract_versions_load() as $row) {
        if (!is_array($row) || (string)($row['room_id'] ?? '') !== $roomId) {
            continue;
        }
        $out[] = $row;
    }
    usort($out, static fn($a, $b) => ((int)($a['version_number'] ?? 0)) <=> ((int)($b['version_number'] ?? 0)));
    return $out;
}

function zinesh_contract_version_latest(string $roomId): ?array
{
    $versions = zinesh_contract_versions_for_room($roomId);
    if ($versions === []) {
        return null;
    }
    return $versions[count($versions) - 1];
}

function zinesh_contract_version_accepted(string $roomId): ?array
{
    $accepted = null;
    foreach (zinesh_contract_versions_for_room($roomId) as $row) {
        $at = (string)($row['accepted_at'] ?? '');
        if ($at !== '') {
            $accepted = $row;
        }
    }
    return $accepted;
}

function zinesh_contract_version_next_number(string $roomId): int
{
    $max = 0;
    foreach (zinesh_contract_versions_for_room($roomId) as $row) {
        $max = max($max, (int)($row['version_number'] ?? 0));
    }
    return $max + 1;
}

/**
 * Yeni sözleşme versiyonu ekler (append-only).
 *
 * @param array<string,mixed> $structuredTerms
 * @param array<string,mixed> $meta
 * @return array<string,mixed>|null
 */
function zinesh_contract_version_append(
    string $roomId,
    string $createdBy,
    string $createdByRole,
    string $title,
    string $termsContent,
    float $amountTry,
    array $structuredTerms = [],
    array $meta = []
): ?array {
    if ($roomId === '' || $createdBy === '') {
        return null;
    }

    $created = null;
    zinesh_json_atomic(ZINESH_CONTRACT_VERSIONS_FILE, static function (array &$rows) use (
        $roomId,
        $createdBy,
        $createdByRole,
        $title,
        $termsContent,
        $amountTry,
        $structuredTerms,
        $meta,
        &$created
    ) {
        $versionNumber = 1;
        foreach ($rows as $row) {
            if (!is_array($row) || (string)($row['room_id'] ?? '') !== $roomId) {
                continue;
            }
            $versionNumber = max($versionNumber, (int)($row['version_number'] ?? 0) + 1);
        }

        $structured = zinesh_contract_structured_terms_skeleton(array_merge(
            $structuredTerms,
            ['title' => $title, 'amount_try' => $amountTry]
        ));

        $version = [
            'id' => zinesh_contract_version_new_id(),
            'room_id' => $roomId,
            'version_number' => $versionNumber,
            'created_by' => $createdBy,
            'created_by_role' => $createdByRole,
            'title' => $title,
            'terms_content' => $termsContent,
            'amount_try' => round($amountTry, 2),
            'structured_terms_json' => $structured,
            'created_at' => date('c'),
            'accepted_at' => null,
            'meta_json' => $meta,
        ];
        $rows[] = $version;
        if (count($rows) > ZINESH_CONTRACT_VERSIONS_MAX_ROWS) {
            $rows = array_slice($rows, -ZINESH_CONTRACT_VERSIONS_MAX_ROWS);
        }
        $created = $version;
        return true;
    });

    return $created;
}

/** @return array<string,mixed>|null */
function zinesh_contract_version_mark_accepted(string $roomId, ?int $versionNumber = null, ?string $acceptedAt = null): ?array
{
    $updated = null;
    zinesh_json_atomic(ZINESH_CONTRACT_VERSIONS_FILE, static function (array &$rows) use ($roomId, $versionNumber, $acceptedAt, &$updated) {
        $targetVersion = $versionNumber;
        if ($targetVersion === null) {
            $max = 0;
            foreach ($rows as $row) {
                if (!is_array($row) || (string)($row['room_id'] ?? '') !== $roomId) {
                    continue;
                }
                $max = max($max, (int)($row['version_number'] ?? 0));
            }
            $targetVersion = $max > 0 ? $max : null;
        }
        if ($targetVersion === null) {
            return false;
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row) || (string)($row['room_id'] ?? '') !== $roomId) {
                continue;
            }
            if ((int)($row['version_number'] ?? 0) === $targetVersion) {
                $rows[$i]['accepted_at'] = $acceptedAt ?? date('c');
                $updated = $rows[$i];
                return true;
            }
        }
        return false;
    });

    return $updated;
}
