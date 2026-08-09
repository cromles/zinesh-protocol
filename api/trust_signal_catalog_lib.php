<?php
declare(strict_types=1);

/**
 * Trust Signal Catalog v0.1 — resmi SIG-* tanımları (salt metadata + eşikler).
 * Kaynak: docs/TRUST_SIGNAL_CATALOG.md
 * Değerlendirme: trust_signal_runtime_lib.php
 */
const ZINESH_TRUST_SIGNAL_CATALOG_VERSION = '0.1';

/** @return list<string> */
function zinesh_trust_signal_catalog_ids(): array
{
    return [
        'SIG-ACT-001',
        'SIG-ACT-002',
        'SIG-BEH-001',
        'SIG-BEH-002',
        'SIG-BEH-003',
        'SIG-CTR-001',
        'SIG-CTR-002',
        'SIG-DSP-001',
        'SIG-NEG-001',
        'SIG-NEG-002',
        'SIG-NEG-003',
        'SIG-SET-001',
        'SIG-SET-002',
        'SIG-SET-003',
        'SIG-TIME-001',
        'SIG-TIME-002',
        'SIG-TIME-003',
        'SIG-TIME-004',
    ];
}

/** @return array<string,array<string,mixed>> */
function zinesh_trust_signal_catalog_definitions(): array
{
    return [
        'SIG-NEG-001' => [
            'title' => 'Frequent Revisions',
            'category' => 'NEG',
            'event_types' => ['terms_proposed', 'terms_updated', 'counter_offer_created'],
            'metric_keys' => ['negotiation.negotiation_rounds', 'negotiation.terms_proposed_count'],
            'context_required' => true,
        ],
        'SIG-NEG-002' => [
            'title' => 'Counter Offer Frequency',
            'category' => 'NEG',
            'event_types' => ['counter_offer_created'],
            'metric_keys' => ['negotiation.counter_offer_count', 'negotiation.negotiation_rounds'],
            'context_required' => true,
        ],
        'SIG-NEG-003' => [
            'title' => 'Low Negotiation Activity',
            'category' => 'NEG',
            'event_types' => ['terms_proposed', 'terms_accepted'],
            'metric_keys' => ['negotiation.negotiation_rounds'],
            'context_required' => true,
        ],
        'SIG-TIME-001' => [
            'title' => 'Fast Agreement',
            'category' => 'TIME',
            'event_types' => ['terms_proposed', 'terms_accepted', 'contract_created'],
            'metric_keys' => ['time.contract_creation_time', 'time.acceptance_time'],
            'context_required' => true,
        ],
        'SIG-TIME-002' => [
            'title' => 'Long Negotiation',
            'category' => 'TIME',
            'event_types' => ['terms_proposed', 'terms_accepted', 'escrow_locked'],
            'metric_keys' => ['negotiation.negotiation_rounds', 'time.acceptance_time'],
            'context_required' => true,
        ],
        'SIG-TIME-003' => [
            'title' => 'Delivery Delay',
            'category' => 'TIME',
            'event_types' => ['escrow_locked', 'escrow_release_requested', 'settlement_completed'],
            'metric_keys' => ['time.duration_seconds'],
            'context_required' => true,
        ],
        'SIG-TIME-004' => [
            'title' => 'Missed Deadline',
            'category' => 'TIME',
            'event_types' => ['terms_accepted', 'settlement_completed'],
            'metric_keys' => ['time.settlement_time'],
            'context_required' => true,
        ],
        'SIG-DSP-001' => [
            'title' => 'Dispute Frequency',
            'category' => 'DSP',
            'event_types' => ['dispute_opened', 'dispute_resolved'],
            'metric_keys' => ['settlement.dispute_count', 'settlement.dispute_resolved'],
            'context_required' => true,
        ],
        'SIG-SET-001' => [
            'title' => 'Successful Settlement Pattern',
            'category' => 'SET',
            'event_types' => ['settlement_completed'],
            'metric_keys' => ['actor.successful_settlements', 'actor.transactions'],
            'context_required' => false,
        ],
        'SIG-SET-002' => [
            'title' => 'Escrow Completion Ratio',
            'category' => 'SET',
            'event_types' => ['escrow_locked', 'settlement_completed', 'settlement_failed'],
            'metric_keys' => ['settlement.successful_settlement', 'settlement.completion_status'],
            'context_required' => false,
        ],
        'SIG-SET-003' => [
            'title' => 'Settlement Failure Observed',
            'category' => 'SET',
            'event_types' => ['settlement_failed', 'escrow_recovered'],
            'metric_keys' => ['settlement.completion_status'],
            'context_required' => true,
        ],
        'SIG-BEH-001' => [
            'title' => 'First Transaction',
            'category' => 'BEH',
            'event_types' => ['settlement_completed', 'escrow_locked'],
            'metric_keys' => ['actor.transactions'],
            'context_required' => false,
        ],
        'SIG-BEH-002' => [
            'title' => 'Repeat Collaboration',
            'category' => 'BEH',
            'event_types' => ['escrow_locked', 'settlement_completed'],
            'metric_keys' => ['peer_pair.room_count'],
            'context_required' => false,
        ],
        'SIG-BEH-003' => [
            'title' => 'Completion Consistency',
            'category' => 'BEH',
            'event_types' => ['settlement_completed'],
            'metric_keys' => ['actor.history.average_completion_time', 'actor.transactions'],
            'context_required' => true,
        ],
        'SIG-CTR-001' => [
            'title' => 'High Contract Volatility',
            'category' => 'CTR',
            'event_types' => ['terms_updated', 'counter_offer_created'],
            'metric_keys' => ['contract.version_count', 'contract.amount_change_count'],
            'context_required' => true,
        ],
        'SIG-CTR-002' => [
            'title' => 'Contract Stability',
            'category' => 'CTR',
            'event_types' => ['terms_accepted', 'terms_updated', 'counter_offer_created'],
            'metric_keys' => ['contract.post_acceptance_change_count'],
            'context_required' => false,
        ],
        'SIG-ACT-001' => [
            'title' => 'Platform Silence Period',
            'category' => 'ACT',
            'event_types' => [],
            'metric_keys' => ['activity.max_silence_gap_seconds'],
            'context_required' => false,
        ],
        'SIG-ACT-002' => [
            'title' => 'Escrow Recovery Observed',
            'category' => 'ACT',
            'event_types' => ['escrow_recovered'],
            'metric_keys' => ['activity.recovery_count'],
            'context_required' => true,
        ],
    ];
}

// v0.1 eşikleri — catalog semver ile versionlanır
const ZINESH_SIG_NEG001_MIN_ROUNDS = 3;
const ZINESH_SIG_NEG002_MIN_COUNTER = 2;
const ZINESH_SIG_NEG003_MAX_ROUNDS = 1;
const ZINESH_SIG_TIME001_MAX_SECONDS = 3600;
const ZINESH_SIG_TIME002_MIN_SECONDS = 604800;
const ZINESH_SIG_TIME002_MIN_ROUNDS = 5;
const ZINESH_SIG_TIME003_MIN_LOCK_TO_SETTLE = 1209600;
const ZINESH_SIG_ACT001_MIN_SILENCE = 1209600;
const ZINESH_SIG_CTR001_MIN_VERSIONS = 4;
const ZINESH_SIG_CTR001_MIN_AMOUNT_CHANGES = 2;
const ZINESH_SIG_BEH002_MIN_PAIR_ROOMS = 2;
const ZINESH_SIG_BEH003_MIN_TRANSACTIONS = 5;
const ZINESH_SIG_SET001_MIN_TRANSACTIONS = 3;
const ZINESH_SIG_SET001_MIN_SUCCESS_RATIO = 0.67;
