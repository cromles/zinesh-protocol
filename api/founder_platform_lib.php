<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';

/** @return array<string,mixed> */
function zinesh_founder_platform_stats(): array
{
    $users = zinesh_load_users();
    $humanUsers = array_values(array_filter(
        $users,
        static fn(array $u): bool => empty($u['isSystemWallet'])
    ));

    $verifiedEmail = 0;
    $kycApproved = 0;
    $totalDeposit = 0.0;
    $totalEscrow = 0.0;

    foreach ($humanUsers as $u) {
        if (!empty($u['emailVerified'])) {
            $verifiedEmail++;
        }
        if (($u['kycStatus'] ?? '') === 'approved') {
            $kycApproved++;
        }
        zinesh_campaign_ensure_user_fields($u);
        zinesh_ensure_wallet_fields($u);
        $totalDeposit += (float)($u['campaignStats']['verifiedDepositUsdt'] ?? 0);
        $totalEscrow += (float)($u['escrowBalance'] ?? 0);
    }

    $now = time();
    $activeUids = [];
    $sessions = zinesh_json_read('sessions.json');
    if (is_array($sessions)) {
        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }
            if (zinesh_session_is_active($session, $now) && !empty($session['uid'])) {
                $activeUids[(string)$session['uid']] = true;
            }
        }
    }

    $treasury = zinesh_treasury_panel_stats();
    $campaign = zinesh_campaign_public_status();
    $tlMode = function_exists('zinesh_tl_mode_enabled') && zinesh_tl_mode_enabled();

    return [
        'updatedAt' => date('c'),
        'tlMode' => $tlMode,
        'users' => [
            'total' => count($humanUsers),
            'activeSessions' => count($activeUids),
            'emailVerified' => $verifiedEmail,
            'kycApproved' => $kycApproved,
            'foundingMembers' => (int)($campaign['slotsUsed'] ?? 0),
        ],
        'balances' => [
            'totalDeposits' => round($totalDeposit, 2),
            'totalEscrowLocked' => round($totalEscrow, 2),
            'userLiabilities' => (float)$treasury['kullaniciBorcu'],
            'availableReserve' => (float)$treasury['kullanilabilirRezerv'],
        ],
        'campaign' => [
            'remaining' => (float)($campaign['poolRemaining'] ?? 0),
            'total' => (float)($campaign['poolTotal'] ?? 0),
        ],
    ];
}
