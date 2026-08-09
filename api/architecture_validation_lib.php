<?php
declare(strict_types=1);

/**
 * Architecture Validation Suite v1.0 — Architecture Freeze / Development Protocol uyum denetimi.
 * Runtime davranışını değiştirmez; yalnızca compliance doğrular (S3.5).
 */
require_once __DIR__ . '/trust_signal_catalog_lib.php';
require_once __DIR__ . '/context_catalog_lib.php';
require_once __DIR__ . '/risk_observation_catalog_lib.php';

const ZINESH_ARCHITECTURE_VALIDATION_VERSION = '1.0';

/** Bilinen C5 istisnaları (S3.1B arbitration sonrası; yeni ihlal FAIL üretir). */
const ZINESH_ARCH_VALIDATION_C5_KNOWN_EXCEPTIONS = [
    'SIG-NEG-003',
    'SIG-TIME-004',
    'SIG-SET-002',
];

/** @return list<string> */
function zinesh_architecture_validation_runtime_layer_files(): array
{
    return [
        'trust_intelligence_lib.php',
        'trust_signal_runtime_lib.php',
        'context_resolver_lib.php',
        'risk_observation_runtime_lib.php',
        'risk_engine_lib.php',
    ];
}

/** @return list<string> */
function zinesh_architecture_validation_stable_core_files(): array
{
    return [
        'escrow_room_lib.php',
        'wallet_lib.php',
    ];
}

/** @return array<string,list<string>> */
function zinesh_architecture_validation_regression_packages(): array
{
    return [
        'trust_metrics' => ['scripts/e2e-trust-metrics-test.php', 'scripts/e2e-trust-intelligence-test.php'],
        'trust_signal_runtime' => ['scripts/e2e-trust-signal-runtime-test.php'],
        'context_resolver' => ['scripts/e2e-context-resolver-test.php'],
        'risk_observation_runtime' => ['scripts/e2e-risk-observation-runtime-test.php'],
        'risk_engine' => ['scripts/e2e-risk-engine-test.php'],
        'actor_trust' => ['scripts/e2e-actor-trust-test.php', 'scripts/e2e-actor-trust-api-test.php'],
        'ai_context' => ['scripts/e2e-ai-context-test.php'],
        'escrow_memory' => ['scripts/e2e-escrow-memory-test.php'],
        'inv_a4' => ['scripts/e2e-inv-a4-settlement-recovery-test.php'],
        's1_1' => ['scripts/e2e-inv-s1-1-employer-settlement-guard-test.php'],
        'intelligence_ops' => [
            'scripts/run-intelligence-regression.php',
            'scripts/e2e-copilot-framework-test.php',
            'scripts/e2e-openai-provider-test.php',
            'scripts/e2e-copilot-evaluation-test.php',
        ],
    ];
}

/** @return list<string> */
function zinesh_architecture_validation_governance_docs(): array
{
    return [
        'docs/ARCHITECTURE_FREEZE_v1.md',
        'docs/DEVELOPMENT_PROTOCOL.md',
        'docs/TRUST_DOMAIN_MODEL.md',
        'docs/TRUST_INTELLIGENCE_ARCHITECTURE.md',
        'docs/TRUST_ONTOLOGY.md',
        'docs/TRUST_SIGNAL_CATALOG.md',
        'docs/CONTEXT_TAXONOMY.md',
        'docs/RISK_OBSERVATION_MODEL.md',
        'docs/INTELLIGENCE_OPS.md',
    ];
}

/**
 * @return array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}
 */
function zinesh_architecture_validation_check_result(
    string $id,
    string $category,
    bool $passed,
    string $message,
    array $details = []
): array {
    $row = [
        'id' => $id,
        'category' => $category,
        'passed' => $passed,
        'message' => $message,
    ];
    if ($details !== []) {
        $row['details'] = $details;
    }
    return $row;
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_stable_core_isolation(string $apiDir): array
{
    $checks = [];
    $writePatterns = [
        'zinesh_json_write',
        'file_put_contents',
        'fwrite(',
        'zinesh_domain_event_emit',
        'unlink(',
    ];

    foreach (zinesh_architecture_validation_runtime_layer_files() as $file) {
        $path = $apiDir . '/' . $file;
        if (!is_file($path)) {
            $checks[] = zinesh_architecture_validation_check_result(
                'stable_core_runtime_file_' . $file,
                'stable_core_isolation',
                false,
                "Runtime dosyası bulunamadı: {$file}"
            );
            continue;
        }
        $source = (string)file_get_contents($path);
        $violations = [];
        foreach ($writePatterns as $pattern) {
            if (str_contains($source, $pattern)) {
                $violations[] = $pattern;
            }
        }
        $checks[] = zinesh_architecture_validation_check_result(
            'stable_core_no_write_' . $file,
            'stable_core_isolation',
            $violations === [],
            $violations === []
                ? "{$file} Stable Core'a yazmıyor"
                : "{$file} yasak yazma/mutasyon çağrısı: " . implode(', ', $violations),
            ['violations' => $violations]
        );
    }

    foreach (zinesh_architecture_validation_stable_core_files() as $file) {
        $path = $apiDir . '/' . $file;
        $checks[] = zinesh_architecture_validation_check_result(
            'stable_core_file_exists_' . $file,
            'stable_core_isolation',
            is_file($path),
            is_file($path) ? "Stable Core dosyası mevcut: {$file}" : "Stable Core dosyası eksik: {$file}"
        );
    }

    return $checks;
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_read_only(string $apiDir): array
{
    $checks = [];
    $forbidden = ['zinesh_json_write', 'file_put_contents(', 'zinesh_domain_event_emit'];

    foreach (zinesh_architecture_validation_runtime_layer_files() as $file) {
        $source = (string)file_get_contents($apiDir . '/' . $file);
        $hits = [];
        foreach ($forbidden as $token) {
            if (str_contains($source, $token)) {
                $hits[] = $token;
            }
        }
        $checks[] = zinesh_architecture_validation_check_result(
            'read_only_' . $file,
            'read_only',
            $hits === [],
            $hits === [] ? "{$file} read-only" : "{$file} mutasyon token: " . implode(', ', $hits),
            ['hits' => $hits]
        );
    }

    return $checks;
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_architecture_chain(string $apiDir): array
{
    $checks = [];
    $requires = [
        'trust_signal_runtime_lib.php' => ['trust_intelligence_lib.php', 'zinesh_trust_intelligence_metrics'],
        'context_resolver_lib.php' => ['trust_signal_runtime_lib.php', 'zinesh_trust_signal_runtime_for_room'],
        'risk_observation_runtime_lib.php' => ['context_resolver_lib.php', 'zinesh_context_resolver_for_room'],
        'risk_engine_lib.php' => [
            'zinesh_trust_intelligence_metrics',
            'zinesh_trust_signal_runtime_for_room',
            'zinesh_context_resolver_for_room',
            'zinesh_risk_observation_runtime_for_room',
        ],
    ];

    foreach ($requires as $file => $tokens) {
        $source = (string)file_get_contents($apiDir . '/' . $file);
        $missing = [];
        foreach ($tokens as $token) {
            if (!str_contains($source, $token)) {
                $missing[] = $token;
            }
        }
        $checks[] = zinesh_architecture_validation_check_result(
            'chain_requires_' . $file,
            'architecture_chain',
            $missing === [],
            $missing === []
                ? "{$file} zincir bağımlılıkları doğru"
                : "{$file} eksik bağımlılık: " . implode(', ', $missing),
            ['missing' => $missing]
        );
    }

    $riskEngine = (string)file_get_contents($apiDir . '/risk_engine_lib.php');
    $forbiddenInEngine = [
        'zinesh_trust_signal_build_emitted',
        'zinesh_context_resolver_build_context',
        'zinesh_risk_observation_build_emitted',
        'zinesh_trust_negotiation_metrics',
    ];
    $engineViolations = [];
    foreach ($forbiddenInEngine as $token) {
        if (str_contains($riskEngine, $token)) {
            $engineViolations[] = $token;
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'chain_risk_engine_orchestrator_only',
        'architecture_chain',
        $engineViolations === [],
        $engineViolations === []
            ? 'Risk Engine yalnızca orchestrator'
            : 'Risk Engine katman iş kuralı içeriyor: ' . implode(', ', $engineViolations),
        ['violations' => $engineViolations]
    );

    return $checks;
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_layer_responsibility(string $apiDir): array
{
    $checks = [];
    $layers = [
        'trust_signal_runtime_lib.php' => ['forbidden' => ['OBS-', 'zinesh_risk_observation'], 'label' => 'Signal Runtime'],
        'context_resolver_lib.php' => ['forbidden' => ['OBS-', 'zinesh_risk_observation'], 'label' => 'Context Resolver'],
        'risk_observation_runtime_lib.php' => ['forbidden' => ['zinesh_trust_signal_build_emitted'], 'label' => 'Risk Observation Runtime'],
        'trust_intelligence_lib.php' => ['forbidden' => ['SIG-', 'CTX-', 'OBS-'], 'label' => 'Trust Metrics', 'allow_catalog_refs' => false],
    ];

    foreach ($layers as $file => $cfg) {
        $source = (string)file_get_contents($apiDir . '/' . $file);
        $hits = [];
        foreach ($cfg['forbidden'] as $token) {
            if ($token === 'SIG-' || $token === 'CTX-' || $token === 'OBS-') {
                if (preg_match('/[\'"]' . preg_quote($token, '/') . '[A-Z0-9-]+[\'"]/', $source)) {
                    $hits[] = $token . '*';
                }
            } elseif (str_contains($source, $token)) {
                $hits[] = $token;
            }
        }
        $checks[] = zinesh_architecture_validation_check_result(
            'layer_responsibility_' . $file,
            'layer_responsibility',
            $hits === [],
            $hits === []
                ? ($cfg['label'] . ' sorumluluk sınırı korunuyor')
                : ($cfg['label'] . ' yasak üretim: ' . implode(', ', $hits)),
            ['hits' => $hits]
        );
    }

    return $checks;
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_frozen_terminology(string $apiDir): array
{
    $checks = [];
    $sigIds = array_flip(zinesh_trust_signal_catalog_ids());
    $ctxIds = array_flip(zinesh_context_catalog_ids());
    $obsIds = array_flip(zinesh_risk_observation_catalog_ids());

    $sigDefs = zinesh_trust_signal_catalog_definitions();
    $ctxDefs = zinesh_context_catalog_definitions();
    $obsDefs = zinesh_risk_observation_catalog_definitions();

    $checks[] = zinesh_architecture_validation_check_result(
        'terminology_sig_catalog_complete',
        'frozen_terminology',
        count($sigDefs) === count($sigIds) && count($sigIds) === 18,
        'SIG-* catalog: ' . count($sigIds) . ' tanım'
    );
    $checks[] = zinesh_architecture_validation_check_result(
        'terminology_ctx_catalog_complete',
        'frozen_terminology',
        count($ctxDefs) === count($ctxIds) && count($ctxIds) === 12,
        'CTX-* catalog: ' . count($ctxIds) . ' tanım'
    );
    $checks[] = zinesh_architecture_validation_check_result(
        'terminology_obs_catalog_complete',
        'frozen_terminology',
        count($obsDefs) === count($obsIds) && count($obsIds) === 15,
        'OBS-* catalog: ' . count($obsIds) . ' tanım'
    );

    $bannedPatterns = [
        'Observation Engine' => 'Risk Engine',
        'Enterprise Intelligence' => 'Enterprise Observability',
        'ai_confidence' => 'Evidence Completeness',
        'risk_confidence' => 'Evidence Completeness',
        'probability_score' => 'Evidence Completeness',
    ];
    $bannedHits = [];
    foreach (zinesh_architecture_validation_runtime_layer_files() as $file) {
        $source = (string)file_get_contents($apiDir . '/' . $file);
        foreach ($bannedPatterns as $bad => $good) {
            if (stripos($source, $bad) !== false) {
                $bannedHits[] = "{$file}:{$bad}";
            }
        }
        if (preg_match('/\brisk_score\b/i', $source) || preg_match('/\btrust_score\b/i', $source)) {
            $bannedHits[] = "{$file}:risk_score|trust_score";
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'terminology_banned_terms',
        'frozen_terminology',
        $bannedHits === [],
        $bannedHits === [] ? 'Yasak terminoloji yok' : 'Yasak terminoloji: ' . implode(', ', $bannedHits),
        ['hits' => $bannedHits]
    );

    return $checks;
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_scope_and_human_control(string $apiDir): array
{
    $checks = [];
    $riskEngine = (string)file_get_contents($apiDir . '/risk_engine_lib.php');
    $forbiddenScope = [
        'openai', 'chatgpt', 'llm_', 'gpt-', 'prediction', 'risk_score',
        'auto_decision', 'auto_settle', 'auto_penalty', 'blacklist_user',
        'block_account', 'trust_score_update',
    ];
    $scopeHits = [];
    foreach ($forbiddenScope as $token) {
        if (stripos($riskEngine, $token) !== false) {
            $scopeHits[] = $token;
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'scope_risk_engine_no_ai_decision',
        'scope_validation',
        $scopeHits === [],
        $scopeHits === [] ? 'Risk Engine scope temiz' : 'Risk Engine yasak scope: ' . implode(', ', $scopeHits),
        ['hits' => $scopeHits]
    );

    foreach (zinesh_architecture_validation_runtime_layer_files() as $file) {
        $source = (string)file_get_contents($apiDir . '/' . $file);
        $humanHits = [];
        foreach (['auto_settle', 'auto_penalty', 'automatic_decision', 'trust_score_change'] as $token) {
            if (stripos($source, $token) !== false) {
                $humanHits[] = $token;
            }
        }
        $checks[] = zinesh_architecture_validation_check_result(
            'human_in_control_' . $file,
            'human_in_control',
            $humanHits === [],
            $humanHits === [] ? "{$file} otomatik karar yok" : "{$file} otomatik aksiyon: " . implode(', ', $humanHits),
            ['hits' => $humanHits]
        );
    }

    return $checks;
}

/** @return list<string> */
function zinesh_architecture_validation_detect_c5_event_gate_signals(string $apiDir): array
{
    $source = (string)file_get_contents($apiDir . '/trust_signal_runtime_lib.php');
    $violations = [];
    if (preg_match_all(
        "/case\s+'(SIG-[A-Z]+-\d+)':[\s\S]*?zinesh_trust_signal_first_event_ts\s*\(\s*\\\$events/",
        $source,
        $matches
    )) {
        foreach ($matches[1] as $sigId) {
            $violations[] = $sigId;
        }
    }
    if (preg_match_all(
        "/case\s+'(SIG-[A-Z]+-\d+)':[\s\S]*?zinesh_trust_signal_deadline_missed\s*\([^)]*\\\$events/",
        $source,
        $matches2
    )) {
        foreach ($matches2[1] as $sigId) {
            $violations[] = $sigId;
        }
    }
    return array_values(array_unique($violations));
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_c5_compliance(string $apiDir): array
{
    $detected = zinesh_architecture_validation_detect_c5_event_gate_signals($apiDir);
    $known = ZINESH_ARCH_VALIDATION_C5_KNOWN_EXCEPTIONS;
    $unexpected = array_values(array_diff($detected, $known));
    $checks = [];

    $checks[] = zinesh_architecture_validation_check_result(
        'c5_no_new_event_gate_violations',
        'architecture_chain',
        $unexpected === [],
        $unexpected === []
            ? 'C5: bilinen istisnalar dışında event-gate ihlali yok'
            : 'C5: yeni event-gate ihlali: ' . implode(', ', $unexpected),
        ['detected' => $detected, 'known_exceptions' => $known, 'unexpected' => $unexpected]
    );

    return $checks;
}

/** @return list<string> */
function zinesh_architecture_validation_collect_confidence_values(mixed $node, array &$out): void
{
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if ($key === 'confidence' || $key === 'signal_confidence') {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }
        zinesh_architecture_validation_collect_confidence_values($value, $out);
    }
}

/**
 * @param array<string,mixed> $enginePkg
 * @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}>
 */
function zinesh_architecture_validation_check_runtime_output(array $enginePkg): array
{
    $checks = [];
    $allowedConfidence = ['low' => true, 'medium' => true, 'high' => true];
    $confidenceValues = [];
    zinesh_architecture_validation_collect_confidence_values($enginePkg, $confidenceValues);
    $badConfidence = [];
    foreach ($confidenceValues as $val) {
        if (!isset($allowedConfidence[strtolower($val)])) {
            $badConfidence[] = $val;
        }
        if (preg_match('/prob|ai_|risk_/i', $val)) {
            $badConfidence[] = $val;
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'confidence_evidence_completeness',
        'confidence',
        $badConfidence === [],
        $badConfidence === []
            ? 'Confidence değerleri Evidence Completeness (low|medium|high)'
            : 'Geçersiz confidence: ' . implode(', ', $badConfidence),
        ['values' => array_values(array_unique($confidenceValues))]
    );

    $sigCatalog = array_flip(zinesh_trust_signal_catalog_ids());
    $ctxCatalog = array_flip(zinesh_context_catalog_ids());
    $obsCatalog = array_flip(zinesh_risk_observation_catalog_ids());

    $unknownSig = [];
    foreach ($enginePkg['signals']['signals'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['signal_id'] ?? '');
        if ($id !== '' && !isset($sigCatalog[$id])) {
            $unknownSig[] = $id;
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_catalog_sig_only',
        'frozen_terminology',
        $unknownSig === [],
        $unknownSig === [] ? 'Emit edilen signal\'lar catalog içinde' : 'Catalog dışı SIG: ' . implode(', ', $unknownSig),
        ['unknown' => $unknownSig]
    );

    $unknownCtx = [];
    foreach ($enginePkg['contexts']['contexts'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['context_id'] ?? '');
        if ($id !== '' && !isset($ctxCatalog[$id])) {
            $unknownCtx[] = $id;
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_catalog_ctx_only',
        'frozen_terminology',
        $unknownCtx === [],
        $unknownCtx === [] ? 'Emit edilen context\'ler catalog içinde' : 'Catalog dışı CTX: ' . implode(', ', $unknownCtx),
        ['unknown' => $unknownCtx]
    );

    $unknownObs = [];
    $explainFailures = [];
    foreach ($enginePkg['observations']['observations'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row['observation_id'] ?? '');
        if ($id !== '' && !isset($obsCatalog[$id])) {
            $unknownObs[] = $id;
        }
        $chain = (string)($row['explainability']['chain'] ?? '');
        $evChain = (string)($row['evidence_chain']['chain'] ?? '');
        $expected = 'risk_observation → context → signal → metric → event';
        if ($chain !== $expected || $evChain !== $expected) {
            $explainFailures[] = $id;
        }
        if (!is_array($row['signals_used'] ?? null) || !is_array($row['contexts_used'] ?? null)) {
            $explainFailures[] = $id . ':missing_refs';
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_catalog_obs_only',
        'frozen_terminology',
        $unknownObs === [],
        $unknownObs === [] ? 'Emit edilen observation\'lar catalog içinde' : 'Catalog dışı OBS: ' . implode(', ', $unknownObs),
        ['unknown' => $unknownObs]
    );
    $checks[] = zinesh_architecture_validation_check_result(
        'explainability_observation_chain',
        'explainability',
        $explainFailures === [],
        $explainFailures === []
            ? 'Observation explainability zinciri tam'
            : 'Explainability eksik: ' . implode(', ', $explainFailures),
        ['failures' => $explainFailures]
    );

    $signalExplainFailures = [];
    foreach ($enginePkg['signals']['signals'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $chain = (string)($row['explanation']['chain'] ?? '');
        if ($chain !== 'signal → metric → event') {
            $signalExplainFailures[] = (string)($row['signal_id'] ?? '');
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'explainability_signal_chain',
        'explainability',
        $signalExplainFailures === [],
        $signalExplainFailures === []
            ? 'Signal explainability zinciri tam'
            : 'Signal explainability eksik: ' . implode(', ', $signalExplainFailures),
        ['failures' => $signalExplainFailures]
    );

    $contextExplainFailures = [];
    foreach ($enginePkg['contexts']['contexts'] ?? [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $chain = (string)($row['explainability']['chain'] ?? '');
        if ($chain !== 'context → signal → metric → event') {
            $contextExplainFailures[] = (string)($row['context_id'] ?? '');
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'explainability_context_chain',
        'explainability',
        $contextExplainFailures === [],
        $contextExplainFailures === []
            ? 'Context explainability zinciri tam'
            : 'Context explainability eksik: ' . implode(', ', $contextExplainFailures),
        ['failures' => $contextExplainFailures]
    );

    $metricsPkg = is_array($enginePkg['metrics'] ?? null) ? $enginePkg['metrics'] : [];
    $signalsPkg = is_array($enginePkg['signals'] ?? null) ? $enginePkg['signals'] : [];
    $contextsPkg = is_array($enginePkg['contexts'] ?? null) ? $enginePkg['contexts'] : [];
    $obsPkg = is_array($enginePkg['observations'] ?? null) ? $enginePkg['observations'] : [];

    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_consistency_engine_layers',
        'runtime_consistency',
        isset($metricsPkg['metrics'], $signalsPkg['signals'], $contextsPkg['contexts'], $obsPkg['observations']),
        'Risk Engine alt katman paket yapısı mevcut'
    );

    return $checks;
}

/**
 * @param array<string,mixed> $enginePkg
 * @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}>
 */
function zinesh_architecture_validation_check_determinism_replay(
    string $roomId,
    ?string $actorId,
    array $enginePkg
): array
{
    require_once __DIR__ . '/trust_intelligence_lib.php';
    require_once __DIR__ . '/trust_signal_runtime_lib.php';
    require_once __DIR__ . '/context_resolver_lib.php';
    require_once __DIR__ . '/risk_observation_runtime_lib.php';
    require_once __DIR__ . '/risk_engine_lib.php';

    $checks = [];
    $run1 = zinesh_risk_engine_for_room($roomId, $actorId);
    $run2 = zinesh_risk_engine_for_room($roomId, $actorId);

    $normalize = static function (array $pkg): array {
        if (isset($pkg['metrics']) && is_array($pkg['metrics'])) {
            unset($pkg['metrics']['generated_at']);
        }
        return $pkg;
    };

    $checks[] = zinesh_architecture_validation_check_result(
        'determinism_same_input_same_output',
        'determinism',
        json_encode($normalize($run1)) === json_encode($normalize($run2)),
        'Aynı input → aynı output (generated_at hariç)'
    );

    $ids1 = zinesh_risk_observation_runtime_observation_ids($run1['observations'] ?? []);
    $ids2 = zinesh_risk_observation_runtime_observation_ids($run2['observations'] ?? []);
    $checks[] = zinesh_architecture_validation_check_result(
        'replayability_observation_ids',
        'replayability',
        $ids1 === $ids2,
        'Replay aynı observation ID listesini üretiyor',
        ['ids' => $ids1]
    );

    $directMetrics = zinesh_trust_intelligence_metrics($roomId);
    $directSignals = zinesh_trust_signal_runtime_for_room($roomId, $actorId);
    $directContexts = zinesh_context_resolver_for_room($roomId, $actorId);
    $directObs = zinesh_risk_observation_runtime_for_room($roomId, $actorId);

    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_consistency_metrics',
        'runtime_consistency',
        json_encode($enginePkg['metrics'] ?? null) === json_encode($directMetrics),
        'Engine metrics = Trust Metrics'
    );
    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_consistency_signals',
        'runtime_consistency',
        json_encode($enginePkg['signals'] ?? null) === json_encode($directSignals),
        'Engine signals = Signal Runtime'
    );
    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_consistency_contexts',
        'runtime_consistency',
        json_encode($enginePkg['contexts'] ?? null) === json_encode($directContexts),
        'Engine contexts = Context Resolver'
    );
    $checks[] = zinesh_architecture_validation_check_result(
        'runtime_consistency_observations',
        'runtime_consistency',
        json_encode($enginePkg['observations'] ?? null) === json_encode($directObs),
        'Engine observations = Risk Observation Runtime'
    );

    return $checks;
}

/** @return list<array{id:string,category:string,passed:bool,message:string,details?:array<string,mixed>}> */
function zinesh_architecture_validation_check_regression_packages(string $apiDir, string $repoRoot): array
{
    $checks = [];
    $packages = zinesh_architecture_validation_regression_packages();
    $missing = [];

    foreach ($packages as $name => $paths) {
        foreach ($paths as $rel) {
            $full = $apiDir . '/' . $rel;
            if (!is_file($full)) {
                $missing[] = "{$name}:{$rel}";
            }
        }
    }

    $checks[] = zinesh_architecture_validation_check_result(
        'regression_e2e_packages_present',
        'regression_validation',
        $missing === [],
        $missing === []
            ? 'Regression e2e paketleri mevcut'
            : 'Eksik regression paketi: ' . implode(', ', $missing),
        ['missing' => $missing, 'packages' => array_keys($packages)]
    );

    $validationSelf = $apiDir . '/scripts/e2e-architecture-validation-test.php';
    $checks[] = zinesh_architecture_validation_check_result(
        'regression_architecture_validation_self',
        'regression_validation',
        is_file($validationSelf),
        is_file($validationSelf) ? 'Architecture validation e2e mevcut' : 'e2e-architecture-validation-test.php eksik'
    );

    $docMissing = [];
    foreach (zinesh_architecture_validation_governance_docs() as $rel) {
        if (!is_file($repoRoot . '/' . $rel)) {
            $docMissing[] = $rel;
        }
    }
    $checks[] = zinesh_architecture_validation_check_result(
        'governance_frozen_docs_present',
        'governance',
        $docMissing === [],
        $docMissing === [] ? 'Frozen governance belgeleri mevcut' : 'Eksik belge: ' . implode(', ', $docMissing),
        ['missing' => $docMissing]
    );

    return $checks;
}

/**
 * Tam Architecture Validation Suite çalıştırır.
 *
 * @param array{room_id?:string,actor_id?:string|null,api_dir?:string,repo_root?:string} $options
 * @return array<string,mixed>
 */
function zinesh_architecture_validation_run(array $options = []): array
{
    $apiDir = rtrim((string)($options['api_dir'] ?? dirname(__DIR__)), '/\\');
    $repoRoot = rtrim((string)($options['repo_root'] ?? dirname($apiDir)), '/\\');
    $roomId = (string)($options['room_id'] ?? '');
    $actorId = array_key_exists('actor_id', $options) ? $options['actor_id'] : null;

    $allChecks = [];
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_stable_core_isolation($apiDir));
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_read_only($apiDir));
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_architecture_chain($apiDir));
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_layer_responsibility($apiDir));
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_frozen_terminology($apiDir));
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_scope_and_human_control($apiDir));
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_c5_compliance($apiDir));
    $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_regression_packages($apiDir, $repoRoot));

    $enginePkg = null;
    if ($roomId !== '') {
        require_once $apiDir . '/risk_engine_lib.php';
        $enginePkg = zinesh_risk_engine_for_room($roomId, is_string($actorId) ? $actorId : null);
        $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_runtime_output($enginePkg));
        $allChecks = array_merge($allChecks, zinesh_architecture_validation_check_determinism_replay(
            $roomId,
            is_string($actorId) ? $actorId : null,
            $enginePkg
        ));
    }

    $failed = [];
    $passed = [];
    foreach ($allChecks as $check) {
        if (($check['passed'] ?? false) === true) {
            $passed[] = $check['id'];
        } else {
            $failed[] = $check['id'];
        }
    }

    $c5Known = zinesh_architecture_validation_detect_c5_event_gate_signals($apiDir);
    $findings = [];
    foreach ($c5Known as $sigId) {
        if (in_array($sigId, ZINESH_ARCH_VALIDATION_C5_KNOWN_EXCEPTIONS, true)) {
            $findings[] = [
                'class' => 'Architecture Violation',
                'id' => 'C5_KNOWN_' . $sigId,
                'message' => "{$sigId} emit gate event okuması kullanıyor (bilinen istisna)",
                'status' => 'known_exception',
            ];
        }
    }

    return [
        'validation_suite_version' => ZINESH_ARCHITECTURE_VALIDATION_VERSION,
        'passed' => $failed === [],
        'summary' => [
            'total_checks' => count($allChecks),
            'passed_count' => count($passed),
            'failed_count' => count($failed),
            'failed_ids' => $failed,
        ],
        'checks' => $allChecks,
        'findings' => $findings,
        'known_limitations' => [
            'c5_known_exceptions' => ZINESH_ARCH_VALIDATION_C5_KNOWN_EXCEPTIONS,
            'regression_execution' => 'Bu suite regression çalıştırmaz; yalnızca paket varlığını doğrular',
            'stable_core_git_history' => 'Stable Core dosya değişikliği git geçmişi bu suite kapsamında değil',
        ],
    ];
}
