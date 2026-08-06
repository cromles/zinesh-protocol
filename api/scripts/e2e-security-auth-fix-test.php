<?php
declare(strict_types=1);

/**
 * Güvenlik düzeltmeleri E2E (CLI — yerel veya sunucuda).
 * php api/scripts/e2e-security-auth-fix-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/email_lib.php';

const TEST_EMAIL = 'sec-auth-fix@test.zinesh.local';
const TEST_PASS = 'SecFixTest123!';

$passed = 0;
$failed = 0;

function assert_test(string $label, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
    }
}

function ensure_test_user(): array {
    $users = zinesh_load_users();
    foreach ($users as $u) {
        if (strtolower((string)($u['email'] ?? '')) === TEST_EMAIL) {
            return $u;
        }
    }
    $uid = bin2hex(random_bytes(16));
    $profile = [
        'uid' => $uid,
        'name' => 'Sec Auth Fix',
        'email' => TEST_EMAIL,
        'role' => 'web3',
        'passwordHash' => password_hash(TEST_PASS, PASSWORD_DEFAULT),
        'emailVerified' => false,
        'fiziBalance' => 0,
        'usdtBalance' => 0,
        'escrowBalance' => 0,
        'createdAt' => date('c'),
    ];
    $users[] = $profile;
    zinesh_save_users($users);
    return $profile;
}

function cleanup_test_user(): void {
    $users = array_values(array_filter(
        zinesh_load_users(),
        static fn(array $u): bool => strtolower((string)($u['email'] ?? '')) !== TEST_EMAIL
    ));
    zinesh_save_users($users);
}

echo "=== ADIM 1: E-posta-only auth ===\n";
$user = ensure_test_user();
$email = TEST_EMAIL;

$endpoints = [
    'session' => ['action' => 'session', 'email' => $email],
    'resend_verification' => ['action' => 'resend_verification', 'email' => $email],
    'verification_link' => ['action' => 'verification_link', 'email' => $email],
    'submit_kyc' => ['action' => 'submit_kyc', 'email' => $email],
];

foreach ($endpoints as $name => $payload) {
    $resolved = zinesh_resolve_user_from_auth_input($payload);
    assert_test("{$name}: e-posta-only reddedilir", $resolved === null);
}

$withPassword = zinesh_resolve_user_from_auth_input(['email' => $email, 'password' => TEST_PASS]);
assert_test('session: şifre ile kullanıcı bulunur', $withPassword !== null && ($withPassword['uid'] ?? '') === ($user['uid'] ?? ''));

$token = zinesh_create_session((string)$user['uid']);
$withToken = zinesh_resolve_user_from_auth_input(['sessionToken' => $token]);
assert_test('session: token ile kullanıcı bulunur', $withToken !== null);

$issued = zinesh_email_issue_verification_code();
zinesh_update_user((string)$user['uid'], static function (array &$u) use ($issued) {
    $u['emailVerifyCode'] = $issued['hash'];
    $u['emailVerifyExpires'] = $issued['expires'];
});
$withCode = zinesh_resolve_user_from_auth_input(
    ['email' => $email, 'code' => $issued['plain']],
    ['allowVerifyCode' => true]
);
assert_test('verify_email_code: geçerli kod ile kullanıcı bulunur', $withCode !== null);

echo "\n=== Özet ADIM 1 ===\n";
echo "PASS: {$passed}  FAIL: {$failed}\n";

cleanup_test_user();
exit($failed > 0 ? 1 : 0);
