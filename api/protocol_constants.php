<?php
declare(strict_types=1);

/**
 * Zinesh FİZİ ekonomik anayasası — FIZI_ANAYASA.md v1.0 ile birebir.
 * Toplam arz: 10.000.000 FİZİ | Denge max: 1.000.000 FİZİ (genesis 0).
 */
function zinesh_protocol_constants(): array
{
    $totalSupply = 10_000_000;
    $liquidityPool = 1_000_000;
    $campaignReserve = 25_000;
    $rdReserve = 3_000_000;
    $jobPool = 5_975_000;
    $dengeMax = 1_000_000;
    $dengeMid = 750_000;
    $dengeMin = 100_000;
    $dengeOnFaz = 700_000;

    return [
        'total_supply' => $totalSupply,
        'economic_supply_fizi' => $totalSupply,

        'liquidity_pool_fizi' => $liquidityPool,
        'sale_pool_fizi' => $liquidityPool,
        'sale_pool_percent' => 10,

        'campaign_reserve_fizi' => $campaignReserve,
        'campaign_reserve_percent' => 0.25,

        'rd_reserve_fizi' => $rdReserve,
        'rd_reserve_max_fizi' => $rdReserve,
        'max_rd_percent' => 30,

        'job_pool_fizi' => $jobPool,
        'job_pool_percent' => 59.75,

        'denge_kutusu_genesis_fizi' => 0,
        'denge_kutusu_max_fizi' => $dengeMax,
        'denge_kutusu_mid_fizi' => $dengeMid,
        'denge_kutusu_min_fizi' => $dengeMin,
        'denge_kutusu_on_faz_fizi' => $dengeOnFaz,
        'denge_hysteresis_fizi' => 25_000,

        'burn_reserve_fizi' => 0,
        'burn_reserve_percent' => 0,

        'floor_price_usdt' => 0.0,
        'campaign_fizi_sellable' => true,
        'rd_monthly_unlock_rate' => 0.01,
        'rd_monthly_unlock_min_fizi' => 5_000,
        'rd_monthly_unlock_day' => 21,
        'rd_monthly_unlock_hour' => 19,
        'rd_monthly_unlock_timezone' => 'Europe/Istanbul',
        'rd_inaugural_unlock_year' => 2026,
        'rd_inaugural_unlock_month' => 6,

        'job_commission_rate' => 0.05,
        'swap_fee_rate' => 0.005,

        'commission_reward_rate' => 0.40,
        'commission_dam_rate' => 0.30,
        'commission_founder_rate' => 0.30,
        'commission_contribution_rate' => 0.40,
        'commission_burn_rate' => 0.30,
        'commission_reward_bps' => 4000,
        'commission_dam_bps' => 3000,
        'commission_founder_bps' => 3000,
        'commission_contribution_bps' => 4000,
        'commission_burn_bps' => 3000,

        'reward_distribution_interval' => 'weekly',
        'reward_trust_score_min' => 50,
        'juror_trust_score_min' => 50,
        'reward_max_weekly_share_per_user' => 0.05,
        'reward_use_tier_weights' => true,

        'denge_injection_oran_min' => 0.0025,
        'denge_injection_oran_max' => 0.02,
        'denge_injection_vitrin_cap_rate' => 0.05,

        'account_max_vitrin_buy_fizi' => 10_000,
        'account_max_single_buy_fizi' => 1_000,
        'vitrin_buy_cap_months' => 12,
        'vitrin_low_threshold_fizi' => 50_000,

        'baraj_daily_buy_cap_rate' => 0.02,
        'baraj_daily_sell_cap_rate' => 0.01,
        'baraj_v_eff_min_fizi' => 2_000,
        'baraj_v_eff_max_fizi' => 100_000,
        'baraj_as_min_fizi' => 100,
        'baraj_as_max_fizi' => 50_000,
        'baraj_aa_min_usdt' => 10,
        'baraj_aa_max_usdt' => 25_000,
        'baraj_liquidity_release_ratio' => 0.70,
        'baraj_ecosystem_release_ratio' => 0.30,
        'baraj_spillway_monthly_rate' => 0.03,
        'baraj_mode_change_min_hours' => 24,
        'denge_overflow_to_reward' => false,

        'job_pool_min_escrow_fizi' => 100,
        'job_pool_full_unlock_volume_fizi' => 2_000_000,
        'protocol_launch_date' => '2026-01-01',

        /** Site defteri FIZI kapali — yalnizca zincir (MetaMask) FIZI kullanilir. */
        'site_fizi_ledger_enabled' => false,

        /**
         * Platform FIZI — kurucu programi odulleri; para/token degil, yalnizca platform icinde
         * biriktirilen sadakat puani (rozet ve avantajlar icin).
         */
        'platform_fizi_enabled' => true,

        /** Faz 1: TL modu — Web3 UI kapali, escrow site cüzdaninda (TL). */
        'tl_mode' => true,
        'web3_enabled' => false,
    ];
}

function zinesh_site_fizi_ledger_enabled(): bool
{
    return (bool)(zinesh_protocol_constants()['site_fizi_ledger_enabled'] ?? false);
}

function zinesh_platform_fizi_enabled(): bool
{
    return (bool)(zinesh_protocol_constants()['platform_fizi_enabled'] ?? false);
}
