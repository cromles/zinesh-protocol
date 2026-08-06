<?php
declare(strict_types=1);

/**
 * Kurucu TOTP kurulum/sıfırlama E2E (CLI, canlı veriye dokunmaz — geçici test kullanıcısı).
 */
$apiDir = dirname(__DIR__);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/rd_reserve_lib.php';
require_once $apiDir . '/totp_lib.php';
require_once $apiDir . '/totp_reset_lib.php';

$pass = 0;
$fail = 0;

function assert_totp(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) {
        echo "PASS: {$label}\n";
        $pass++;
    } else {
        echo "FAIL: {$label}\n";
        $fail++;
    }
}

echo "=== Kurucu TOTP E2E ===\n";

$founderEmail = 'yasinkarademir147@gmail.com';
$founder = zinesh_find_user_by_email($founderEmail);
assert_totp('Kurucu hesap bulundu', $founder !== null);
if ($founder) {
    assert_totp('Kurucu olarak işaretli', zinesh_is_founder($founder));
    $hasPending = trim((string)($founder['totpPendingSecret'] ?? '')) !== '';
    $hasSecret = trim((string)($founder['totpSecret'] ?? '')) !== '';
    echo "INFO: totpEnabled=" . (!empty($founder['totpEnabled']) ? 'true' : 'false')
        . " pending=" . ($hasPending ? 'yes' : 'no')
        . " secret=" . ($hasSecret ? 'yes' : 'no') . "\n";
}

$otherEmail = 'other-totp-' . bin2hex(random_bytes(4)) . '@test.zinesh.local';
$otherUid = 'other-' . bin2hex(random_bytes(8));
$testUid = 'totp-e2e-' . bin2hex(random_bytes(4));
$secret = zinesh_totp_generate_secret();
$code = zinesh_totp_code($secret);

$users = zinesh_load_users();
$users[] = [
    'uid' => $otherUid,
    'email' => $otherEmail,
    'name' => 'Other User',
    'passwordHash' => password_hash('OtherPass99!', PASSWORD_DEFAULT),
    'totpPendingSecret' => zinesh_totp_generate_secret(),
    'fiziBalance' => 0,
    'usdtBalance' => 0,
    'escrowBalance' => 0,
];
$users[] = [
    'uid' => $testUid,
    'email' => 'founder-totp-e2e@test.zinesh.local',
    'name' => 'E2E Founder',
    'passwordHash' => password_hash('TestPass99!', PASSWORD_DEFAULT),
    'isFounder' => true,
    'totpPendingSecret' => $secret,
    'totpEnabled' => false,
    'fiziBalance' => 0,
    'usdtBalance' => 0,
    'escrowBalance' => 0,
];
zinesh_save_users($users);

$blocked = zinesh_totp_reset_send_code($otherEmail, 'OtherPass99!');
assert_totp('Diğer hesap reset kodu engellendi', !$blocked['ok']);

$blockedApply = zinesh_totp_reset_apply($otherEmail, 'OtherPass99!', '123456');
assert_totp('Diğer hesap reset onayı engellendi', !$blockedApply['ok']);

$wrongPass = zinesh_totp_reset_send_code($founderEmail, 'WrongPassword!');
assert_totp('Yanlış şifre ile reset kodu engellendi', !$wrongPass['ok']);

$gateEmpty = zinesh_founder_login_totp_gate($testUid, zinesh_find_user_by_uid($testUid) ?? [], '');
assert_totp('Pending kurulumda QR URI döner', !empty($gateEmpty['totpQrUri']) && !empty($gateEmpty['needsTotpSetup']));

$gateOk = zinesh_founder_login_totp_gate($testUid, zinesh_find_user_by_uid($testUid) ?? [], $code);
assert_totp('İlk kod ile kurulum tamamlanır', !empty($gateOk['ok']));
$after = zinesh_find_user_by_uid($testUid) ?? [];
assert_totp('totpSecret taşındı', trim((string)($after['totpSecret'] ?? '')) !== '');
assert_totp('totpPendingSecret silindi', trim((string)($after['totpPendingSecret'] ?? '')) === '');
assert_totp('totpEnabled true', !empty($after['totpEnabled']));

$users = array_values(array_filter(
    zinesh_load_users(),
    static fn(array $u): bool => !in_array((string)($u['uid'] ?? ''), [$testUid, $otherUid], true)
));
zinesh_save_users($users);

echo "\nSONUÇ: {$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
