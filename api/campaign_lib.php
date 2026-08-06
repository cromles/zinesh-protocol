<?php
declare(strict_types=1);

require_once __DIR__ . '/early_access_lib.php';
require_once __DIR__ . '/notifications_lib.php';
require_once __DIR__ . '/protocol_constants.php';

function zinesh_campaign_cfg(): array {
    $ea = function_exists('zinesh_early_access_cfg')
        ? zinesh_early_access_cfg()
        : (zinesh_config()['early_access'] ?? []);
    $maxUsers = (int)($ea['max_users'] ?? 500);
    $maxPerUser = (float)($ea['max_fizi_per_user'] ?? 500);
    $defaults = [
        'enabled' => true,
        'title' => 'Erken Erişim',
        'supply_percent' => 0.25,
        'pool_total' => $maxUsers * $maxPerUser,
        'slots_total' => $maxUsers,
        'reward_signup' => 100,
        'reward_kyc' => 100,
        'reward_deposit_10' => 100,
        'reward_agreement' => 100,
        'reward_first_fizi_buy' => 0,
        'reward_first_job' => 0,
        'reward_referrals_3' => 100,
        'reward_first_jury' => 0,
        'reward_first_correct_jury' => 0,
        'referrals_required' => 3,
    ];
    $cfg = zinesh_config()['founding_campaign'] ?? [];
    return array_merge($defaults, $cfg);
}

function zinesh_campaign_rewards_map(): array {
    $cfg = zinesh_campaign_cfg();
    return [
        'founding_signup' => (float)$cfg['reward_signup'],
        'founding_kyc' => (float)$cfg['reward_kyc'],
        'deposit_10_usdt' => (float)($cfg['reward_deposit_10'] ?? 0),
        'founding_agreement' => (float)($cfg['reward_agreement'] ?? 0),
        'first_fizi_buy' => (float)($cfg['reward_first_fizi_buy'] ?? 0),
        'first_job' => (float)($cfg['reward_first_job'] ?? 0),
        'referrals_3' => (float)$cfg['reward_referrals_3'],
        'first_jury' => (float)($cfg['reward_first_jury'] ?? 0),
        'first_correct_jury' => (float)($cfg['reward_first_correct_jury'] ?? 0),
    ];
}

function zinesh_campaign_max_per_user(): float {
    $ea = zinesh_early_access_cfg();
    $cap = (float)($ea['max_fizi_per_user'] ?? 500);
    $sum = array_sum(zinesh_campaign_rewards_map());
    return min($cap, $sum);
}

/** Kurucu programi FIZI odulleri aktif mi (site defteri veya platform sadakat puani). */
function zinesh_campaign_fizi_rewards_active(): bool
{
    if (function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled()) {
        return true;
    }
    return function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled();
}

/** Platform FIZI: oduller tek seferde, tum gorevler bitince cüzdana aktarilir. */
function zinesh_campaign_platform_deferred_payout(): bool
{
    return zinesh_campaign_fizi_rewards_active()
        && function_exists('zinesh_platform_fizi_enabled')
        && zinesh_platform_fizi_enabled()
        && !(function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled());
}

/** @return list<string> */
function zinesh_campaign_core_milestone_keys(): array
{
    return ['founding_signup', 'deposit_10_usdt', 'founding_kyc', 'referrals_3', 'founding_agreement'];
}

function zinesh_campaign_credit_fizi_reward(array &$user, float $reward): void
{
    if ($reward <= 0) {
        return;
    }
    zinesh_ensure_wallet_fields($user);
    if (function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled()) {
        $user['fiziBalance'] = round((float)$user['fiziBalance'] + $reward, 6);
        $user['campaignFiziBalance'] = round((float)($user['campaignFiziBalance'] ?? 0) + $reward, 6);
        return;
    }
    if (function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled()) {
        $user['platformFiziBalance'] = round((float)($user['platformFiziBalance'] ?? 0) + $reward, 6);
        $user['campaignFiziBalance'] = round((float)($user['campaignFiziBalance'] ?? 0) + $reward, 6);
    }
}

function zinesh_campaign_debit_fizi_reward(array &$user, float $reward): void
{
    if ($reward <= 0) {
        return;
    }
    zinesh_ensure_wallet_fields($user);
    if (function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled()) {
        $user['fiziBalance'] = round(max(0.0, (float)$user['fiziBalance'] - $reward), 6);
        $user['campaignFiziBalance'] = round(max(0.0, (float)($user['campaignFiziBalance'] ?? 0) - $reward), 6);
        return;
    }
    if (function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled()) {
        $user['platformFiziBalance'] = round(max(0.0, (float)($user['platformFiziBalance'] ?? 0) - $reward), 6);
        $user['campaignFiziBalance'] = round(max(0.0, (float)($user['campaignFiziBalance'] ?? 0) - $reward), 6);
    }
}

function zinesh_campaign_reward_notify_message(float $reward, bool $milestoneOnly = false): string
{
    if (zinesh_campaign_platform_deferred_payout()) {
        if ($milestoneOnly) {
            return 'Görev tamamlandı. Tüm başlangıç adımları bitince hesabın güncellenir.';
        }
        return 'Başlangıç görevlerin tamamlandı. Hesabın güncellendi.';
    }
    if (function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled()
        && !(function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled())) {
        return 'Görev tamamlandı.';
    }
    return 'Kampanya görevi tamamlandı.';
}

/** Tamamlanan ama henüz cüzdana aktarılmamış platform FIZI. */
function zinesh_campaign_pending_fizi(array $user): float
{
    if (!zinesh_campaign_platform_deferred_payout() || !empty($user['platformFiziReleased'])) {
        return 0.0;
    }
    $claimed = zinesh_campaign_resolved_claimed($user);
    $rewards = zinesh_campaign_rewards_map();
    $sum = 0.0;
    foreach (zinesh_campaign_core_milestone_keys() as $key) {
        if (!empty($claimed[$key])) {
            $sum += (float)($rewards[$key] ?? 0);
        }
    }
    return round($sum, 6);
}

/** Gecmis talepleri kontrol eder; tum gorevler bitince toplu odeme yapar. */
function zinesh_platform_fizi_sync_user(string $uid): void
{
    if ($uid === '' || !zinesh_campaign_platform_deferred_payout()) {
        return;
    }
    $user = zinesh_find_user_by_uid($uid);
    if ($user && empty($user['platformFiziReleased']) && !zinesh_campaign_all_milestones_complete($user)) {
        $bal = (float)($user['platformFiziBalance'] ?? 0);
        if ($bal > 0) {
            zinesh_update_user($uid, static function (array &$u) {
                $u['platformFiziBalance'] = 0.0;
                $u['campaignFiziBalance'] = 0.0;
            });
        }
    }
    zinesh_campaign_try_release_platform_fizi($uid);
}

/** Kampanya kaynaklı FİZİ miktarı (geriye dönük uyum dahil). */
function zinesh_campaign_fizi_held(array $user): float {
    zinesh_ensure_wallet_fields($user);
    zinesh_campaign_ensure_user_fields($user);
    if (function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled()
        && !(function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled())) {
        if (!empty($user['platformFiziReleased'])) {
            return max(0.0, (float)($user['platformFiziBalance'] ?? 0));
        }
        return 0.0;
    }
    $totalFizi = max(0.0, (float)($user['fiziBalance'] ?? 0));
    $tracked = max(0.0, (float)($user['campaignFiziBalance'] ?? 0));
    if ($tracked > 0) {
        return min($tracked, $totalFizi);
    }
    $claimed = zinesh_campaign_resolved_claimed($user);
    if ($claimed === []) {
        return 0.0;
    }
    $rewards = zinesh_campaign_rewards_map();
    $sum = 0.0;
    foreach (array_keys($claimed) as $key) {
        $sum += (float)($rewards[$key] ?? 0);
    }
    return min(round($sum, 6), $totalFizi);
}

function zinesh_campaign_default_state(): array {
    $cfg = zinesh_campaign_cfg();
    return [
        'poolTotal' => (float)$cfg['pool_total'],
        'poolRemaining' => (float)$cfg['pool_total'],
        'slotsTotal' => (int)$cfg['slots_total'],
        'slotsUsed' => 0,
        'enabled' => (bool)$cfg['enabled'],
        'initializedAt' => date('c'),
    ];
}

function zinesh_campaign_merge_default_state(array &$state): void {
    if (!empty($state['poolTotal'])) {
        return;
    }
    foreach (zinesh_campaign_default_state() as $key => $value) {
        $state[$key] = $value;
    }
}

function zinesh_campaign_ensure_state(): array {
    $persist = false;
    $state = zinesh_json_read('founding_campaign.json');
    if (empty($state) || !isset($state['poolTotal'])) {
        $state = zinesh_campaign_default_state();
        $persist = true;
    }
    if (zinesh_campaign_maybe_launch_reset($state)) {
        $persist = true;
    }
    if ($persist) {
        zinesh_json_write('founding_campaign.json', $state);
    }
    return $state;
}

/** Lansman nesli degistiginde havuz ve kontenjan sayaclarini sifirlar. */
function zinesh_campaign_maybe_launch_reset(array &$state): bool
{
    $cfg = zinesh_campaign_cfg();
    $gen = trim((string)($cfg['launch_generation'] ?? ''));
    if ($gen === '') {
        return false;
    }
    if ((string)($state['launchGeneration'] ?? '') === $gen) {
        return false;
    }
    $defaults = zinesh_campaign_default_state();
    $state['poolTotal'] = $defaults['poolTotal'];
    $state['poolRemaining'] = $defaults['poolRemaining'];
    $state['slotsTotal'] = $defaults['slotsTotal'];
    $state['slotsUsed'] = 0;
    $state['membersEnrolled'] = 0;
    $state['enabled'] = true;
    $state['launchGeneration'] = $gen;
    $state['updatedAt'] = date('c');
    return true;
}

function zinesh_campaign_ensure_user_fields(array &$user): void {
    if (!isset($user['foundingSlotConsumed'])) {
        $user['foundingSlotConsumed'] = false;
    }
    if (!isset($user['campaignStats']) || !is_array($user['campaignStats'])) {
        $user['campaignStats'] = [
            'completedJobs' => 0,
            'juryDuties' => 0,
            'correctJuryVotes' => 0,
            'foundingReferrals' => 0,
            'referralsVerified' => 0,
            'verifiedDepositUsdt' => 0.0,
            'firstFiziPurchase' => false,
            'agreementsCompleted' => 0,
        ];
    }
    if (!isset($user['campaignStats']['referralsVerified'])) {
        $user['campaignStats']['referralsVerified'] = (int)($user['campaignStats']['foundingReferrals'] ?? 0);
    }
    if (!isset($user['campaignStats']['agreementsCompleted'])) {
        $user['campaignStats']['agreementsCompleted'] = 0;
    }
    if (!isset($user['campaignStats']['verifiedDepositUsdt'])) {
        $user['campaignStats']['verifiedDepositUsdt'] = 0.0;
    }
    if (!isset($user['campaignStats']['firstFiziPurchase'])) {
        $user['campaignStats']['firstFiziPurchase'] = false;
    }
    if (!isset($user['campaignsClaimed']) || !is_array($user['campaignsClaimed'])) {
        $user['campaignsClaimed'] = [];
    }
    if (!isset($user['platformFiziReleased'])) {
        $user['platformFiziReleased'] = false;
    }
    if (empty($user['referralCode']) && !empty($user['uid'])) {
        $user['referralCode'] = strtoupper(substr((string)$user['uid'], 0, 8));
    }
}

/**
 * campaignsClaimed + kullanıcı durumundan (e-posta, KYC, istatistik) birleşik ödül durumu.
 *
 * @return array<string,bool>
 */
function zinesh_campaign_resolved_claimed(array $user): array {
    zinesh_campaign_ensure_user_fields($user);
    $claimed = [];
    foreach ($user['campaignsClaimed'] as $key => $value) {
        if ($value) {
            $claimed[(string)$key] = true;
        }
    }

    $stats = $user['campaignStats'] ?? [];
    $cfg = zinesh_campaign_cfg();
    $minDeposit = (float)(zinesh_config()['min_deposit_usdt'] ?? 10);
    $refRequired = (int)($cfg['referrals_required'] ?? 3);

    if (!empty($user['emailVerified']) || !empty($user['foundingMember'])) {
        $claimed['founding_signup'] = true;
    }
    if (($user['kycStatus'] ?? '') === 'approved') {
        $claimed['founding_kyc'] = true;
    }
    if ((float)($stats['verifiedDepositUsdt'] ?? 0) >= $minDeposit) {
        $claimed['deposit_10_usdt'] = true;
    }
    $refs = (int)($stats['referralsVerified'] ?? $stats['foundingReferrals'] ?? 0);
    if ($refs >= $refRequired) {
        $claimed['referrals_3'] = true;
    }
    if ((int)($stats['agreementsCompleted'] ?? 0) >= 1) {
        $claimed['founding_agreement'] = true;
    }
    if (!empty($stats['firstFiziPurchase'])) {
        $claimed['first_fizi_buy'] = true;
    }
    if ((int)($stats['completedJobs'] ?? 0) >= 1) {
        $claimed['first_job'] = true;
    }
    if ((int)($stats['juryDuties'] ?? 0) >= 1) {
        $claimed['first_jury'] = true;
    }
    if ((int)($stats['correctJuryVotes'] ?? 0) >= 1) {
        $claimed['first_correct_jury'] = true;
    }

    return $claimed;
}

function zinesh_campaign_persist_user_fields(string $uid): ?array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return null;
    }

    $needsSave = empty($user['referralCode'])
        || !isset($user['campaignStats'])
        || !is_array($user['campaignStats'])
        || !isset($user['campaignsClaimed'])
        || !is_array($user['campaignsClaimed']);

    if (!$needsSave) {
        return $user;
    }

    zinesh_update_user($uid, static function (array &$u) {
        zinesh_campaign_ensure_user_fields($u);
    });

    return zinesh_find_user_by_uid($uid);
}

/** Referans zincirinde döngü var mı (inviter → … → invited)? */
function zinesh_campaign_referral_loop_detected(string $inviterUid, string $invitedUid): bool {
    if ($inviterUid === '' || $invitedUid === '' || $inviterUid === $invitedUid) {
        return $inviterUid === $invitedUid && $inviterUid !== '';
    }
    $current = $inviterUid;
    $visited = [];
    while ($current !== '') {
        if ($current === $invitedUid) {
            return true;
        }
        if (isset($visited[$current])) {
            return true;
        }
        $visited[$current] = true;
        $user = zinesh_find_user_by_uid($current);
        if (!$user) {
            break;
        }
        $current = trim((string)($user['referredBy'] ?? ''));
    }
    return false;
}

/** @return array{ipAddress:string,deviceFingerprint:string,userAgent:string} */
function zinesh_campaign_referral_device_fields(array $user): array {
    return [
        'ipAddress' => trim((string)($user['ipAddress'] ?? $user['registrationIp'] ?? '')),
        'deviceFingerprint' => trim((string)($user['deviceFingerprint'] ?? '')),
        'userAgent' => trim((string)($user['userAgent'] ?? '')),
    ];
}

/** Aynı cihaz / IP / tarayıcı ile şüpheli referans. */
function zinesh_campaign_is_suspicious_referral(array $referrer, array $referee): bool {
    $r = zinesh_campaign_referral_device_fields($referrer);
    $e = zinesh_campaign_referral_device_fields($referee);

    if ($r['deviceFingerprint'] !== '' && $e['deviceFingerprint'] !== ''
        && $r['deviceFingerprint'] === $e['deviceFingerprint']) {
        return true;
    }
    if ($r['deviceFingerprint'] !== '' && $e['deviceFingerprint'] !== ''
        && $r['deviceFingerprint'] === $e['deviceFingerprint']
        && $r['ipAddress'] !== '' && $e['ipAddress'] !== ''
        && $r['ipAddress'] === $e['ipAddress']) {
        return true;
    }
    if ($r['deviceFingerprint'] !== '' && $e['deviceFingerprint'] !== ''
        && $r['deviceFingerprint'] === $e['deviceFingerprint']
        && $r['ipAddress'] !== '' && $e['ipAddress'] !== ''
        && $r['ipAddress'] === $e['ipAddress']
        && $r['userAgent'] !== '' && $e['userAgent'] !== ''
        && $r['userAgent'] === $e['userAgent']) {
        return true;
    }
    return false;
}

function zinesh_campaign_verified_referral_count(array $user): int {
    zinesh_campaign_ensure_user_fields($user);
    $stats = $user['campaignStats'] ?? [];
    if (isset($stats['referralsVerified'])) {
        return (int)$stats['referralsVerified'];
    }
    return (int)($stats['foundingReferrals'] ?? 0);
}

function zinesh_campaign_all_milestones_complete(array $user): bool {
    zinesh_campaign_ensure_user_fields($user);
    $claimed = zinesh_campaign_resolved_claimed($user);
    foreach (zinesh_campaign_core_milestone_keys() as $key) {
        if (empty($claimed[$key])) {
            return false;
        }
    }
    if (zinesh_campaign_platform_deferred_payout()) {
        return true;
    }
    if (function_exists('zinesh_site_fizi_ledger_enabled') && !zinesh_site_fizi_ledger_enabled()) {
        return true;
    }
    return zinesh_campaign_fizi_held($user) + 1e-9 >= zinesh_campaign_max_per_user();
}

/**
 * Tum kurucu gorevleri tamamlaninca platform FIZI paketini cüzdana aktarir.
 *
 * @return array{released:bool, amount:float, reason:?string}
 */
function zinesh_campaign_try_release_platform_fizi(string $uid): array
{
    if (!zinesh_campaign_platform_deferred_payout()) {
        return ['released' => false, 'amount' => 0.0, 'reason' => 'not_deferred'];
    }
    $user = zinesh_find_user_by_uid($uid);
    if (!$user || empty($user['foundingMember'])) {
        return ['released' => false, 'amount' => 0.0, 'reason' => 'not_founding_member'];
    }
    if (!empty($user['platformFiziReleased'])) {
        return ['released' => false, 'amount' => 0.0, 'reason' => 'already_released'];
    }
    if (!zinesh_campaign_all_milestones_complete($user)) {
        return ['released' => false, 'amount' => 0.0, 'reason' => 'incomplete'];
    }

    $amount = zinesh_campaign_max_per_user();
    $poolOk = false;
    zinesh_json_atomic('founding_campaign.json', static function (array &$state) use ($amount, &$poolOk) {
        zinesh_campaign_merge_default_state($state);
        $poolLeft = (float)($state['poolRemaining'] ?? 0);
        if ($poolLeft < $amount) {
            return false;
        }
        $state['poolRemaining'] = round($poolLeft - $amount, 6);
        $state['updatedAt'] = date('c');
        $poolOk = true;
        return true;
    });
    if (!$poolOk) {
        return ['released' => false, 'amount' => 0.0, 'reason' => 'pool_depleted'];
    }

    $credited = false;
    zinesh_json_atomic('users.json', static function (array &$users) use ($uid, $amount, &$credited) {
        foreach ($users as &$u) {
            if (($u['uid'] ?? '') !== $uid) {
                continue;
            }
            zinesh_ensure_wallet_fields($u);
            zinesh_campaign_ensure_user_fields($u);
            if (!empty($u['platformFiziReleased'])) {
                return false;
            }
            $u['platformFiziBalance'] = round($amount, 6);
            $u['campaignFiziBalance'] = round($amount, 6);
            $u['platformFiziReleased'] = true;
            $u['platformFiziReleasedAt'] = date('c');
            $credited = true;
            return true;
        }
        return false;
    });

    if (!$credited) {
        zinesh_json_atomic('founding_campaign.json', static function (array &$state) use ($amount) {
            $state['poolRemaining'] = round((float)($state['poolRemaining'] ?? 0) + $amount, 6);
            $state['updatedAt'] = date('c');
            return true;
        });
        return ['released' => false, 'amount' => 0.0, 'reason' => 'user_not_found'];
    }

    zinesh_campaign_try_consume_founder_slot($uid);
    zinesh_notify_user(
        $uid,
        'CAMPAIGN_REWARD',
        'Kurucu görevleri tamamlandı',
        zinesh_campaign_reward_notify_message($amount, false),
        ['claimKey' => 'founding_bundle', 'amount' => $amount]
    );
    zinesh_audit('campaign_platform_fizi_released', ['uid' => $uid, 'amount' => $amount]);

    return ['released' => true, 'amount' => $amount, 'reason' => null];
}

/** @return array{consumed:bool,reason:?string,memberNumber:?int} */
function zinesh_campaign_try_consume_founder_slot(string $uid): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user || empty($user['foundingMember'])) {
        return ['consumed' => false, 'reason' => 'not_founding_member', 'memberNumber' => null];
    }
    zinesh_campaign_ensure_user_fields($user);
    if (!empty($user['foundingSlotConsumed'])) {
        return ['consumed' => false, 'reason' => 'already_consumed', 'memberNumber' => $user['foundingMemberNumber'] ?? null];
    }
    if (!zinesh_campaign_all_milestones_complete($user)) {
        return ['consumed' => false, 'reason' => 'milestones_incomplete', 'memberNumber' => null];
    }

    $result = null;
    zinesh_json_atomic('founding_campaign.json', static function (array &$state) use (&$result) {
        zinesh_campaign_merge_default_state($state);
        if (!zinesh_campaign_slots_available($state)) {
            $result = ['consumed' => false, 'reason' => 'slots_full', 'memberNumber' => null];
            return false;
        }
        $state['slotsUsed'] = (int)($state['slotsUsed'] ?? 0) + 1;
        $state['updatedAt'] = date('c');
        $result = [
            'consumed' => true,
            'reason' => null,
            'memberNumber' => (int)$state['slotsUsed'],
        ];
        return true;
    });

    if ($result === null || empty($result['consumed'])) {
        return $result ?? ['consumed' => false, 'reason' => 'slots_full', 'memberNumber' => null];
    }

    zinesh_update_user($uid, static function (array &$u) use ($result) {
        zinesh_campaign_ensure_user_fields($u);
        $u['foundingSlotConsumed'] = true;
        $u['foundingSlotConsumedAt'] = date('c');
        if (!empty($result['memberNumber'])) {
            $u['foundingMemberNumber'] = (int)$result['memberNumber'];
        }
    });

    zinesh_audit('founder_slot_consumed', ['uid' => $uid, 'memberNumber' => $result['memberNumber'] ?? null]);

    return $result;
}

/** @return array{ok:bool, reason:?string, referrerName:?string, message:?string} */
function zinesh_campaign_validate_referral_code(string $code, ?string $excludeUid = null): array {
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['ok' => false, 'reason' => 'empty', 'referrerName' => null, 'message' => null];
    }
    if (!preg_match('/^[A-Z0-9]{6,12}$/', $code)) {
        return ['ok' => false, 'reason' => 'invalid_format', 'referrerName' => null, 'message' => null];
    }

    $referrer = zinesh_campaign_find_by_referral_code($code);
    if (!$referrer) {
        return ['ok' => false, 'reason' => 'not_found', 'referrerName' => null, 'message' => null];
    }
    if ($excludeUid !== null && ($referrer['uid'] ?? '') === $excludeUid) {
        return ['ok' => false, 'reason' => 'self_referral', 'referrerName' => null, 'message' => null];
    }
    if ($excludeUid !== null && zinesh_campaign_referral_loop_detected((string)$referrer['uid'], $excludeUid)) {
        return [
            'ok' => false,
            'reason' => 'referral_loop',
            'referrerName' => null,
            'message' => 'Referral loop detected',
        ];
    }

    return [
        'ok' => true,
        'reason' => null,
        'referrerName' => (string)($referrer['name'] ?? 'Kurucu üye'),
        'message' => null,
    ];
}

function zinesh_campaign_enrollment_available(array $state): bool
{
    $enrolled = (int)($state['membersEnrolled'] ?? 0);
    $total = (int)($state['slotsTotal'] ?? zinesh_campaign_cfg()['slots_total'] ?? 500);
    return $enrolled < $total;
}

function zinesh_campaign_slots_available(array $state): bool {
    if (zinesh_campaign_platform_deferred_payout()) {
        return zinesh_campaign_enrollment_available($state);
    }
    return (int)($state['slotsUsed'] ?? 0) < (int)($state['slotsTotal'] ?? 0);
}

function zinesh_campaign_is_active(array $state): bool {
    $cfg = zinesh_campaign_cfg();
    if (!($cfg['enabled'] ?? true) || !($state['enabled'] ?? true)) {
        return false;
    }
    if (zinesh_campaign_platform_deferred_payout()) {
        $bundle = zinesh_campaign_max_per_user();
        $poolLeft = (float)($state['poolRemaining'] ?? 0);
        return zinesh_campaign_enrollment_available($state) && $poolLeft >= $bundle;
    }
    $poolLeft = (float)($state['poolRemaining'] ?? 0);
    $minReward = min(zinesh_campaign_rewards_map());
    return zinesh_campaign_slots_available($state) && $poolLeft >= $minReward;
}

function zinesh_campaign_public_status(): array {
    $cfg = zinesh_campaign_cfg();
    $supply = (float)(zinesh_config()['fizi_total_supply'] ?? 10_000_000);
    $state = zinesh_campaign_ensure_state();
    $rewards = zinesh_campaign_rewards_map();
    $poolTotal = (float)($state['poolTotal'] ?? $cfg['pool_total']);
    $poolRemaining = (float)($state['poolRemaining'] ?? 0);
    $slotsTotal = (int)($state['slotsTotal'] ?? $cfg['slots_total']);
    $slotsUsed = (int)($state['slotsUsed'] ?? 0);
    $membersEnrolled = (int)($state['membersEnrolled'] ?? 0);
    $active = zinesh_campaign_is_active($state);
    $enrollmentOpen = zinesh_campaign_enrollment_available($state);
    $siteFiziOn = function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled();
    $platformFiziOn = function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled();
    $rewardsActive = zinesh_campaign_fizi_rewards_active();
    $tlMode = function_exists('zinesh_tl_mode_enabled') && zinesh_tl_mode_enabled();

    $milestoneDefs = [
        ['key' => 'signup', 'label' => 'E-posta doğrulama', 'reward' => $rewards['founding_signup']],
        ['key' => 'deposit_10', 'label' => $tlMode ? 'İlk TL yatırma' : 'İlk USDT yatırma', 'reward' => $rewards['deposit_10_usdt']],
        ['key' => 'kyc', 'label' => 'Kimlik doğrulama (KYC)', 'reward' => $rewards['founding_kyc']],
        ['key' => 'referrals_3', 'label' => '3 doğrulanmış referans', 'reward' => $rewards['referrals_3']],
        ['key' => 'agreement', 'label' => $tlMode ? 'İlk emanet oluşturma' : 'İlk zincir anlaşması', 'reward' => $rewards['founding_agreement']],
    ];
    $milestones = [];
    foreach ($milestoneDefs as $m) {
        $row = ['key' => $m['key'], 'label' => $m['label']];
        if ($rewardsActive) {
            $row['fizi'] = $m['reward'];
        }
        $milestones[] = $row;
    }

    $publicRewards = $rewardsActive ? [
        'signup' => $rewards['founding_signup'],
        'kyc' => $rewards['founding_kyc'],
        'deposit10' => $rewards['deposit_10_usdt'],
        'agreement10' => $rewards['founding_agreement'],
        'firstFiziBuy' => $rewards['first_fizi_buy'],
        'firstJob' => $rewards['first_job'],
        'referrals3' => $rewards['referrals_3'],
        'firstJury' => $rewards['first_jury'],
        'firstCorrectJury' => $rewards['first_correct_jury'],
        'maxPerUser' => zinesh_campaign_max_per_user(),
        'referralsRequired' => (int)($cfg['referrals_required'] ?? 3),
        'minDepositUsdt' => (float)(zinesh_config()['min_deposit_usdt'] ?? 10),
    ] : [
        'signup' => 0,
        'kyc' => 0,
        'deposit10' => 0,
        'agreement10' => 0,
        'firstFiziBuy' => 0,
        'firstJob' => 0,
        'referrals3' => 0,
        'firstJury' => 0,
        'firstCorrectJury' => 0,
        'maxPerUser' => 0,
        'referralsRequired' => (int)($cfg['referrals_required'] ?? 3),
        'minDepositUsdt' => (float)(zinesh_config()['min_deposit_usdt'] ?? 10),
    ];

    $earlyAccess = zinesh_early_access_public();
    if ($platformFiziOn && !$siteFiziOn) {
        $earlyAccess['maxFiziPerUser'] = (int)zinesh_campaign_max_per_user();
        $earlyAccess['minSwapUsdt'] = 0;
        $earlyAccess['sellRequiresDeposit'] = false;
        $earlyAccess['sellRequiresReferrals'] = 0;
    } elseif (!$siteFiziOn) {
        $earlyAccess['maxFiziPerUser'] = 0;
        $earlyAccess['minSwapUsdt'] = 0;
        $earlyAccess['sellRequiresDeposit'] = false;
        $earlyAccess['sellRequiresReferrals'] = 0;
    }

    if ($platformFiziOn && !$siteFiziOn) {
        $disclaimer = 'E-posta doğrulama, TL yatırma ve ilk emanet ile Zinesh güvenli ödeme sistemini kullanmaya başlayabilirsin.';
    } elseif ($siteFiziOn) {
        $disclaimer = 'Erken erişim dönemi: e-posta doğrulama ve başlangıç görevlerini tamamlayarak platforma erişim kazan.';
    } else {
        $disclaimer = 'E-posta doğrulama, TL yatırma ve ilk emanet ile Zinesh güvenli ödeme sistemini kullanmaya başlayabilirsin.';
    }

    return [
        'id' => 'founding_member',
        'title' => (string)$cfg['title'],
        'active' => $active,
        'ended' => !$active,
        'endReason' => !$active
            ? ($poolRemaining < $rewards['founding_signup'] ? 'pool_depleted' : ($slotsUsed >= $slotsTotal ? 'slots_full' : 'disabled'))
            : null,
        'totalSupply' => $supply,
        'supplyPercent' => (float)($cfg['supply_percent'] ?? 1),
        'poolTotal' => $poolTotal,
        'poolRemaining' => $poolRemaining,
        'poolDistributed' => round($poolTotal - $poolRemaining, 6),
        'poolPercentUsed' => $poolTotal > 0 ? round((($poolTotal - $poolRemaining) / $poolTotal) * 100, 1) : 100,
        'slotsTotal' => $slotsTotal,
        'slotsUsed' => $slotsUsed,
        'membersEnrolled' => $membersEnrolled,
        'slotsRemaining' => max(0, $slotsTotal - ($platformFiziOn ? $membersEnrolled : $slotsUsed)),
        'enrollmentOpen' => $enrollmentOpen,
        'rewards' => $publicRewards,
        'milestones' => $milestones,
        'earlyAccess' => $earlyAccess,
        'disclaimer' => $disclaimer,
        'siteFiziLedgerEnabled' => $siteFiziOn,
        'platformFiziEnabled' => $platformFiziOn && !$siteFiziOn,
    ];
}

function zinesh_campaign_user_progress(array $user): array {
    $uid = (string)($user['uid'] ?? '');
    if ($uid !== '') {
        zinesh_platform_fizi_sync_user($uid);
        $fresh = zinesh_find_user_by_uid($uid);
        if ($fresh) {
            $user = $fresh;
        }
    }
    zinesh_campaign_ensure_user_fields($user);
    $resolved = zinesh_campaign_resolved_claimed($user);
    $stats = $user['campaignStats'] ?? [];
    $rewards = zinesh_campaign_rewards_map();
    $cfg = zinesh_campaign_cfg();
    $refRequired = (int)($cfg['referrals_required'] ?? 3);

    $keys = [
        'founding_signup' => !empty($resolved['founding_signup']),
        'founding_kyc' => !empty($resolved['founding_kyc']),
        'deposit_10_usdt' => !empty($resolved['deposit_10_usdt']),
        'founding_agreement' => !empty($resolved['founding_agreement']),
        'first_fizi_buy' => !empty($resolved['first_fizi_buy']),
        'first_job' => !empty($resolved['first_job']),
        'referrals_3' => !empty($resolved['referrals_3']),
        'first_jury' => !empty($resolved['first_jury']),
        'first_correct_jury' => !empty($resolved['first_correct_jury']),
    ];

    $earned = 0.0;
    foreach ($keys as $key => $done) {
        if ($done) {
            $earned += $rewards[$key] ?? 0;
        }
    }

    return [
        'foundingMember' => !empty($user['foundingMember']),
        'foundingMemberNumber' => $user['foundingMemberNumber'] ?? null,
        'referralCode' => $user['referralCode'] ?? null,
        'claimed' => $keys,
        'stats' => [
            'completedJobs' => (int)($stats['completedJobs'] ?? 0),
            'juryDuties' => (int)($stats['juryDuties'] ?? 0),
            'correctJuryVotes' => (int)($stats['correctJuryVotes'] ?? 0),
            'foundingReferrals' => (int)($stats['foundingReferrals'] ?? 0),
            'referralsVerified' => (int)($stats['referralsVerified'] ?? $stats['foundingReferrals'] ?? 0),
            'verifiedDepositUsdt' => (float)($stats['verifiedDepositUsdt'] ?? 0),
            'firstFiziPurchase' => !empty($stats['firstFiziPurchase']),
            'agreementsCompleted' => (int)($stats['agreementsCompleted'] ?? 0),
            'referralsRequired' => $refRequired,
            'minDepositUsdt' => (float)(zinesh_config()['min_deposit_usdt'] ?? 10),
        ],
        'fiziEarnedFromCampaign' => zinesh_campaign_fizi_held($user),
        'platformFiziBalance' => zinesh_campaign_fizi_held($user),
        'platformFiziPending' => zinesh_campaign_pending_fizi($user),
        'platformFiziReleased' => !empty($user['platformFiziReleased']),
        'fiziMaxFromCampaign' => !empty($user['foundingMember']) ? zinesh_campaign_max_per_user() : 0,
        'earlyAccess' => zinesh_early_access_sell_status($user),
    ];
}

/** @return array{claimed:bool, amount:float, memberNumber:int|null, reason:?string} */
function zinesh_campaign_claim_signup(): array {
    $reward = zinesh_campaign_rewards_map()['founding_signup'];
    $deferred = zinesh_campaign_platform_deferred_payout();
    $reserveAmount = $deferred ? zinesh_campaign_max_per_user() : $reward;

    return zinesh_json_atomic('founding_campaign.json', static function (array &$state) use ($reward, $deferred, $reserveAmount) {
        zinesh_campaign_merge_default_state($state);
        if (!zinesh_campaign_slots_available($state)) {
            return ['claimed' => false, 'amount' => 0.0, 'memberNumber' => null, 'reason' => 'slots_full'];
        }
        $poolLeft = (float)$state['poolRemaining'];
        if ($poolLeft < $reserveAmount) {
            return ['claimed' => false, 'amount' => 0.0, 'memberNumber' => null, 'reason' => 'pool_depleted'];
        }
        $enrolled = (int)($state['membersEnrolled'] ?? 0) + 1;
        $state['membersEnrolled'] = $enrolled;
        if (!$deferred) {
            $state['poolRemaining'] = round($poolLeft - $reward, 6);
        }
        $state['updatedAt'] = date('c');
        return [
            'claimed' => true,
            'amount' => $reward,
            'memberNumber' => $enrolled,
            'reason' => null,
        ];
    });
}

/**
 * @return array{ok:bool, amount:float, reason:?string, claimKey:?string}
 */
function zinesh_campaign_claim_reward(string $uid, string $claimKey): array {
    $rewards = zinesh_campaign_rewards_map();
    if (!isset($rewards[$claimKey])) {
        return ['claimed' => false, 'amount' => 0.0, 'reason' => 'invalid_key', 'claimKey' => null];
    }

    $reward = $rewards[$claimKey];
    $user = zinesh_find_user_by_uid($uid);
    if (!$user || empty($user['foundingMember'])) {
        return ['claimed' => false, 'amount' => 0.0, 'reason' => 'not_founding_member', 'claimKey' => $claimKey];
    }

    zinesh_campaign_ensure_user_fields($user);

    $prereq = zinesh_campaign_prerequisite_met($user, $claimKey);
    if (!$prereq['ok']) {
        return ['claimed' => false, 'amount' => 0.0, 'reason' => $prereq['reason'], 'claimKey' => $claimKey];
    }

    $maxPerUser = zinesh_campaign_max_per_user();
    $userApply = zinesh_campaign_apply_user_reward_atomic($uid, $claimKey, $reward, $maxPerUser);
    if (!($userApply['ok'] ?? false)) {
        return [
            'claimed' => false,
            'amount' => 0.0,
            'reason' => $userApply['reason'] ?? 'already_claimed',
            'claimKey' => $claimKey,
        ];
    }

    $deferred = zinesh_campaign_platform_deferred_payout();
    $poolOk = true;
    if (!$deferred) {
        $poolOk = zinesh_json_atomic('founding_campaign.json', static function (array &$state) use ($reward) {
            zinesh_campaign_merge_default_state($state);
            $poolLeft = (float)$state['poolRemaining'];
            if ($poolLeft < $reward) {
                return false;
            }
            $state['poolRemaining'] = round($poolLeft - $reward, 6);
            $state['updatedAt'] = date('c');
            return true;
        }) === true;
    }

    if (!$poolOk) {
        zinesh_campaign_revert_user_reward_atomic($uid, $claimKey, $reward);
        return ['claimed' => false, 'amount' => 0.0, 'reason' => 'pool_depleted', 'claimKey' => $claimKey];
    }

    zinesh_audit('campaign_reward', ['uid' => $uid, 'claimKey' => $claimKey, 'amount' => $reward]);

    if ($deferred) {
        zinesh_campaign_try_release_platform_fizi($uid);
    } else {
        zinesh_campaign_try_consume_founder_slot($uid);
    }

    if ($claimKey === 'referrals_3') {
        zinesh_notify_user(
            $uid,
            'REFERRAL_COMPLETED',
            null,
            zinesh_campaign_reward_notify_message($reward, $deferred),
            ['claimKey' => $claimKey, 'amount' => $reward]
        );
    } elseif ($claimKey !== 'founding_kyc') {
        zinesh_notify_user(
            $uid,
            'CAMPAIGN_REWARD',
            null,
            zinesh_campaign_reward_notify_message($reward, $deferred),
            ['claimKey' => $claimKey, 'amount' => $reward]
        );
    }

    return ['claimed' => true, 'amount' => $reward, 'reason' => null, 'claimKey' => $claimKey];
}

/**
 * @return array{ok:bool, reason?:string}
 */
function zinesh_campaign_apply_user_reward_atomic(string $uid, string $claimKey, float $reward, float $maxPerUser): array {
    $result = ['ok' => false, 'reason' => 'user_not_found'];

    zinesh_json_atomic('users.json', static function (array &$users) use ($uid, $claimKey, $reward, $maxPerUser, &$result) {
        foreach ($users as &$u) {
            if (($u['uid'] ?? '') !== $uid) {
                continue;
            }
            zinesh_ensure_wallet_fields($u);
            zinesh_campaign_ensure_user_fields($u);
            if (empty($u['foundingMember'])) {
                $result = ['ok' => false, 'reason' => 'not_founding_member'];
                return false;
            }
            if (!empty($u['campaignsClaimed'][$claimKey])) {
                $result = ['ok' => false, 'reason' => 'already_claimed'];
                return false;
            }
            if (!zinesh_campaign_fizi_rewards_active()) {
                $u['campaignsClaimed'][$claimKey] = date('c');
                $result = ['ok' => true];
                return true;
            }
            if (zinesh_campaign_platform_deferred_payout()) {
                $u['campaignsClaimed'][$claimKey] = date('c');
                $result = ['ok' => true];
                return true;
            }
            $earnedSoFar = (float)($u['campaignFiziBalance'] ?? $u['platformFiziBalance'] ?? 0);
            if ($earnedSoFar + $reward > $maxPerUser + 1e-9) {
                $result = ['ok' => false, 'reason' => 'user_cap_reached'];
                return false;
            }
            zinesh_campaign_credit_fizi_reward($u, $reward);
            $u['campaignsClaimed'][$claimKey] = date('c');
            $result = ['ok' => true];
            return true;
        }
        $result = ['ok' => false, 'reason' => 'user_not_found'];
        return false;
    });

    return $result;
}

function zinesh_campaign_revert_user_reward_atomic(string $uid, string $claimKey, float $reward): void {
    zinesh_json_atomic('users.json', static function (array &$users) use ($uid, $claimKey, $reward) {
        foreach ($users as &$u) {
            if (($u['uid'] ?? '') !== $uid) {
                continue;
            }
            zinesh_ensure_wallet_fields($u);
            zinesh_campaign_ensure_user_fields($u);
            if (!zinesh_campaign_platform_deferred_payout()) {
                zinesh_campaign_debit_fizi_reward($u, $reward);
            }
            unset($u['campaignsClaimed'][$claimKey]);
            return true;
        }
        return false;
    });
}

/** @return array{ok:bool, reason:?string} */
function zinesh_campaign_prerequisite_met(array $user, string $claimKey): array {
    zinesh_campaign_ensure_user_fields($user);
    $stats = $user['campaignStats'];
    $cfg = zinesh_campaign_cfg();
    $refRequired = (int)($cfg['referrals_required'] ?? 3);

    switch ($claimKey) {
        case 'founding_signup':
            return !empty($user['emailVerified'])
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'email_not_verified'];
        case 'founding_kyc':
            return ($user['kycStatus'] ?? 'none') === 'approved'
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'kyc_not_approved'];
        case 'deposit_10_usdt': {
            $deposited = (float)($stats['verifiedDepositUsdt'] ?? 0);
            $minDeposit = (float)(zinesh_config()['min_deposit_usdt'] ?? 10);
            return $deposited >= $minDeposit
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'deposit_required'];
        }
        case 'founding_agreement':
            return ((int)($stats['agreementsCompleted'] ?? 0)) >= 1
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'agreement_required'];
        case 'first_fizi_buy':
            return !empty($stats['firstFiziPurchase'])
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'no_fizi_purchase'];
        case 'first_job':
            return ((int)($stats['completedJobs'] ?? 0)) >= 1
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'no_completed_job'];
        case 'referrals_3':
            return zinesh_campaign_verified_referral_count($user) >= $refRequired
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'referrals_insufficient'];
        case 'first_jury':
            return ((int)($stats['juryDuties'] ?? 0)) >= 1
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'no_jury_duty'];
        case 'first_correct_jury':
            return ((int)($stats['correctJuryVotes'] ?? 0)) >= 1
                ? ['ok' => true, 'reason' => null]
                : ['ok' => false, 'reason' => 'no_correct_jury'];
        default:
            return ['ok' => false, 'reason' => 'invalid_key'];
    }
}

function zinesh_campaign_claim_kyc(string $uid): array {
    zinesh_update_user($uid, static function (array &$u) {
        $u['kycStatus'] = 'approved';
    });
    $result = zinesh_campaign_claim_reward($uid, 'founding_kyc');
    if (!empty($result['claimed'])) {
        $amount = (float)($result['amount'] ?? 0);
        zinesh_notify_user(
            $uid,
            'KYC_APPROVED',
            null,
            'Kimlik doğrulaman tamamlandı.',
            ['amount' => $amount]
        );
    }
    return [
        'claimed' => $result['claimed'],
        'amount' => $result['amount'],
        'reason' => $result['reason'],
    ];
}

function zinesh_campaign_find_by_referral_code(string $code): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    foreach (zinesh_load_users() as $u) {
        zinesh_campaign_ensure_user_fields($u);
        if (strtoupper((string)($u['referralCode'] ?? '')) === $code) {
            return $u;
        }
    }
    return null;
}

/** @return array{ok:bool, reason:?string, message:?string} */
function zinesh_campaign_process_referral(string $newUid, string $referralCode): array {
    $referralCode = strtoupper(trim($referralCode));
    if ($referralCode === '') {
        return ['ok' => false, 'reason' => 'empty', 'message' => null];
    }

    $validation = zinesh_campaign_validate_referral_code($referralCode, $newUid);
    if (!$validation['ok']) {
        return [
            'ok' => false,
            'reason' => $validation['reason'] ?? 'invalid',
            'message' => $validation['message'] ?? null,
        ];
    }

    $referrer = zinesh_campaign_find_by_referral_code($referralCode);
    if (!$referrer) {
        return ['ok' => false, 'reason' => 'not_found', 'message' => null];
    }

    $newUser = zinesh_find_user_by_uid($newUid);
    if (!$newUser || empty($newUser['foundingMember']) || !empty($newUser['referredBy'])) {
        return ['ok' => false, 'reason' => 'not_eligible', 'message' => null];
    }

    $pending = strtoupper(trim((string)($newUser['pendingReferralCode'] ?? '')));
    if ($pending === '' || $pending !== $referralCode) {
        return ['ok' => false, 'reason' => 'retroactive_referral', 'message' => 'Referral loop detected'];
    }

    $referrerUid = (string)$referrer['uid'];

    if (zinesh_campaign_is_suspicious_referral($referrer, $newUser)) {
        zinesh_audit('referral_rejected', [
            'newUid' => $newUid,
            'referrerUid' => $referrerUid,
            'reason' => 'suspicious_referral',
            'message' => 'Suspicious referral detected',
        ]);
        return ['ok' => false, 'reason' => 'suspicious_referral', 'message' => 'Suspicious referral detected'];
    }

    zinesh_update_user($newUid, static function (array &$u) use ($referrerUid, $referralCode) {
        $u['referredBy'] = $referrerUid;
        $u['referredAt'] = date('c');
        $u['referredWithCode'] = $referralCode;
    });

    zinesh_update_user($referrerUid, static function (array &$u) {
        zinesh_campaign_ensure_user_fields($u);
        $u['campaignStats']['foundingReferrals'] = (int)($u['campaignStats']['foundingReferrals'] ?? 0) + 1;
        $u['campaignStats']['referralsVerified'] = (int)($u['campaignStats']['referralsVerified'] ?? 0) + 1;
    });

    $updated = zinesh_find_user_by_uid($referrerUid);
    $cfg = zinesh_campaign_cfg();
    if ($updated && zinesh_campaign_verified_referral_count($updated) >= (int)$cfg['referrals_required']) {
        zinesh_campaign_claim_reward($referrerUid, 'referrals_3');
    }

    zinesh_audit('referral_counted', [
        'newUid' => $newUid,
        'referrerUid' => $referrerUid,
        'code' => $referralCode,
        'totalReferrals' => zinesh_campaign_verified_referral_count($updated ?? []),
    ]);

    $joinLabel = trim((string)($newUser['displayName'] ?? $newUser['email'] ?? 'Yeni üye'));
    zinesh_notify_user(
        $referrerUid,
        'REFERRAL_JOINED',
        null,
        sprintf('Davet kodunla %s katıldı.', $joinLabel),
        ['newUid' => $newUid, 'referralCode' => $referralCode]
    );

    return ['ok' => true, 'reason' => null, 'message' => null];
}

/** @return array{claimed:bool, amount:float, reason:?string} */
function zinesh_campaign_record_job_complete(string $uid): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user || empty($user['foundingMember'])) {
        return ['claimed' => false, 'amount' => 0.0, 'reason' => 'not_founding_member'];
    }

    zinesh_update_user($uid, static function (array &$u) {
        zinesh_campaign_ensure_user_fields($u);
        $u['campaignStats']['completedJobs'] = (int)($u['campaignStats']['completedJobs'] ?? 0) + 1;
    });

    $result = zinesh_campaign_claim_reward($uid, 'first_job');
    return [
        'claimed' => $result['claimed'],
        'amount' => $result['amount'],
        'reason' => $result['reason'],
    ];
}

/** @return array{claimed:bool, amount:float, juryClaim:array, correctClaim:array} */
function zinesh_campaign_record_jury_vote(string $uid, bool $correct = true): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user || empty($user['foundingMember'])) {
        return [
            'claimed' => false,
            'amount' => 0.0,
            'juryClaim' => ['claimed' => false, 'amount' => 0.0],
            'correctClaim' => ['claimed' => false, 'amount' => 0.0],
        ];
    }

    zinesh_update_user($uid, static function (array &$u) use ($correct) {
        zinesh_campaign_ensure_user_fields($u);
        $u['campaignStats']['juryDuties'] = (int)($u['campaignStats']['juryDuties'] ?? 0) + 1;
        if ($correct) {
            $u['campaignStats']['correctJuryVotes'] = (int)($u['campaignStats']['correctJuryVotes'] ?? 0) + 1;
        }
    });

    $juryClaim = zinesh_campaign_claim_reward($uid, 'first_jury');
    $correctClaim = $correct
        ? zinesh_campaign_claim_reward($uid, 'first_correct_jury')
        : ['claimed' => false, 'amount' => 0.0, 'reason' => 'not_correct'];

    $total = ($juryClaim['claimed'] ? $juryClaim['amount'] : 0)
        + ($correctClaim['claimed'] ? $correctClaim['amount'] : 0);

    return [
        'claimed' => $total > 0,
        'amount' => $total,
        'juryClaim' => $juryClaim,
        'correctClaim' => $correctClaim,
    ];
}

/**
 * E-posta doğrulandıktan sonra kurucu kayıt ödülünü verir.
 * @return array{claimed:bool, amount:float, memberNumber:int|null, reason:?string}
 */
function zinesh_campaign_try_grant_signup_reward(string $uid): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['claimed' => false, 'amount' => 0.0, 'memberNumber' => null, 'reason' => 'user_not_found'];
    }

    zinesh_campaign_ensure_user_fields($user);
    if (!empty($user['campaignsClaimed']['founding_signup'])) {
        return ['claimed' => false, 'amount' => 0.0, 'memberNumber' => $user['foundingMemberNumber'] ?? null, 'reason' => 'already_claimed'];
    }

    if (empty($user['emailVerified'])) {
        return ['claimed' => false, 'amount' => 0.0, 'memberNumber' => null, 'reason' => 'email_not_verified'];
    }

    $grant = zinesh_campaign_claim_signup();
    if (!$grant['claimed']) {
        return $grant;
    }

    zinesh_update_user($uid, static function (array &$u) use ($grant) {
        zinesh_ensure_wallet_fields($u);
        zinesh_campaign_ensure_user_fields($u);
        $u['foundingMember'] = true;
        $u['foundingMemberNumber'] = $grant['memberNumber'];
        if (!zinesh_campaign_platform_deferred_payout()) {
            zinesh_campaign_credit_fizi_reward($u, (float)$grant['amount']);
        }
        $u['campaignsClaimed']['founding_signup'] = date('c');
    });

    $pendingRef = (string)($user['pendingReferralCode'] ?? '');
    if ($pendingRef !== '') {
        zinesh_campaign_process_referral($uid, $pendingRef);
        zinesh_update_user($uid, static function (array &$u) {
            unset($u['pendingReferralCode']);
        });
    }

    zinesh_audit('campaign_signup_granted', ['uid' => $uid, 'amount' => $grant['amount']]);
    zinesh_campaign_try_release_platform_fizi($uid);

    return $grant;
}

/** @return array{claimed:bool, amount:float, reason:?string} */
function zinesh_campaign_record_agreement(string $uid): array {
    zinesh_update_user($uid, static function (array &$u) {
        zinesh_campaign_ensure_user_fields($u);
        $u['campaignStats']['agreementsCompleted'] = max(1, (int)($u['campaignStats']['agreementsCompleted'] ?? 0));
    });
    return zinesh_campaign_claim_reward($uid, 'founding_agreement');
}

/** @return array{claimed:bool, amount:float, verifiedDepositUsdt:float} */
function zinesh_campaign_record_deposit(string $uid, float $amount): array {
    if ($amount <= 0) {
        return ['claimed' => false, 'amount' => 0.0, 'verifiedDepositUsdt' => 0.0];
    }

    zinesh_update_user($uid, static function (array &$u) use ($amount) {
        zinesh_campaign_ensure_user_fields($u);
        $u['campaignStats']['verifiedDepositUsdt'] = round(
            (float)($u['campaignStats']['verifiedDepositUsdt'] ?? 0) + $amount,
            6
        );
    });

    $claim = zinesh_campaign_claim_reward($uid, 'deposit_10_usdt');
    $user = zinesh_find_user_by_uid($uid);
    $verified = 0.0;
    if ($user) {
        zinesh_campaign_ensure_user_fields($user);
        $verified = (float)($user['campaignStats']['verifiedDepositUsdt'] ?? 0);
    }

    return [
        'claimed' => $claim['claimed'],
        'amount' => $claim['amount'],
        'verifiedDepositUsdt' => $verified,
    ];
}

/** @return array{claimed:bool, amount:float} */
function zinesh_campaign_record_fizi_purchase(string $uid): array {
    zinesh_update_user($uid, static function (array &$u) {
        zinesh_campaign_ensure_user_fields($u);
        $u['campaignStats']['firstFiziPurchase'] = true;
    });

    return zinesh_campaign_claim_reward($uid, 'first_fizi_buy');
}
