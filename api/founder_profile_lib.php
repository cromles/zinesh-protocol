<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';

/**
 * Kurucu profil verisi — kampanya ve bakiye.
 *
 * @return array{campaignBalance:float,campaignEarned:float,tlBalance:float,escrowBalance:float}
 */
function zinesh_founder_profile(array $user): array
{
    zinesh_ensure_wallet_fields($user);
    zinesh_campaign_ensure_user_fields($user);

    $progress = zinesh_campaign_user_progress($user);
    $campaignEarned = round((float)($progress['fiziEarnedFromCampaign'] ?? 0), 2);

    return [
        'campaignBalance' => round((float)($user['campaignFiziBalance'] ?? 0), 2),
        'campaignEarned' => $campaignEarned,
        'tlBalance' => round((float)($user['usdtBalance'] ?? 0), 2),
        'escrowBalance' => round((float)($user['escrowBalance'] ?? 0), 2),
    ];
}

/**
 * @return array{wallet:array,campaign:array,founderProfile:array}
 */
function zinesh_founder_wallet_bundle(array $user, int $rdLedgerLimit = 50): array
{
    $profile = zinesh_founder_profile($user);
    $wallet = zinesh_wallet_state($user);
    $campaign = zinesh_campaign_user_progress($user);

    return [
        'wallet' => $wallet,
        'campaign' => $campaign,
        'founderProfile' => $profile,
    ];
}
