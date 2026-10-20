<?php
declare(strict_types=1);

/**
 * Kayıt kapısı regresyonu. Yalnızca geçici bir veri dizini kullanır.
 * Çalıştırma: php api/scripts/e2e-registration-closed-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'zinesh-registration-closed-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/campaign_lib.php';
require_once $apiDir . '/email_lib.php';
require_once $apiDir . '/kyc_lib.php';
require_once $apiDir . '/verification_lib.php';
require_once $apiDir . '/phone_lib.php';
require_once $apiDir . '/founder_lib.php';
require_once $apiDir . '/founder_profile_lib.php';
require_once $apiDir . '/founder_platform_lib.php';
require_once $apiDir . '/totp_lib.php';
require_once $apiDir . '/password_reset_lib.php';
require_once $apiDir . '/totp_reset_lib.php';
require_once $apiDir . '/oauth_lib.php';

$passed = 0;
$failed = 0;

function registration_assert(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
}

/** @return array<string,mixed> */
function registration_auth_request(array $payload): array
{
    global $apiDir;
    $process = proc_open(
        [PHP_BINARY, $apiDir . '/auth_bridge.php'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $apiDir
    );
    if (!is_resource($process)) {
        return ['_error' => 'auth_bridge başlatılamadı'];
    }
    fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded)) {
        return [
            '_error' => 'Geçersiz auth cevabı',
            '_stdout' => $stdout,
            '_stderr' => $stderr,
            '_exit' => $exitCode,
        ];
    }
    return $decoded;
}

function registration_cleanup(string $dir): void
{
    if (!is_dir($dir) || !str_contains(basename($dir), 'zinesh-registration-closed-')) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            registration_cleanup($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

try {
    $existingEmail = 'existing-user@test.zinesh.local';
    $existingPassword = 'ExistingPass123!';
    $existingUid = 'existing-registration-test-user';
    zinesh_save_users([[
        'uid' => $existingUid,
        'name' => 'Existing User',
        'email' => $existingEmail,
        'role' => 'real',
        'ticketNumber' => '12345',
        'trustScore' => 50,
        'fiziBalance' => 0,
        'usdtBalance' => 0,
        'escrowBalance' => 0,
        'walletAddress' => '0x0000000000000000000000000000000000000001',
        'passwordHash' => password_hash($existingPassword, PASSWORD_DEFAULT),
        'emailVerified' => false,
        'googleSub' => 'existing-google-sub',
        'authProviders' => ['google'],
        'campaignsClaimed' => [],
        'campaignStats' => [],
        'createdAt' => date('c'),
    ]]);

    registration_assert('Sunucu kayıt bayrağı kapalı', !zinesh_registration_is_open());
    $beforeCount = count(zinesh_load_users());

    $register = registration_auth_request([
        'action' => 'register',
        'name' => 'Blocked User',
        'email' => 'blocked-user@test.zinesh.local',
        'password' => 'BlockedPass123!',
        'role' => 'real',
    ]);
    if (($register['code'] ?? '') !== 'registration_closed') {
        echo 'DIAGNOSTIC register=' . json_encode($register, JSON_UNESCAPED_UNICODE) . "\n";
    }
    registration_assert('E-posta kayıt API isteği reddedildi', ($register['code'] ?? '') === 'registration_closed');
    registration_assert('Reddedilen e-posta kaydı kullanıcı oluşturmadı', count(zinesh_load_users()) === $beforeCount);

    $newGoogle = zinesh_oauth_google_login_or_register_identity([
        'sub' => 'new-google-sub',
        'email' => 'new-google@test.zinesh.local',
        'name' => 'New Google User',
    ]);
    registration_assert('Yeni Google hesabı reddedildi', empty($newGoogle['ok']) && ($newGoogle['code'] ?? '') === 'registration_closed');
    registration_assert('Reddedilen Google kaydı kullanıcı oluşturmadı', count(zinesh_load_users()) === $beforeCount);

    $login = registration_auth_request([
        'action' => 'login',
        'email' => $existingEmail,
        'password' => $existingPassword,
    ]);
    if (empty($login['ok'])) {
        echo 'DIAGNOSTIC login=' . json_encode($login, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $sessionToken = trim((string)($login['user']['sessionToken'] ?? ''));
    registration_assert('Mevcut kullanıcı e-posta/şifre ile giriş yaptı', !empty($login['ok']) && $sessionToken !== '');

    $session = registration_auth_request([
        'action' => 'session',
        'sessionToken' => $sessionToken,
    ]);
    registration_assert(
        'Mevcut oturum yenilendi',
        !empty($session['ok']) && ($session['user']['uid'] ?? '') === $existingUid
    );

    $googleLogin = zinesh_oauth_google_login_or_register_identity([
        'sub' => 'existing-google-sub',
        'email' => $existingEmail,
        'name' => 'Existing User',
    ]);
    registration_assert(
        'Mevcut Google hesabı giriş yapabildi',
        !empty($googleLogin['ok']) && empty($googleLogin['isNew']) && ($googleLogin['user']['uid'] ?? '') === $existingUid
    );
    registration_assert('Giriş regresyonları kullanıcı sayısını değiştirmedi', count(zinesh_load_users()) === $beforeCount);
} finally {
    registration_cleanup($simDir);
}

echo "ÖZET: PASS={$passed} FAIL={$failed}\n";
exit($failed > 0 ? 1 : 0);
