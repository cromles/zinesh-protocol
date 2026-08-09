<?php
declare(strict_types=1);

/**
 * Risk Observation Model v1.0 — resmi OBS-* tanımları (salt metadata + eşikler).
 * Kaynak: docs/RISK_OBSERVATION_MODEL.md
 * Değerlendirme: risk_observation_runtime_lib.php
 */
const ZINESH_RISK_OBSERVATION_MODEL_VERSION = '1.0';

/** @return list<string> */
function zinesh_risk_observation_catalog_ids(): array
{
    return [
        'OBS-CTR-001',
        'OBS-CTR-002',
        'OBS-CTR-003',
        'OBS-CTX-001',
        'OBS-DEL-001',
        'OBS-EVD-001',
        'OBS-HIS-001',
        'OBS-HIS-002',
        'OBS-HIS-003',
        'OBS-HIS-004',
        'OBS-NEG-001',
        'OBS-REV-001',
        'OBS-SCP-001',
        'OBS-SET-001',
        'OBS-TIM-001',
    ];
}

/** @return array<string,array<string,mixed>> */
function zinesh_risk_observation_catalog_definitions(): array
{
    return [
        'OBS-REV-001' => [
            'title' => 'High Revision Pattern',
            'required_signals' => ['SIG-NEG-001', 'SIG-CTR-001'],
            'optional_contexts' => ['CTX-SEC', 'CTX-SW', 'CTX-LNG'],
            'context_required' => true,
        ],
        'OBS-DEL-001' => [
            'title' => 'Delivery Uncertainty',
            'required_signals_any' => ['SIG-TIME-003', 'SIG-TIME-004'],
            'optional_contexts' => ['CTX-DIG', 'CTX-CON'],
            'context_required' => true,
        ],
        'OBS-CTR-001' => [
            'title' => 'Contract Ambiguity',
            'required_signals' => ['SIG-NEG-003'],
            'requires_incomplete_structured_terms' => true,
            'context_required' => true,
        ],
        'OBS-TIM-001' => [
            'title' => 'Frequent Deadline Changes',
            'required_signals' => ['SIG-CTR-001', 'SIG-TIME-004'],
            'optional_contexts' => ['CTX-LNG', 'CTX-B2B'],
            'context_required' => false,
        ],
        'OBS-SET-001' => [
            'title' => 'Settlement Instability',
            'required_signals_any' => ['SIG-SET-003', 'SIG-ACT-002', 'SIG-DSP-001'],
            'optional_contexts' => ['CTX-SW', 'CTX-B2B'],
            'context_required' => true,
        ],
        'OBS-NEG-001' => [
            'title' => 'High Negotiation Complexity',
            'required_signals' => ['SIG-TIME-002'],
            'required_signals_any' => ['SIG-NEG-002', 'SIG-NEG-001'],
            'optional_contexts' => ['CTX-B2B', 'CTX-ONE', 'CTX-SW', 'CTX-LNG'],
            'context_required' => true,
        ],
        'OBS-HIS-001' => [
            'title' => 'Low Historical Evidence',
            'required_signals' => ['SIG-BEH-001'],
            'optional_contexts' => ['CTX-ONE', 'CTX-B2B'],
            'context_required' => false,
        ],
        'OBS-HIS-002' => [
            'title' => 'First-Time Transaction',
            'required_signals' => ['SIG-BEH-001'],
            'optional_contexts' => ['CTX-ONE', 'CTX-B2B'],
            'context_required' => false,
        ],
        'OBS-HIS-003' => [
            'title' => 'Limited Collaboration History',
            'required_signals_absent' => ['SIG-BEH-002'],
            'preferred_contexts' => ['CTX-SEC', 'CTX-ONE'],
            'context_required' => false,
        ],
        'OBS-CTX-001' => [
            'title' => 'High Context Uncertainty',
            'requires_context_fallback' => true,
            'context_required' => true,
        ],
        'OBS-SCP-001' => [
            'title' => 'Repeated Scope Change',
            'required_signals' => ['SIG-CTR-001'],
            'required_signals_absent' => ['SIG-CTR-002'],
            'optional_contexts' => ['CTX-SW', 'CTX-SEC'],
            'context_required' => true,
        ],
        'OBS-CTR-002' => [
            'title' => 'Incomplete Contract',
            'requires_incomplete_structured_terms' => true,
            'required_signals_any' => ['SIG-NEG-003', 'SIG-CTR-001'],
            'context_required' => true,
        ],
        'OBS-EVD-001' => [
            'title' => 'Weak Evidence Chain',
            'required_signals' => ['SIG-ACT-001'],
            'optional_contexts' => ['CTX-LNG', 'CTX-DIG'],
            'context_required' => false,
        ],
        'OBS-HIS-004' => [
            'title' => 'Sparse History',
            'required_signals' => ['SIG-BEH-003'],
            'optional_contexts' => ['CTX-ONE'],
            'context_required' => false,
        ],
        'OBS-CTR-003' => [
            'title' => 'Low Contract Stability',
            'required_signals' => ['SIG-CTR-001'],
            'required_signals_absent' => ['SIG-CTR-002'],
            'optional_contexts' => ['CTX-B2B'],
            'context_required' => false,
        ],
    ];
}

/** @return array<string,string> */
function zinesh_risk_observation_catalog_descriptions(): array
{
    return [
        'OBS-REV-001' => 'Sözleşme revizyon yoğunluğu, bağlama göre dikkat gerektiren bir desen olarak gözlemlendi.',
        'OBS-DEL-001' => 'Teslim zamanlaması belirsizliği sinyalleri mevcut bağlamda birlikte okunuyor.',
        'OBS-CTR-001' => 'Düşük müzakere aktivitesi ile eksik yapısal sözleşme alanları birlikte görülüyor.',
        'OBS-TIM-001' => 'Sözleşme volatilitesi ve kaçırılmış teslim tarihi birlikte gözlemlendi.',
        'OBS-SET-001' => 'Settlement veya dispute sürecinde istikrarsızlık sinyalleri kayıtlı.',
        'OBS-NEG-001' => 'Müzakere karmaşıklığı ve uzun müzakere süresi üst dilimde gözlemlendi.',
        'OBS-HIS-001' => 'Actor veya oda için geçmiş kanıt sınırlı; confidence düşük olabilir.',
        'OBS-HIS-002' => 'Actor için ilk anlamlı işlem bandı gözlemlendi; geçmiş veri sınırlı.',
        'OBS-HIS-003' => 'Taraflar arasında tekrarlayan işbirliği sinyali gözlemlenmedi.',
        'OBS-CTX-001' => 'Context boyutlarında belirsizlik yüksek; yorum güvenilirliği sınırlı.',
        'OBS-SCP-001' => 'Kapsam değişimi deseni, kabul sonrası istikrar sinyali olmadan gözlemlendi.',
        'OBS-CTR-002' => 'Zorunlu yapısal sözleşme alanları eksik veya zayıf.',
        'OBS-EVD-001' => 'Event zincirinde uzun sessizlik aralığı gözlemlendi; kanıt zinciri zayıf olabilir.',
        'OBS-HIS-004' => 'Actor geçmişi seyrek; tamamlama tutarlılığı sınırlı örnekleme ile okunmalı.',
        'OBS-CTR-003' => 'Sözleşme volatilitesi var; kabul sonrası istikrar sinyali yok.',
    ];
}
