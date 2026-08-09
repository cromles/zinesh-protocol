<?php
declare(strict_types=1);

/**
 * Context Resolver Runtime v0.1 — Signal → Context (salt okunur).
 * Architecture Freeze v1.0: Event → Metric → Signal → Context.
 * Risk Engine, AI, Observation yok (S3.2 kapsamı).
 */
require_once __DIR__ . '/context_catalog_lib.php';
require_once __DIR__ . '/trust_signal_runtime_lib.php';
require_once __DIR__ . '/ai_context_lib.php';

const ZINESH_CONTEXT_RUNTIME_VERSION = '0.1';

/**
 * Oda metadata'sından kategori ataması (Event okunmaz; room + contract structured terms).
 *
 * @param array<string,mixed>|null $room
 * @param array<string,mixed> $aiContext
 * @return list<string>
 */
function zinesh_context_resolver_detect_categories(?array $room, array $aiContext): array
{
    if ($room === null) {
        return [];
    }

    $textParts = [];
    foreach (['title', 'description'] as $field) {
        $val = trim((string)($room[$field] ?? ''));
        if ($val !== '') {
            $textParts[] = mb_strtolower($val, 'UTF-8');
        }
    }

    $structured = [];
    $accepted = is_array($aiContext['accepted_contract_version'] ?? null)
        ? $aiContext['accepted_contract_version']
        : null;
    if ($accepted !== null && is_array($accepted['structured_terms_json'] ?? null)) {
        $structured = $accepted['structured_terms_json'];
    } else {
        $latest = is_array($aiContext['latest_contract_version'] ?? null)
            ? $aiContext['latest_contract_version']
            : null;
        if ($latest !== null && is_array($latest['structured_terms_json'] ?? null)) {
            $structured = $latest['structured_terms_json'];
        }
    }

    foreach (['title', 'terms_content'] as $field) {
        if ($accepted !== null) {
            $val = trim((string)($accepted[$field] ?? ''));
            if ($val !== '') {
                $textParts[] = mb_strtolower($val, 'UTF-8');
            }
        }
    }

    $haystack = implode(' ', $textParts);
    $categories = [];

    $jobType = mb_strtolower((string)($structured['job_type'] ?? ''), 'UTF-8');
    $sector = mb_strtolower((string)($structured['sector'] ?? ''), 'UTF-8');
    $offering = mb_strtolower((string)($structured['offering'] ?? ''), 'UTF-8');
    $delivery = mb_strtolower((string)($structured['delivery_model'] ?? ''), 'UTF-8');
    $contractType = mb_strtolower((string)($structured['contract_type'] ?? ''), 'UTF-8');

    if ($jobType === 'software' || preg_match('/\b(yazılım|software|api|backend|frontend|web\s*app|mobil\s*uygulama)\b/u', $haystack)) {
        $categories[] = 'CTX-SW';
    }
    if ($jobType === 'consulting' || preg_match('/\b(danışmanlık|consulting|mentorluk)\b/u', $haystack)) {
        $categories[] = 'CTX-CNS';
    }
    if ($offering === 'video' || preg_match('/\b(video|prodüksiyon|montaj|kurgu)\b/u', $haystack)) {
        $categories[] = 'CTX-VID';
    }
    if (preg_match('/\b(tasarım|logo|grafik|branding|ui\/ux)\b/u', $haystack)) {
        $categories[] = 'CTX-DSG';
    }
    if (
        $jobType === 'physical_goods'
        || $delivery === 'physical_shipping'
        || preg_match('/\b(fiziksel|kargo|ürün|shipping|teslimat\s*adresi)\b/u', $haystack)
    ) {
        $categories[] = 'CTX-PHY';
    }
    if (preg_match('/\b(ikinci\s*el|used\s*device|telefon\s*satış|2\.\s*el)\b/u', $haystack)) {
        $categories[] = 'CTX-SEC';
    }
    if ($sector === 'construction' || preg_match('/\b(inşaat|şantiye|fiziksel\s*proje)\b/u', $haystack)) {
        $categories[] = 'CTX-CON';
    }
    if ($delivery === 'digital_instant' || preg_match('/\b(dijital\s*teslimat|anında\s*teslim|digital\s*asset)\b/u', $haystack)) {
        $categories[] = 'CTX-DIG';
    }
    if ($contractType === 'milestone' || preg_match('/\b(uzun\s*vadeli|milestone|fazlı)\b/u', $haystack)) {
        $categories[] = 'CTX-LNG';
    }
    if (preg_match('/\b(ajans|portföy|agency)\b/u', $haystack)) {
        $categories[] = 'CTX-AGY';
    }

    $amountTry = (float)($room['agreedAmountTry'] ?? 0);
    if ($amountTry <= 0 && $accepted !== null) {
        $amountTry = (float)($accepted['amount_try'] ?? 0);
    }
    if ($amountTry >= 100000 || preg_match('/\b(kurumsal|b2b|enterprise)\b/u', $haystack)) {
        $categories[] = 'CTX-B2B';
    }

    if ($categories === []) {
        $categories[] = 'CTX-ONE';
    }

    $unique = [];
    foreach ($categories as $ctxId) {
        if (!in_array($ctxId, $unique, true)) {
            $unique[] = $ctxId;
        }
    }
    return $unique;
}

/**
 * @param array<string,mixed> $structured
 * @return array<string,string>
 */
function zinesh_context_resolver_assignment_sources(array $structured): array
{
    $sources = [];
    foreach (['job_type', 'sector', 'offering', 'delivery_model', 'contract_type', 'complexity'] as $key) {
        $val = trim((string)($structured[$key] ?? ''));
        if ($val !== '') {
            $sources[$key] = 'structured_terms.' . $key;
        }
    }
    if ($sources === []) {
        $sources['fallback'] = 'room.title/description';
    }
    return $sources;
}

/**
 * Evidence Completeness — Domain Model §9 (Context katmanı).
 *
 * @param list<array<string,mixed>> $signalsUsed
 * @param array<string,string> $assignmentSources
 */
function zinesh_context_resolver_confidence_level(array $signalsUsed, array $assignmentSources): string
{
    if ($signalsUsed === []) {
        return 'low';
    }
    if (isset($assignmentSources['fallback'])) {
        return 'low';
    }

    $lowCount = 0;
    $highCount = 0;
    foreach ($signalsUsed as $signal) {
        $level = (string)($signal['confidence'] ?? 'medium');
        if ($level === 'low') {
            $lowCount++;
        } elseif ($level === 'high') {
            $highCount++;
        }
    }

    if ($lowCount > 0 && $highCount === 0) {
        return 'low';
    }
    if ($highCount >= 2 && $lowCount === 0) {
        return 'high';
    }
    return 'medium';
}

/**
 * @param array<string,mixed> $signalRow
 * @param string $ctxId
 * @param string $interpretation
 * @return array<string,mixed>
 */
function zinesh_context_resolver_signal_frame(
    array $signalRow,
    string $ctxId,
    string $interpretation
): array {
    return [
        'signal_id' => (string)($signalRow['signal_id'] ?? ''),
        'signal_title' => (string)($signalRow['title'] ?? ''),
        'interpretation_note' => $interpretation,
        'metric_sources' => is_array($signalRow['metric_sources'] ?? null)
            ? $signalRow['metric_sources']
            : [],
        'event_sources' => is_array($signalRow['event_sources'] ?? null)
            ? $signalRow['event_sources']
            : [],
        'signal_confidence' => (string)($signalRow['confidence'] ?? 'medium'),
    ];
}

/**
 * @param list<array<string,mixed>> $signalsUsed
 * @return array<string,mixed>
 */
function zinesh_context_resolver_aggregate_metrics(array $signalsUsed): array
{
    $metrics = [];
    foreach ($signalsUsed as $frame) {
        $sources = is_array($frame['metric_sources'] ?? null) ? $frame['metric_sources'] : [];
        foreach ($sources as $key => $value) {
            $metrics[(string)$key] = $value;
        }
    }
    return $metrics;
}

/**
 * @param string $ctxId
 * @param array<string,mixed> $def
 * @param list<array<string,mixed>> $signalsUsed
 * @param array<string,string> $assignmentSources
 * @param array<string,mixed> $structured
 * @return array<string,mixed>
 */
function zinesh_context_resolver_build_context(
    string $ctxId,
    array $def,
    array $signalsUsed,
    array $assignmentSources,
    array $structured
): array {
    $metricsUsed = zinesh_context_resolver_aggregate_metrics($signalsUsed);
    $confidence = zinesh_context_resolver_confidence_level($signalsUsed, $assignmentSources);
    $dimensions = is_array($def['dimensions'] ?? null) ? $def['dimensions'] : [];

    $notes = [];
    foreach ($signalsUsed as $frame) {
        $note = trim((string)($frame['interpretation_note'] ?? ''));
        if ($note !== '') {
            $notes[] = $note;
        }
    }
    $description = $notes !== []
        ? $notes[0]
        : 'Bu bağlam çerçevesi, mevcut signal gözlemlerinin nötr yorumlanması için atanmıştır.';

    $evidenceChain = [
        'chain' => 'context → signal → metric → event',
        'context_id' => $ctxId,
        'signals' => array_map(static function (array $frame): array {
            return [
                'signal_id' => $frame['signal_id'] ?? '',
                'metrics' => $frame['metric_sources'] ?? [],
                'events' => $frame['event_sources'] ?? [],
            ];
        }, $signalsUsed),
        'dimensions' => $dimensions,
        'assignment_sources' => $assignmentSources,
        'structured_terms_snapshot' => $structured !== [] ? array_keys($structured) : [],
    ];

    return [
        'context_id' => $ctxId,
        'title' => (string)($def['title'] ?? $ctxId),
        'description' => $description,
        'signals_used' => $signalsUsed,
        'metrics_used' => $metricsUsed,
        'evidence_chain' => $evidenceChain,
        'explainability' => [
            'chain' => 'context → signal → metric → event',
            'context_id' => $ctxId,
            'dimensions' => $dimensions,
            'signals' => $signalsUsed,
            'metrics' => $metricsUsed,
            'assignment_sources' => $assignmentSources,
            'taxonomy_version' => ZINESH_CONTEXT_TAXONOMY_VERSION,
            'interpretation_notes' => $notes,
        ],
        'confidence' => $confidence,
        'context_version' => ZINESH_CONTEXT_RUNTIME_VERSION,
    ];
}

/** @return array<string,mixed> */
function zinesh_context_resolver_empty(string $roomId): array
{
    return [
        'context_runtime_version' => ZINESH_CONTEXT_RUNTIME_VERSION,
        'taxonomy_version' => ZINESH_CONTEXT_TAXONOMY_VERSION,
        'room_id' => $roomId,
        'actor_id' => null,
        'contexts' => [],
    ];
}

/**
 * Oda için Context paketi (salt okunur).
 *
 * @return array<string,mixed>
 */
function zinesh_context_resolver_for_room(string $roomId, ?string $actorId = null): array
{
    $roomId = trim($roomId);
    if ($roomId === '') {
        return zinesh_context_resolver_empty('');
    }

    try {
        $room = zinesh_ai_context_room_snapshot($roomId);
        if ($room === null) {
            return zinesh_context_resolver_empty($roomId);
        }

        $aiContext = zinesh_escrow_room_ai_context($roomId);
        $signalPkg = zinesh_trust_signal_runtime_for_room($roomId, $actorId);
        $emittedSignals = is_array($signalPkg['signals'] ?? null) ? $signalPkg['signals'] : [];

        $categoryIds = zinesh_context_resolver_detect_categories($room, $aiContext);
        if ($categoryIds === []) {
            return [
                'context_runtime_version' => ZINESH_CONTEXT_RUNTIME_VERSION,
                'taxonomy_version' => ZINESH_CONTEXT_TAXONOMY_VERSION,
                'room_id' => $roomId,
                'actor_id' => $signalPkg['actor_id'] ?? $actorId,
                'contexts' => [],
            ];
        }

        $structured = [];
        $accepted = is_array($aiContext['accepted_contract_version'] ?? null)
            ? $aiContext['accepted_contract_version']
            : null;
        if ($accepted !== null && is_array($accepted['structured_terms_json'] ?? null)) {
            $structured = $accepted['structured_terms_json'];
        }
        $assignmentSources = zinesh_context_resolver_assignment_sources($structured);

        $definitions = zinesh_context_catalog_definitions();
        $interpretations = zinesh_context_catalog_signal_interpretations();
        $universal = zinesh_context_catalog_signal_universal_contexts();

        $signalById = [];
        foreach ($emittedSignals as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sid = (string)($row['signal_id'] ?? '');
            if ($sid !== '') {
                $signalById[$sid] = $row;
            }
        }

        $contexts = [];
        foreach ($categoryIds as $ctxId) {
            $def = $definitions[$ctxId] ?? null;
            if ($def === null) {
                continue;
            }

            $signalsUsed = [];
            foreach ($signalById as $signalId => $signalRow) {
                $interpretation = $interpretations[$signalId][$ctxId] ?? null;
                if ($interpretation === null) {
                    $universalList = $universal[$signalId] ?? [];
                    if (in_array($ctxId, $universalList, true)) {
                        $interpretation = 'Bu gözlem tüm bağlamlarda nötr okunmalıdır; hüküm üretmez.';
                    }
                }
                if ($interpretation === null) {
                    continue;
                }
                $signalsUsed[] = zinesh_context_resolver_signal_frame(
                    $signalRow,
                    $ctxId,
                    $interpretation
                );
            }

            if ($signalsUsed === [] && $emittedSignals === []) {
                continue;
            }

            $contexts[] = zinesh_context_resolver_build_context(
                $ctxId,
                $def,
                $signalsUsed,
                $assignmentSources,
                $structured
            );
        }

        return [
            'context_runtime_version' => ZINESH_CONTEXT_RUNTIME_VERSION,
            'taxonomy_version' => ZINESH_CONTEXT_TAXONOMY_VERSION,
            'room_id' => $roomId,
            'actor_id' => $signalPkg['actor_id'] ?? $actorId,
            'contexts' => $contexts,
        ];
    } catch (Throwable $e) {
        error_log('zinesh_context_resolver_for_room: ' . $e->getMessage());
        return zinesh_context_resolver_empty($roomId);
    }
}

/** @return list<string> */
function zinesh_context_resolver_context_ids(array $pkg): array
{
    $ids = [];
    foreach ($pkg['contexts'] ?? [] as $row) {
        if (is_array($row) && isset($row['context_id'])) {
            $ids[] = (string)$row['context_id'];
        }
    }
    return $ids;
}

/** @return array<string,mixed> */
function zinesh_context_resolver_normalize_for_compare(array $pkg): array
{
    return $pkg;
}
