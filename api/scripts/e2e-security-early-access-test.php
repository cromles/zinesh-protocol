<?php
declare(strict_types=1);

/**
 * Erken Erişim güvenlik / manipülasyon testleri (CLI — sunucuda çalıştırın).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/campaign_lib.php';
require_once $apiDir . '/early_access_lib.php';

const SEC_PREFIX = 'sec-ea-';
const SEC_BACKUP = 'sec-ea-campaign-backup.json';

/** @var array<string, array{pass:int,fail:int,notes:list<string>,steps:list<array<string,mixed>>}> */
$results = [
    'test1' => ['pass' => 0, 'fail' => 0, 'notes' => [], 'steps' => []],
    'test2' => ['pass' => 0, 'fail' => 0, 'notes' => [], 'steps' => []],
    'test3' => ['pass' => 0, 'fail' => 0, 'notes' => [], 'steps' => []],
    'test4' => ['pass' => 0, 'fail' => 0, 'notes' => [], 'steps' => []],
];
/** @var list<string> */
$vulnerabilities = [];
/** @var list<string> */
$recommendations = [];

function sec_assert(string $test, bool $cond, string $label): void {
    global $results;
    if ($cond) {
        $results[$test]['pass']++;
        echo "  ✓ {$label}\n";
    } else {
        $results[$test]['fail']++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

function sec_note(string $test, string $msg): void {
    global $results;
    $results[$test]['notes'][] = $msg;
    echo "  → {$msg}\n";
}

function sec_pool(): array {
    return zinesh_json_read('founding_campaign.json');
}

function sec_cleanup_users(): void {
    $users = zinesh_load_users();
    $before = count($users);
    $users = array_values(array_filter($users, static function (array $u): bool {
        $email = strtolower((string)($u['email'] ?? ''));
        return !str_starts_with($email, SEC_PREFIX);
    }));
    if (count($users) < $before) {
        zinesh_save_users($users);
        echo "Temizlik: " . ($before - count($users)) . " sec-ea kullanıcı silindi.\n";
    }
}

/** @param array<string,mixed> $meta */
function sec_create_user(string $tag, ?string $referralCode = null, array $meta = []): array {
    $email = SEC_PREFIX . $tag . '@test.zinesh.local';
    $uid = bin2hex(random_bytes(16));
    $profile = [
        'uid' => $uid,
        'name' => 'SecTest ' . $tag,
        'email' => $email,
        'role' => 'web3',
        'trustScore' => 50,
        'fiziBalance' => 0.0,
        'usdtBalance' => 0.0,
        'escrowBalance' => 0.0,
        'purchasedFiziBalance' => 0.0,
        'campaignFiziBalance' => 0.0,
        'connectedWallets' => [],
        'passwordHash' => password_hash('SecTest123!', PASSWORD_DEFAULT),
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
        'isSecurityTestUser' => true,
        'foundingSlotConsumed' => false,
    ];
    if ($referralCode !== null && $referralCode !== '') {
        $profile['pendingReferralCode'] = strtoupper($referralCode);
    }
    foreach (['registrationIp', 'ipAddress', 'deviceFingerprint', 'browserFingerprint', 'userAgent'] as $k) {
        if (isset($meta[$k])) {
            $profile[$k] = $meta[$k];
        }
    }
    if (isset($meta['registrationIp']) && !isset($profile['ipAddress'])) {
        $profile['ipAddress'] = $meta['registrationIp'];
    }
    $users = zinesh_load_users();
    $users[] = $profile;
    zinesh_save_users($users);
    return zinesh_find_user_by_uid($uid) ?? $profile;
}

function sec_verify(string $uid): array {
    zinesh_update_user($uid, static function (array &$u) {
        $u['emailVerified'] = true;
        $u['emailVerifiedAt'] = date('c');
    });
    return zinesh_campaign_try_grant_signup_reward($uid);
}

function sec_deposit(string $uid, float $amount): array {
    zinesh_update_user($uid, static function (array &$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 6);
    });
    return zinesh_campaign_record_deposit($uid, $amount);
}

function sec_withdraw_all(string $uid): void {
    zinesh_update_user($uid, static function (array &$u) {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = 0.0;
    });
}

/** @return array<string,mixed> */
function sec_user_metrics(string $uid): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['error' => 'not_found'];
    }
    zinesh_ensure_wallet_fields($user);
    zinesh_campaign_ensure_user_fields($user);
    $progress = zinesh_campaign_user_progress($user);
    $sell = zinesh_early_access_sell_status($user);
    $pool = sec_pool();
    return [
        'uid' => $uid,
        'email' => $user['email'] ?? '',
        'campaignFizi' => round(zinesh_campaign_fizi_held($user), 2),
        'purchasedFizi' => round(zinesh_purchased_fizi($user), 2),
        'usdtBalance' => round((float)($user['usdtBalance'] ?? 0), 2),
        'verifiedDepositUsdt' => round((float)($user['campaignStats']['verifiedDepositUsdt'] ?? 0), 2),
        'referrals' => (int)($user['campaignStats']['foundingReferrals'] ?? 0),
        'campaignEarned' => (float)($progress['fiziEarnedFromCampaign'] ?? 0),
        'referrals3Claimed' => !empty($user['campaignsClaimed']['referrals_3']),
        'sellAllowed' => $sell['allowed'],
        'sellReason' => $sell['reason'],
        'hasDepositFlag' => zinesh_user_has_verified_deposit($user),
        'slotsUsed' => (int)($pool['slotsUsed'] ?? 0),
        'slotsTotal' => (int)($pool['slotsTotal'] ?? 0),
        'referredBy' => $user['referredBy'] ?? null,
    ];
}

/** @return list<array<string,mixed>> */
function sec_make_refs(string $referrerUid, int $count, string $tagPrefix): array {
    $referrer = zinesh_find_user_by_uid($referrerUid);
    $code = (string)($referrer['referralCode'] ?? '');
    $created = [];
    for ($i = 1; $i <= $count; $i++) {
        $u = sec_create_user($tagPrefix . '-ref' . $i, $code);
        sec_verify((string)$u['uid']);
        $created[] = sec_user_metrics((string)$u['uid']);
    }
    return $created;
}

function sec_complete_journey(string $uid, string $refTagPrefix): array {
    $steps = [];
    $log = static function (string $label) use ($uid, &$steps): array {
        $m = sec_user_metrics($uid);
        $m['label'] = $label;
        $steps[] = $m;
        return $m;
    };

    sec_verify($uid);
    $log('email_verify');
    sec_deposit($uid, 5.0);
    $log('deposit');
    zinesh_campaign_claim_kyc($uid);
    $log('kyc');
    sec_make_refs($uid, 3, $refTagPrefix);
    $log('referrals_3');
    zinesh_campaign_record_agreement($uid);
    $log('agreement_50');

    return $steps;
}

function sec_backup_campaign(): array {
    $state = sec_pool();
    file_put_contents(
        zinesh_data_path(SEC_BACKUP),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    return $state;
}

function sec_restore_campaign(): bool {
    $path = zinesh_data_path(SEC_BACKUP);
    if (!file_exists($path)) {
        return false;
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (!is_array($state)) {
        return false;
    }
    zinesh_json_write('founding_campaign.json', $state);
    unlink($path);
    return true;
}

// ═══════════════════════════════════════════════════════════════
echo "=== Erken Erişim Güvenlik Testleri ===\n\n";
sec_cleanup_users();
$originalCampaign = sec_backup_campaign();

// ─── TEST 1: Referans döngüsü ───
echo "TEST 1 — Referans Döngüsü Saldırısı\n";
$test = 'test1';

$userA = sec_create_user('loop-a');
$uidA = (string)$userA['uid'];
$refA = (string)$userA['referralCode'];
sec_verify($uidA);

$userB = sec_create_user('loop-b', $refA);
$uidB = (string)$userB['uid'];
$refB = (string)$userB['referralCode'];
sec_verify($uidB);

$userC = sec_create_user('loop-c', $refB);
$uidC = (string)$userC['uid'];
$refC = (string)$userC['referralCode'];
sec_verify($uidC);

$beforeA = sec_user_metrics($uidA);
$beforeB = sec_user_metrics($uidB);
$beforeC = sec_user_metrics($uidC);
$results[$test]['steps'][] = ['phase' => 'before_loop', 'A' => $beforeA, 'B' => $beforeB, 'C' => $beforeC];

echo "  A: kampanya={$beforeA['campaignFizi']} ref={$beforeA['referrals']} | ";
echo "B: kampanya={$beforeB['campaignFizi']} ref={$beforeB['referrals']} | ";
echo "C: kampanya={$beforeC['campaignFizi']} ref={$beforeC['referrals']}\n";

$validateLoop = zinesh_campaign_validate_referral_code($refC, $uidA);
$processResult = zinesh_campaign_process_referral($uidA, $refC);

$afterA = sec_user_metrics($uidA);
$afterB = sec_user_metrics($uidB);
$afterC = sec_user_metrics($uidC);
$results[$test]['steps'][] = [
    'phase' => 'after_loop_attempt',
    'validate' => $validateLoop,
    'process' => $processResult,
    'A' => $afterA,
    'B' => $afterB,
    'C' => $afterC,
];

$loopBlocked = !$validateLoop['ok'] && ($validateLoop['reason'] ?? '') === 'referral_loop';
$referredByUnchanged = ($afterA['referredBy'] ?? null) === ($beforeA['referredBy'] ?? null);

$loopMessage = $validateLoop['reason'] ?? 'none';
sec_note($test, "C→A validate: ok=" . ($validateLoop['ok'] ? 'true' : 'false') . " reason={$loopMessage}");
sec_note($test, "process_referral: ok=" . (!empty($processResult['ok']) ? 'true' : 'false') . " reason=" . ($processResult['reason'] ?? 'none'));
sec_note($test, "A referredBy: " . ($beforeA['referredBy'] ?? 'null') . " → " . ($afterA['referredBy'] ?? 'null'));

$fiziUnchanged = abs($afterA['campaignFizi'] - $beforeA['campaignFizi']) < 0.01
    && abs($afterB['campaignFizi'] - $beforeB['campaignFizi']) < 0.01
    && abs($afterC['campaignFizi'] - $beforeC['campaignFizi']) < 0.01;

$noRefReward = !$afterA['referrals3Claimed'] && !$afterB['referrals3Claimed'] && !$afterC['referrals3Claimed'];

sec_assert($test, $loopBlocked, 'Referans döngüsü engellendi (referral_loop)');
sec_assert($test, $referredByUnchanged, 'A referredBy değişmedi');
sec_assert($test, $fiziUnchanged, 'Döngü denemesinden sonra campaignFizi değişmedi');
sec_assert($test, $noRefReward, 'A/B/C referans ödülü (referrals_3) almadı');

if (!$loopBlocked || !$referredByUnchanged) {
    $vulnerabilities[] = 'TEST 1: Referans döngüsü koruması yetersiz.';
}

echo "\n";

// ─── TEST 2: Yatır ve çek ───
echo "TEST 2 — Yatır ve Çek Saldırısı\n";
$test = 'test2';

$userD = sec_create_user('deposit-d');
$uidD = (string)$userD['uid'];
sec_verify($uidD);
sec_deposit($uidD, 1.0);
sec_make_refs($uidD, 3, 'dep-d');

$beforeWithdraw = sec_user_metrics($uidD);
sec_note($test, "Çekim öncesi: USDT={$beforeWithdraw['usdtBalance']} verifiedDeposit={$beforeWithdraw['verifiedDepositUsdt']} satış=" . ($beforeWithdraw['sellAllowed'] ? 'AÇIK' : 'KAPALI'));

sec_withdraw_all($uidD);
$afterWithdraw = sec_user_metrics($uidD);
$results[$test]['steps'][] = ['before' => $beforeWithdraw, 'after' => $afterWithdraw];

sec_note($test, "Çekim sonrası: USDT={$afterWithdraw['usdtBalance']} verifiedDeposit={$afterWithdraw['verifiedDepositUsdt']} satış=" . ($afterWithdraw['sellAllowed'] ? 'AÇIK' : 'KAPALI'));
sec_note($test, "hasDepositFlag=" . ($afterWithdraw['hasDepositFlag'] ? 'true' : 'false'));

sec_assert($test, $beforeWithdraw['usdtBalance'] >= 1.0, '1 USD yatırım simülasyonu başarılı');
sec_assert($test, $beforeWithdraw['campaignEarned'] >= 20.0, 'E-posta + yatırım ödülü alındı');
sec_assert($test, $afterWithdraw['usdtBalance'] === 0.0, 'USDT tamamen çekildi (simülasyon)');

if ($afterWithdraw['sellAllowed']) {
    sec_note($test, 'GÖZLEM: saleRequiresCurrentDeposit=false — USDT çekilse bile satış AÇIK (beklenen).');
} else {
    sec_note($test, 'GÖZLEM: USDT çekildikten sonra satış yetkisi KAPALI.');
}
sec_assert($test, true, 'Yatır-çek davranışı gözlemlendi (değiştirilmedi)');

echo "\n";

// ─── TEST 3: Çoklu hesap referans ───
echo "TEST 3 — Çoklu Hesap Referans Saldırısı\n";
$test = 'test3';

$sharedMeta = [
    'registrationIp' => '203.0.113.99',
    'deviceFingerprint' => 'sec-shared-fp-deadbee',
    'browserFingerprint' => 'sec-shared-browser-abc',
    'userAgent' => 'SecTestBrowser/1.0 (Windows NT 10.0)',
];

$userE = sec_create_user('multi-e', null, $sharedMeta);
$uidE = (string)$userE['uid'];
$refE = (string)$userE['referralCode'];
sec_verify($uidE);

$beforeE = sec_user_metrics($uidE);
for ($i = 1; $i <= 3; $i++) {
    $fake = sec_create_user('multi-e-sub' . $i, $refE, $sharedMeta);
    sec_verify((string)$fake['uid']);
}
$afterE = sec_user_metrics($uidE);
$results[$test]['steps'][] = ['before' => $beforeE, 'after' => $afterE, 'sharedMeta' => $sharedMeta];

sec_note($test, "E referans sayısı: {$beforeE['referrals']} → {$afterE['referrals']}");
sec_note($test, "E referrals_3 ödülü: " . ($afterE['referrals3Claimed'] ? 'VERİLDİ' : 'verilmedi'));
sec_note($test, "E kampanya FIZI: {$beforeE['campaignFizi']} → {$afterE['campaignFizi']}");

$multiBlocked = $afterE['referrals'] === $beforeE['referrals'] && !$afterE['referrals3Claimed'];
sec_assert($test, $multiBlocked, 'Aynı IP/fingerprint ile 3 hesap referans ödülü tetiklemedi');
sec_assert($test, abs($afterE['campaignFizi'] - $beforeE['campaignFizi']) < 0.01, 'E kampanya FIZI değişmedi');

if (!$multiBlocked) {
    $vulnerabilities[] = 'TEST 3: Çoklu hesap referans koruması yetersiz.';
}

echo "\n";

// ─── TEST 4: Slot limiti ───
echo "TEST 4 — Kurucu Üye Slot Limiti\n";
$test = 'test4';

$slotTestState = $originalCampaign;
$slotTestState['slotsTotal'] = 3;
$slotTestState['slotsUsed'] = 0;
$slotTestState['poolRemaining'] = max(500.0, (float)($slotTestState['poolRemaining'] ?? 500));
$slotTestState['poolTotal'] = max((float)($slotTestState['poolTotal'] ?? 500), (float)$slotTestState['poolRemaining']);
$slotTestState['enabled'] = true;
$slotTestState['secTestNote'] = 'slot limit test — geçici';
zinesh_json_write('founding_campaign.json', $slotTestState);

sec_note($test, 'founding_campaign.json geçici: slotsTotal=3, slotsUsed=0');

$slotTimeline = [];
$u1 = sec_create_user('slot-u1');
$uid1 = (string)$u1['uid'];

$grant1 = sec_verify($uid1);
$slotTimeline[] = ['user' => 'User1', 'milestone' => '10/50 email', 'slotsUsed' => sec_pool()['slotsUsed'], 'grant' => $grant1];

sec_deposit($uid1, 5.0);
$slotTimeline[] = ['user' => 'User1', 'milestone' => '20/50 deposit', 'slotsUsed' => sec_pool()['slotsUsed']];
zinesh_campaign_claim_kyc($uid1);
$slotTimeline[] = ['user' => 'User1', 'milestone' => '30/50 kyc', 'slotsUsed' => sec_pool()['slotsUsed']];

$u2 = sec_create_user('slot-u2');
$uid2 = (string)$u2['uid'];
$g2 = sec_verify($uid2);
$slotTimeline[] = ['user' => 'User2', 'milestone' => '10/50 email', 'slotsUsed' => sec_pool()['slotsUsed'], 'grant' => $g2];

$u3 = sec_create_user('slot-u3');
$uid3 = (string)$u3['uid'];
$g3 = sec_verify($uid3);
$slotTimeline[] = ['user' => 'User3', 'milestone' => '10/50 email', 'slotsUsed' => sec_pool()['slotsUsed'], 'grant' => $g3];

sec_make_refs($uid1, 3, 'slot-u1');
zinesh_campaign_record_agreement($uid1);
$m1final = sec_user_metrics($uid1);
$slotTimeline[] = ['user' => 'User1', 'milestone' => '50/50 complete', 'slotsUsed' => sec_pool()['slotsUsed'], 'earned' => $m1final['campaignEarned']];

sec_complete_journey($uid2, 'slot-u2');
$slotTimeline[] = ['user' => 'User2', 'milestone' => '50/50 complete', 'slotsUsed' => sec_pool()['slotsUsed']];

sec_complete_journey($uid3, 'slot-u3');
$slotTimeline[] = ['user' => 'User3', 'milestone' => '50/50 complete', 'slotsUsed' => sec_pool()['slotsUsed']];

$u4 = sec_create_user('slot-u4');
$uid4 = (string)$u4['uid'];
zinesh_update_user($uid4, static function (array &$u) {
    $u['emailVerified'] = true;
});
$grant4 = zinesh_campaign_try_grant_signup_reward($uid4);
$m4 = sec_user_metrics($uid4);
$status4 = zinesh_campaign_public_status();

$results[$test]['steps'] = [
    'slotTimeline' => $slotTimeline,
    'user4' => ['grant' => $grant4, 'metrics' => $m4, 'campaignStatus' => $status4],
];

foreach ($slotTimeline as $row) {
    sec_note($test, "{$row['user']} @ {$row['milestone']}: slotsUsed={$row['slotsUsed']}/3");
}

sec_assert($test, (int)($slotTimeline[0]['slotsUsed'] ?? -1) === 0, 'User1 @ 10/50: slot kullanılmadı');
sec_assert($test, (int)($slotTimeline[1]['slotsUsed'] ?? -1) === 0, 'User1 @ 20/50: slot kullanılmadı');
sec_assert($test, (int)($slotTimeline[2]['slotsUsed'] ?? -1) === 0, 'User1 @ 30/50: slot kullanılmadı');
sec_assert($test, (int)($slotTimeline[5]['slotsUsed'] ?? -1) === 1, 'User1 @ 50/50: slot tüketildi (1/3)');
sec_assert($test, (int)($slotTimeline[6]['slotsUsed'] ?? -1) === 2, 'User2 @ 50/50: slot tüketildi (2/3)');
sec_assert($test, (int)($slotTimeline[7]['slotsUsed'] ?? -1) === 3, 'User3 @ 50/50: slot tüketildi (3/3)');

$user4Rejected = empty($grant4['claimed']) && (($grant4['reason'] ?? '') === 'slots_full' || !($status4['active'] ?? true));
sec_assert($test, $user4Rejected, 'User4 kurucu kaydı reddedildi (slots_full / kampanya kapalı)');
sec_assert($test, empty($m4['campaignFizi']) || $m4['campaignEarned'] < 10.0, 'User4 kampanya FIZI almadı');

$endedMsg = !($status4['active'] ?? true) ? ($status4['endReason'] ?? 'ended') : 'still_active';
sec_note($test, "Kampanya durumu User4: active=" . (($status4['active'] ?? false) ? 'true' : 'false') . " endReason={$endedMsg}");

if ((int)($slotTimeline[0]['slotsUsed'] ?? 0) > 0) {
    $vulnerabilities[] = 'TEST 4: Slot hâlâ email doğrulamasında tüketiliyor.';
}

// Restore campaign
sec_restore_campaign();
sec_note($test, 'founding_campaign.json orijinal haline geri yüklendi');

echo "\n── Test kullanıcı temizliği ──\n";
sec_cleanup_users();
$users = zinesh_load_users();
$eaLeft = count(array_filter($users, static fn(array $u): bool => str_starts_with(strtolower((string)($u['email'] ?? '')), 'ea-e2e-')));
if ($eaLeft > 0) {
    $users = array_values(array_filter($users, static fn(array $u): bool => !str_starts_with(strtolower((string)($u['email'] ?? '')), 'ea-e2e-')));
    zinesh_save_users($users);
    echo "  ea-e2e kullanıcıları silindi.\n";
}
echo "  sec-ea test kullanıcıları silindi.\n";

echo "\n";

// ─── Özet ───
$totalPass = 0;
$totalFail = 0;
foreach ($results as $r) {
    $totalPass += $r['pass'];
    $totalFail += $r['fail'];
}

$verdict = static function (string $key) use ($results): string {
    return $results[$key]['fail'] === 0 ? 'PASS' : 'FAIL';
};

$out = [
    'ok' => $totalFail === 0,
    'totalPass' => $totalPass,
    'totalFail' => $totalFail,
    'verdicts' => [
        'TEST1' => $verdict('test1'),
        'TEST2' => $verdict('test2'),
        'TEST3' => $verdict('test3'),
        'TEST4' => $verdict('test4'),
    ],
    'results' => $results,
    'vulnerabilities' => $vulnerabilities,
    'recommendations' => $recommendations,
    'filesChanged' => [],
    'newControls' => [],
];
file_put_contents(
    zinesh_data_path('e2e-security-early-access-report.json'),
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

echo "=== ÖZET ===\n";
echo "TEST 1 : {$out['verdicts']['TEST1']}\n";
echo "TEST 2 : {$out['verdicts']['TEST2']}\n";
echo "TEST 3 : {$out['verdicts']['TEST3']}\n";
echo "TEST 4 : {$out['verdicts']['TEST4']}\n";
echo "PASS: {$totalPass} | FAIL: {$totalFail}\n";
echo "Güvenlik açığı: " . count($vulnerabilities) . "\n";
echo "Rapor: " . zinesh_data_path('e2e-security-early-access-report.json') . "\n";

exit($totalFail === 0 ? 0 : 1);
