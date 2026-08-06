<?php
declare(strict_types=1);

/**
 * Erken Erişim stres testleri TEST 5 (race) + TEST 6 (havuz tükenme).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/campaign_lib.php';
require_once $apiDir . '/early_access_lib.php';

const STRESS_PREFIX = 'stress-ea-';
const STRESS_BACKUP = 'stress-ea-campaign-backup.json';
const STRESS_PW_HASH = '$2y$10$stressTestOnlyNotForLoginUse000000000000000000000000000';

/** @var array<string, array{pass:int,fail:int,notes:list<string>}> */
$results = [
    'test5' => ['pass' => 0, 'fail' => 0, 'notes' => []],
    'test6' => ['pass' => 0, 'fail' => 0, 'notes' => []],
];

function st_assert(string $test, bool $cond, string $label): void {
    global $results;
    if ($cond) {
        $results[$test]['pass']++;
        echo "  ✓ {$label}\n";
    } else {
        $results[$test]['fail']++;
        echo "  ✗ FAIL: {$label}\n";
    }
}

function st_note(string $test, string $msg): void {
    global $results;
    $results[$test]['notes'][] = $msg;
    echo "  → {$msg}\n";
}

function st_pool(): array {
    return zinesh_json_read('founding_campaign.json');
}

function st_backup_campaign(): array {
    $state = st_pool();
    file_put_contents(
        zinesh_data_path(STRESS_BACKUP),
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    return $state;
}

function st_restore_campaign(): void {
    $path = zinesh_data_path(STRESS_BACKUP);
    if (!file_exists($path)) {
        return;
    }
    $state = json_decode((string)file_get_contents($path), true);
    if (is_array($state)) {
        zinesh_json_write('founding_campaign.json', $state);
        unlink($path);
    }
}

function st_cleanup_users(): int {
    $users = zinesh_load_users();
    $before = count($users);
    $users = array_values(array_filter($users, static function (array $u): bool {
        $email = strtolower((string)($u['email'] ?? ''));
        return !str_starts_with($email, STRESS_PREFIX);
    }));
    if (count($users) < $before) {
        zinesh_save_users($users);
    }
    return $before - count($users);
}

/** @param array<string,mixed> $extra */
function st_user_profile(string $tag, ?string $referralCode = null, array $extra = []): array {
    $uid = bin2hex(random_bytes(16));
    $fp = 'stress-fp-' . substr(hash('sha256', $tag), 0, 16);
    $profile = [
        'uid' => $uid,
        'name' => 'Stress ' . $tag,
        'email' => STRESS_PREFIX . $tag . '@test.zinesh.local',
        'role' => 'web3',
        'trustScore' => 50,
        'fiziBalance' => 0.0,
        'usdtBalance' => 0.0,
        'escrowBalance' => 0.0,
        'purchasedFiziBalance' => 0.0,
        'campaignFiziBalance' => 0.0,
        'connectedWallets' => [],
        'passwordHash' => STRESS_PW_HASH,
        'createdAt' => date('c'),
        'kycStatus' => 'none',
        'referralCode' => strtoupper(substr($uid, 0, 8)),
        'foundingMember' => false,
        'foundingSlotConsumed' => false,
        'emailVerified' => false,
        'ipAddress' => '198.51.100.' . ((abs(crc32($tag)) % 250) + 1),
        'deviceFingerprint' => $fp,
        'userAgent' => 'StressTest/1.0 (' . $tag . ')',
        'campaignsClaimed' => [],
        'campaignStats' => [
            'completedJobs' => 0,
            'juryDuties' => 0,
            'correctJuryVotes' => 0,
            'foundingReferrals' => 0,
            'referralsVerified' => 0,
            'verifiedDepositUsdt' => 0.0,
            'firstFiziPurchase' => false,
            'agreementsCompleted' => 0,
        ],
        'isStressTestUser' => true,
    ];
    if ($referralCode !== null && $referralCode !== '') {
        $profile['pendingReferralCode'] = strtoupper($referralCode);
    }
    foreach ($extra as $k => $v) {
        $profile[$k] = $v;
    }
    return $profile;
}

function st_append_users(array $profiles): void {
    if ($profiles === []) {
        return;
    }
    $users = zinesh_load_users();
    foreach ($profiles as $p) {
        $users[] = $p;
    }
    zinesh_save_users($users);
}

/** @return array<string,mixed> */
function st_create_user(string $tag, ?string $referralCode = null, array $extra = []): array {
    $profile = st_user_profile($tag, $referralCode, $extra);
    st_append_users([$profile]);
    return zinesh_find_user_by_uid((string)$profile['uid']) ?? $profile;
}

function st_verify(string $uid): array {
    zinesh_update_user($uid, static function (array &$u) {
        $u['emailVerified'] = true;
        $u['emailVerifiedAt'] = date('c');
    });
    return zinesh_campaign_try_grant_signup_reward($uid);
}

function st_deposit(string $uid, float $amount = 5.0): array {
    zinesh_update_user($uid, static function (array &$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 6);
    });
    return zinesh_campaign_record_deposit($uid, $amount);
}

function st_prepare_through_referrals(string $uid, string $refPrefix): void {
    st_verify($uid);
    st_deposit($uid, 5.0);
    zinesh_campaign_claim_kyc($uid);
    $referrer = zinesh_find_user_by_uid($uid);
    $code = (string)($referrer['referralCode'] ?? '');
    $batch = [];
    for ($i = 1; $i <= 3; $i++) {
        $batch[] = st_user_profile($refPrefix . '-r' . $i, $code);
    }
    st_append_users($batch);
    foreach ($batch as $p) {
        st_verify((string)$p['uid']);
    }
    st_grant_three_referrals($uid);
}

function st_ended_message(?string $reason): ?string {
    return match ($reason) {
        'slots_full', 'pool_depleted' => 'Kurucu Üye Kampanyası sona ermiştir.',
        default => null,
    };
}

/** @return array{code:int,results:list<array<string,mixed>>} */
function st_run_parallel_agreement(string $uidA, string $uidB): array {
    $apiDir = dirname(__DIR__);
    $worker = $apiDir . '/scripts/e2e-stress-race-worker.php';
    $php = PHP_BINARY ?: 'php';
    $cmd = static fn(string $uid): string => escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($uid);

    foreach ([$uidA, $uidB] as $uid) {
        $f = zinesh_data_path('stress-race-' . $uid . '.json');
        if (file_exists($f)) {
            unlink($f);
        }
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
    $p1 = proc_open($cmd($uidA), $descriptors, $pipes1, $apiDir, $env);
    $p2 = proc_open($cmd($uidB), $descriptors, $pipes2, $apiDir, $env);
    if (!is_resource($p1) || !is_resource($p2)) {
        return ['code' => 1, 'results' => []];
    }
    fclose($pipes1[0]);
    fclose($pipes2[0]);
    stream_get_contents($pipes1[1]);
    stream_get_contents($pipes2[1]);
    fclose($pipes1[1]);
    fclose($pipes2[1]);
    fclose($pipes1[2]);
    fclose($pipes2[2]);
    proc_close($p1);
    proc_close($p2);

    $results = [];
    foreach ([$uidA, $uidB] as $uid) {
        $f = zinesh_data_path('stress-race-' . $uid . '.json');
        $data = file_exists($f) ? json_decode((string)file_get_contents($f), true) : null;
        if (is_array($data)) {
            $results[] = $data;
            unlink($f);
        }
    }
    return ['code' => 0, 'results' => $results];
}

function st_grant_three_referrals(string $uid): void {
    zinesh_update_user($uid, static function (array &$u) {
        zinesh_campaign_ensure_user_fields($u);
        $u['campaignStats']['referralsVerified'] = 3;
        $u['campaignStats']['foundingReferrals'] = 3;
    });
    zinesh_campaign_claim_reward($uid, 'referrals_3');
}

function st_complete_five_tasks(string $uid, string $refPrefix): void {
    st_verify($uid);
    st_deposit($uid, 5.0);
    zinesh_campaign_claim_kyc($uid);
    // Referans signup ödülleri havuzu erken tüketmesin diye 3 doğrulanmış referans simüle edilir;
    // referrals_3 ödülü yine claim_reward ile verilir.
    st_grant_three_referrals($uid);
    zinesh_campaign_record_agreement($uid);
}

// ═══════════════════════════════════════════════════════════════
echo "=== Erken Erişim Stres Testleri (TEST 5 + 6) ===\n\n";
$originalCampaign = st_backup_campaign();
st_cleanup_users();

// ─── TEST 5: Race condition ───
echo "TEST 5 — Race Condition (slotsTotal=1)\n";
$test = 'test5';

$raceState = $originalCampaign;
$raceState['slotsTotal'] = 1;
$raceState['slotsUsed'] = 0;
$raceState['poolRemaining'] = max(200.0, (float)($raceState['poolRemaining'] ?? 200));
$raceState['poolTotal'] = max((float)($raceState['poolTotal'] ?? 25000), (float)$raceState['poolRemaining']);
$raceState['membersEnrolled'] = 0;
$raceState['stressTest'] = 'race-slots-1';
zinesh_json_write('founding_campaign.json', $raceState);

$userA = st_create_user('race-a');
$userB = st_create_user('race-b');
$uidA = (string)$userA['uid'];
$uidB = (string)$userB['uid'];

st_prepare_through_referrals($uidA, 'race-a');
st_prepare_through_referrals($uidB, 'race-b');

$beforeRace = st_pool();
st_note($test, "Yarış öncesi slotsUsed={$beforeRace['slotsUsed']}/{$beforeRace['slotsTotal']}");

$parallel = st_run_parallel_agreement($uidA, $uidB);
$raceResults = $parallel['results'];

$afterRace = st_pool();
$userAfterA = zinesh_find_user_by_uid($uidA) ?? [];
$userAfterB = zinesh_find_user_by_uid($uidB) ?? [];

$slotConsumedA = !empty($userAfterA['foundingSlotConsumed']);
$slotConsumedB = !empty($userAfterB['foundingSlotConsumed']);
$slotsUsed = (int)($afterRace['slotsUsed'] ?? 0);

st_note($test, "Yarış sonrası slotsUsed={$slotsUsed}");
st_note($test, 'UserA slot=' . ($slotConsumedA ? 'EVET' : 'HAYIR') . ' | UserB slot=' . ($slotConsumedB ? 'EVET' : 'HAYIR'));

$slotReasons = [];
foreach ($raceResults as $row) {
    $slotReasons[(string)$row['uid']] = (string)($row['slot']['reason'] ?? 'ok');
    st_note($test, "Worker {$row['uid']}: slot.consumed=" . (!empty($row['slot']['consumed']) ? 'true' : 'false')
        . ' reason=' . ($row['slot']['reason'] ?? 'null'));
}

$exactlyOneSlot = ($slotConsumedA + $slotConsumedB) === 1;

$loserReason = '';
foreach ($raceResults as $row) {
    if (empty($row['foundingSlotConsumed'])) {
        $loserReason = (string)($row['slot']['reason'] ?? '');
    }
}
if ($loserReason !== 'slots_full' && $exactlyOneSlot) {
    $loserUid = $slotConsumedA ? $uidB : $uidA;
    $retry = zinesh_campaign_try_consume_founder_slot($loserUid);
    $loserReason = (string)($retry['reason'] ?? '');
}

st_assert($test, count($raceResults) === 2, 'Paralel worker çıktısı alındı (2)');
st_assert($test, $exactlyOneSlot, 'Yalnızca bir kullanıcı slot aldı');
st_assert($test, $slotsUsed === 1, 'slotsUsed = 1');
st_assert($test, $slotsUsed <= 1, 'slotsUsed hiçbir zaman 1\'i geçmedi');
st_assert($test, $loserReason === 'slots_full', 'Kaybeden slots_full');

st_restore_campaign();
echo "\n";

// ─── TEST 6: Havuz tükenme ───
echo "TEST 6 — Havuz Tükenme (500 kullanıcı)\n";
$test = 'test6';

$poolState = $originalCampaign;
$poolState['slotsTotal'] = 500;
$poolState['slotsUsed'] = 0;
$poolState['poolTotal'] = 25000;
$poolState['poolRemaining'] = 25000;
$poolState['membersEnrolled'] = 0;
$poolState['enabled'] = true;
$poolState['stressTest'] = 'pool-500';
zinesh_json_write('founding_campaign.json', $poolState);

$batchProfiles = [];
$mainUids = [];
for ($i = 1; $i <= 500; $i++) {
    $tag = 'pool-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    $profile = st_user_profile($tag);
    $mainUids[] = (string)$profile['uid'];
    $batchProfiles[] = $profile;
    if (count($batchProfiles) >= 100) {
        st_append_users($batchProfiles);
        $batchProfiles = [];
    }
    if ($i % 100 === 0) {
        echo "  … {$i}/500 kullanıcı oluşturuldu\n";
    }
}
if ($batchProfiles !== []) {
    st_append_users($batchProfiles);
}
echo "  ✓ 500 ana kullanıcı oluşturuldu\n";

foreach ($mainUids as $idx => $uid) {
    $i = $idx + 1;
    $tag = 'pool-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
    st_complete_five_tasks($uid, $tag);
    if ($i % 50 === 0) {
        $p = st_pool();
        echo "  … {$i}/500 görev tamamlandı | slotsUsed={$p['slotsUsed']} poolRemaining={$p['poolRemaining']}\n";
    }
}

$after500 = st_pool();
st_note($test, "500 tamamlandı: slotsUsed={$after500['slotsUsed']}/500 poolRemaining={$after500['poolRemaining']}");

st_assert($test, (int)($after500['slotsUsed'] ?? 0) === 500, 'slotsUsed = 500');
st_assert($test, abs((float)($after500['poolRemaining'] ?? -1)) < 0.01, 'poolRemaining = 0');

$user501 = st_create_user('pool-501');
$uid501 = (string)$user501['uid'];
zinesh_update_user($uid501, static function (array &$u) {
    $u['emailVerified'] = true;
});
$grant501 = zinesh_campaign_try_grant_signup_reward($uid501);
$status501 = zinesh_campaign_public_status();
$msg501 = st_ended_message($grant501['reason'] ?? ($status501['endReason'] ?? null));

st_note($test, '501. kullanıcı grant: claimed=' . (!empty($grant501['claimed']) ? 'true' : 'false')
    . ' reason=' . ($grant501['reason'] ?? 'null'));
st_note($test, 'Kampanya active=' . (($status501['active'] ?? false) ? 'true' : 'false')
    . ' endReason=' . ($status501['endReason'] ?? 'null'));
st_note($test, 'Mesaj: ' . ($msg501 ?? '—'));

st_assert($test, empty($grant501['claimed']), '501. kullanıcı ödül alamadı');
st_assert($test, in_array($grant501['reason'] ?? '', ['pool_depleted', 'slots_full'], true), '501. kullanıcı reddedildi (pool/slots)');
st_assert($test, $msg501 === 'Kurucu Üye Kampanyası sona ermiştir.', 'Mesaj: Kurucu Üye Kampanyası sona ermiştir.');
st_assert($test, !($status501['active'] ?? true) || ($grant501['reason'] ?? '') === 'pool_depleted', 'Kampanya sona erdi veya havuz tükendi');

st_restore_campaign();

echo "\n── Test kullanıcı temizliği ──\n";
$removed = st_cleanup_users();
echo "  {$removed} stress-ea kullanıcı silindi.\n\n";

// ─── Özet ───
$totalPass = 0;
$totalFail = 0;
foreach ($results as $r) {
    $totalPass += $r['pass'];
    $totalFail += $r['fail'];
}

$verdict = static fn(string $k): string => $results[$k]['fail'] === 0 ? 'PASS' : 'FAIL';

$out = [
    'ok' => $totalFail === 0,
    'totalPass' => $totalPass,
    'totalFail' => $totalFail,
    'verdicts' => ['TEST5' => $verdict('test5'), 'TEST6' => $verdict('test6')],
    'results' => $results,
];
file_put_contents(
    zinesh_data_path('e2e-stress-early-access-report.json'),
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    LOCK_EX
);

echo "=== ÖZET ===\n";
echo "TEST 5 : {$out['verdicts']['TEST5']}\n";
echo "TEST 6 : {$out['verdicts']['TEST6']}\n";
echo "PASS: {$totalPass} | FAIL: {$totalFail}\n";
echo "Rapor: " . zinesh_data_path('e2e-stress-early-access-report.json') . "\n";

exit($totalFail === 0 ? 0 : 1);
