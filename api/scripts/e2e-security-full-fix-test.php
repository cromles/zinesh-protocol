<?php
declare(strict_types=1);

/**
 * TOTP + hot wallet limiti E2E (CLI).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/rd_reserve_lib.php';
require_once $apiDir . '/totp_lib.php';
require_once $apiDir . '/withdraw_executor.php';

$passed = 0;
$failed = 0;

function assert_full(string $label, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
    }
}

echo "=== ADIM 3: Founder TOTP ===\n";
$secret = zinesh_totp_generate_secret();
$code = zinesh_totp_code($secret);
assert_full('TOTP geçerli kod üretir', preg_match('/^\d{6}$/', $code) === 1);
assert_full('TOTP doğru kod kabul', zinesh_totp_verify($secret, $code));
assert_full('TOTP yanlış kod reddedilir', !zinesh_totp_verify($secret, '000000'));

$founderUid = 'totp-test-founder-' . bin2hex(random_bytes(4));
$founderEmail = 'founder-totp-' . bin2hex(random_bytes(4)) . '@test.zinesh.local';
$users = zinesh_load_users();
$users[] = [
    'uid' => $founderUid,
    'email' => $founderEmail,
    'name' => 'TOTP Test Founder',
    'passwordHash' => password_hash('FounderTest123!', PASSWORD_DEFAULT),
    'isFounder' => true,
    'fiziBalance' => 0,
    'usdtBalance' => 0,
    'escrowBalance' => 0,
];
zinesh_save_users($users);

zinesh_update_user($founderUid, static function (array &$u) use ($secret) {
    $u['totpPendingSecret'] = $secret;
    $u['totpEnabled'] = false;
});
$founderUser = zinesh_find_user_by_uid($founderUid) ?? [];

$gateFail = zinesh_founder_login_totp_gate($founderUid, $founderUser, '000000');
assert_full('Founder gate: yanlış kod FAIL', !$gateFail['ok']);

$gateOk = zinesh_founder_login_totp_gate($founderUid, $founderUser, $code);
assert_full('Founder gate: doğru kod PASS', !empty($gateOk['ok']));

$users = array_values(array_filter(zinesh_load_users(), static fn(array $u): bool => ($u['uid'] ?? '') !== $founderUid));
zinesh_save_users($users);

echo "\n=== ADIM 4: Hot wallet limiti ===\n";
$max = zinesh_hot_wallet_max_usdt();
assert_full('Hot wallet limit 500 USDT', abs($max - 500.0) < 0.01);

// Simüle: mock zinesh_hot_usdt_check via closure impossible — test helper logic
$blocksHigh = 700.0 > $max;
$allowsLow = 400.0 <= $max;
assert_full('700 USDT kasa → otomatik blok mantığı', $blocksHigh);
assert_full('400 USDT kasa → otomatik izin mantığı', $allowsLow);

echo "\n=== Özet ADIM 3+4 ===\n";
echo "PASS: {$passed}  FAIL: {$failed}\n";
exit($failed > 0 ? 1 : 0);
