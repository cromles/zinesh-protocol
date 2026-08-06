<?php
declare(strict_types=1);

/**
 * Erken erişim kampanyası uçtan uca test (CLI — sunucuda çalıştırın).
 * php e2e-early-access-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/campaign_lib.php';
require_once $apiDir . '/early_access_lib.php';
require_once $apiDir . '/email_lib.php';
require_once $apiDir . '/kyc_lib.php';

const E2E_PREFIX = 'ea-e2e-';
const E2E_PASSWORD = 'E2eTestPass123!';

/** @var list<array<string,mixed>> */
$report = [];
$pass = 0;
$fail = 0;

function e2e_assert(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ✓ {$label}\n";
    } else {
        $fail++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

function e2e_pool(): array {
    return zinesh_json_read('founding_campaign.json');
}

/** @return array<string,mixed> */
function e2e_snapshot(string $uid): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['error' => 'user_not_found'];
    }
    zinesh_ensure_wallet_fields($user);
    zinesh_campaign_ensure_user_fields($user);
    $pool = e2e_pool();
    $progress = zinesh_campaign_user_progress($user);
    $sell = zinesh_early_access_sell_status($user);
    return [
        'uid' => $uid,
        'email' => $user['email'] ?? '',
        'fiziBalance' => round((float)$user['fiziBalance'], 2),
        'campaignFizi' => round(zinesh_campaign_fizi_held($user), 2),
        'purchasedFizi' => round(zinesh_purchased_fizi($user), 2),
        'sellableFizi' => round(zinesh_sellable_fizi($user), 2),
        'sellAllowed' => $sell['allowed'],
        'sellBlockReason' => $sell['reason'],
        'referrals' => (int)($user['campaignStats']['foundingReferrals'] ?? 0),
        'hasDeposit' => (float)($user['campaignStats']['verifiedDepositUsdt'] ?? 0) > 0,
        'campaignEarned' => (float)($progress['fiziEarnedFromCampaign'] ?? 0),
        'poolRemaining' => round((float)($pool['poolRemaining'] ?? 0), 2),
        'poolTotal' => round((float)($pool['poolTotal'] ?? 0), 2),
        'slotsUsed' => (int)($pool['slotsUsed'] ?? 0),
        'claimed' => $user['campaignsClaimed'] ?? [],
    ];
}

function e2e_log_step(string $step, string $uid, ?array $extra = null): void {
    global $report;
    $snap = e2e_snapshot($uid);
    if ($extra) {
        $snap = array_merge($snap, $extra);
    }
    $snap['step'] = $step;
    $report[] = $snap;
    echo "\n── {$step} ──\n";
    echo "  FIZI: {$snap['fiziBalance']} (kampanya: {$snap['campaignFizi']}, satın: {$snap['purchasedFizi']})\n";
    echo "  Kazanılan kampanya: {$snap['campaignEarned']} | Havuz: {$snap['poolRemaining']}/{$snap['poolTotal']}\n";
    echo "  Satış: " . ($snap['sellAllowed'] ? 'AÇIK' : 'KAPALI') . " | Satılabilir: {$snap['sellableFizi']}\n";
    if (!$snap['sellAllowed'] && !empty($snap['sellBlockReason'])) {
        echo "  Satış engeli: {$snap['sellBlockReason']}\n";
    }
}

function e2e_cleanup(): void {
    $users = zinesh_load_users();
    $before = count($users);
    $users = array_values(array_filter($users, static function (array $u): bool {
        $email = strtolower((string)($u['email'] ?? ''));
        return !str_starts_with($email, E2E_PREFIX);
    }));
    if (count($users) < $before) {
        zinesh_save_users($users);
        echo "Temizlik: " . ($before - count($users)) . " eski e2e kullanıcı silindi.\n";
    }
}

/** @return array<string,mixed> */
function e2e_create_user(int $num, ?string $referralCode = null): array {
    $email = E2E_PREFIX . str_pad((string)$num, 3, '0', STR_PAD_LEFT) . '@test.zinesh.local';
    $uid = bin2hex(random_bytes(16));
    $profile = [
        'uid' => $uid,
        'name' => 'E2E Test ' . $num,
        'email' => $email,
        'role' => 'web3',
        'trustScore' => 50,
        'fiziBalance' => 0.0,
        'usdtBalance' => 0.0,
        'escrowBalance' => 0.0,
        'purchasedFiziBalance' => 0.0,
        'campaignFiziBalance' => 0.0,
        'connectedWallets' => [],
        'passwordHash' => password_hash(E2E_PASSWORD, PASSWORD_DEFAULT),
        'createdAt' => date('c'),
        'kycStatus' => 'none',
        'referralCode' => strtoupper(substr($uid, 0, 8)),
        'foundingMember' => false,
        'emailVerified' => false,
        'campaignsClaimed' => [],
        'campaignStats' => [
            'completedJobs' => 0,
            'juryDuties' => 0,
            'correctJuryVotes' => 0,
            'foundingReferrals' => 0,
            'verifiedDepositUsdt' => 0.0,
            'firstFiziPurchase' => false,
            'agreementsCompleted' => 0,
        ],
        'isE2ETestUser' => true,
    ];
    if ($referralCode !== null && $referralCode !== '') {
        $profile['pendingReferralCode'] = strtoupper($referralCode);
    }

    $users = zinesh_load_users();
    $users[] = $profile;
    zinesh_save_users($users);
    return zinesh_find_user_by_uid($uid) ?? $profile;
}

function e2e_verify_email(string $uid): array {
    zinesh_update_user($uid, static function (array &$u) {
        $u['emailVerified'] = true;
        $u['emailVerifiedAt'] = date('c');
    });
    return zinesh_campaign_try_grant_signup_reward($uid);
}

function e2e_simulate_deposit(string $uid, float $amount = 5.0): array {
    zinesh_update_user($uid, static function (array &$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 6);
    });
    return zinesh_campaign_record_deposit($uid, $amount);
}

function e2e_add_purchased_fizi(string $uid, float $amount): void {
    zinesh_update_user($uid, static function (array &$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $u['fiziBalance'] = round((float)$u['fiziBalance'] + $amount, 6);
        $u['purchasedFiziBalance'] = round((float)($u['purchasedFiziBalance'] ?? 0) + $amount, 6);
    });
}

/** Satış kilidi — zincir/treasury okumadan (CLI hızlı yolu). */
function e2e_sell_gate(string $uid): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['blocked' => true, 'reason' => 'user_not_found', 'sellable' => 0.0];
    }
    zinesh_ensure_wallet_fields($user);
    $gate = zinesh_early_access_sell_status($user);
    return [
        'blocked' => !$gate['allowed'],
        'reason' => $gate['reason'],
        'sellable' => zinesh_sellable_fizi($user),
        'campaignFizi' => zinesh_campaign_fizi_held($user),
        'purchasedFizi' => zinesh_purchased_fizi($user),
    ];
}

// ─── Başlangıç ───
echo "=== Erken Erişim E2E Test ===\n";
e2e_cleanup();

$hero = e2e_create_user(1);
$heroUid = (string)$hero['uid'];
$heroRef = (string)$hero['referralCode'];
e2e_log_step('0 — Kayıt (10 kullanıcı oluşturuluyor)', $heroUid);

$extraUsers = [];
for ($i = 2; $i <= 10; $i++) {
    $extraUsers[$i] = e2e_create_user($i, $i <= 4 ? $heroRef : null);
}
e2e_assert(count($extraUsers) === 9, 'Toplam 10 test kullanıcısı oluşturuldu');

$s0 = e2e_snapshot($heroUid);
e2e_assert($s0['fiziBalance'] === 0.0, 'Başlangıç FIZI = 0');
e2e_assert($s0['sellAllowed'] === false, 'Satış başlangıçta kapalı');

// ─── 1 E-posta doğrulama (+10) ───
$grant1 = e2e_verify_email($heroUid);
e2e_log_step('1 — E-posta doğrulama (+10 FIZI)', $heroUid, ['grant' => $grant1]);
$s1 = e2e_snapshot($heroUid);
e2e_assert(!empty($grant1['claimed']), 'E-posta ödülü verildi');
e2e_assert($s1['campaignEarned'] === 10.0, 'Kampanya kazancı = 10');
e2e_assert($s1['sellAllowed'] === false, 'Satış hâlâ kapalı (yatırım+referans yok)');

$dupEmail = zinesh_campaign_try_grant_signup_reward($heroUid);
e2e_assert(empty($dupEmail['claimed']), 'E-posta ödülü ikinci kez alınamaz');

$dupClaim = zinesh_campaign_claim_reward($heroUid, 'founding_signup');
e2e_assert(empty($dupClaim['claimed']), 'founding_signup claim tekrar reddedildi');

// ─── 2 İlk USDT yatırma (+10) ───
$grant2 = e2e_simulate_deposit($heroUid, 5.0);
e2e_log_step('2 — İlk USDT yatırma (+10 FIZI)', $heroUid, ['grant' => $grant2]);
$s2 = e2e_snapshot($heroUid);
e2e_assert(!empty($grant2['claimed']), 'Yatırım ödülü verildi');
e2e_assert($s2['campaignEarned'] === 20.0, 'Kampanya kazancı = 20');
e2e_assert($s2['hasDeposit'] === true, 'Yatırım kaydı var');
e2e_assert($s2['sellAllowed'] === false, 'Satış kapalı (referans eksik)');

$dupDep = zinesh_campaign_claim_reward($heroUid, 'deposit_10_usdt');
e2e_assert(empty($dupDep['claimed']), 'Yatırım ödülü ikinci kez alınamaz');

// Satış kilidi (referans eksik)
e2e_add_purchased_fizi($heroUid, 100.0);
$s2b = e2e_snapshot($heroUid);
$gate1 = e2e_sell_gate($heroUid);
e2e_assert($s2b['purchasedFizi'] >= 100.0, 'Satın alınmış FIZI eklendi');
e2e_assert($s2b['sellableFizi'] === 0.0, 'Satılabilir FIZI = 0 (kilit aktif)');
e2e_assert($gate1['blocked'] === true, 'Satış kapısı reddedildi (referans eksik)');
e2e_assert($s2b['campaignFizi'] > 0 && $gate1['sellable'] === 0.0, 'Şartlar eksikken satış kapalı');

// ─── 3 KYC (+10) ───
$kyc = zinesh_campaign_claim_kyc($heroUid);
e2e_log_step('3 — KYC onayı (+10 FIZI)', $heroUid, ['grant' => $kyc]);
$s3 = e2e_snapshot($heroUid);
e2e_assert(!empty($kyc['claimed']), 'KYC ödülü verildi');
e2e_assert($s3['campaignEarned'] === 30.0, 'Kampanya kazancı = 30');

$dupKyc = zinesh_campaign_claim_reward($heroUid, 'founding_kyc');
e2e_assert(empty($dupKyc['claimed']), 'KYC ödülü tekrar alınamaz');

// ─── 4 Üç referans (+10) ───
for ($i = 2; $i <= 4; $i++) {
    $refUid = (string)$extraUsers[$i]['uid'];
    e2e_verify_email($refUid);
}
e2e_log_step('4 — 3 doğrulanmış referans (+10 FIZI)', $heroUid);
$s4 = e2e_snapshot($heroUid);
e2e_assert($s4['referrals'] >= 3, 'Referans sayısı >= 3');
e2e_assert($s4['campaignEarned'] === 40.0, 'Kampanya kazancı = 40');
e2e_assert($s4['sellAllowed'] === true, 'Satış AÇIK (yatırım + 3 referans)');

$s4b = e2e_snapshot($heroUid);
$gate2 = e2e_sell_gate($heroUid);
e2e_assert($s4b['sellableFizi'] > 0, 'Satılabilir FIZI > 0 (şartlar sağlandı)');
e2e_assert($gate2['blocked'] === false, 'Erken erişim satış kapısı geçildi');
e2e_assert(
    abs($s4b['sellableFizi'] - (float)$s4b['fiziBalance']) < 1e-6,
    'Satılabilir = toplam FIZI (kampanya dahil)'
);

$dupRef = zinesh_campaign_claim_reward($heroUid, 'referrals_3');
e2e_assert(empty($dupRef['claimed']), 'Referans ödülü tekrar alınamaz');

// ─── 5 İlk anlaşma (+10) ───
$agr = zinesh_campaign_record_agreement($heroUid);
e2e_log_step('5 — İlk anlaşma (+10 FIZI)', $heroUid, ['grant' => $agr]);
$s5 = e2e_snapshot($heroUid);
e2e_assert(!empty($agr['claimed']), 'Anlaşma ödülü verildi');
e2e_assert($s5['campaignEarned'] === 50.0, 'Kampanya kazancı = 50 (tavan)');
e2e_assert($s5['campaignEarned'] <= 50.0, '50 FIZI tavanı aşılmadı');

$dupAgr = zinesh_campaign_record_agreement($heroUid);
e2e_assert(empty($dupAgr['claimed']), 'Anlaşma ödülü tekrar alınamaz');

// ─── Kullanıcı 5–10: çift ödül denemeleri ───
echo "\n── Kullanıcı 5–10: çift ödül testleri ──\n";
for ($i = 5; $i <= 10; $i++) {
    $u = $extraUsers[$i];
    $uid = (string)$u['uid'];
    e2e_verify_email($uid);
    $snap = e2e_snapshot($uid);
    $dup = zinesh_campaign_try_grant_signup_reward($uid);
    e2e_assert(empty($dup['claimed']), "User{$i}: e-posta ödülü tekrar alınamaz");
    e2e_assert($snap['campaignEarned'] === 10.0, "User{$i}: yalnızca 10 FIZI signup");
}

// ─── Havuz kontrolü ───
$poolFinal = e2e_pool();
$expectedDistributed = 10.0 * 4 + 50.0; // user1=50, users 2-4=10 each, users 5-10=10 each = 50+30+60=140? 
// user1: 50, users 2,3,4: 10 each = 30, users 5-10: 6*10 = 60 → total 140 FIZI from campaign
$totalEarned = 0.0;
foreach (zinesh_load_users() as $u) {
    if (!str_starts_with(strtolower((string)($u['email'] ?? '')), E2E_PREFIX)) {
        continue;
    }
    $totalEarned += zinesh_campaign_fizi_held($u);
}
echo "\n── Özet ──\n";
echo "  Dağıtılan kampanya FIZI (e2e): {$totalEarned}\n";
echo "  Havuz kalan: {$poolFinal['poolRemaining']} / {$poolFinal['poolTotal']}\n";
echo "  PASS: {$pass} | FAIL: {$fail}\n";

$out = [
    'ok' => $fail === 0,
    'pass' => $pass,
    'fail' => $fail,
    'steps' => $report,
    'poolFinal' => $poolFinal,
    'totalCampaignFiziDistributed' => $totalEarned,
];
file_put_contents(
    zinesh_data_path('e2e-early-access-report.json'),
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);
echo "\nRapor: " . zinesh_data_path('e2e-early-access-report.json') . "\n";
exit($fail === 0 ? 0 : 1);
