<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/kyc_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/founder_profile_lib.php';
require_once __DIR__ . '/founder_platform_lib.php';
require_once __DIR__ . '/totp_lib.php';
require_once __DIR__ . '/password_reset_lib.php';
require_once __DIR__ . '/totp_reset_lib.php';
require_once __DIR__ . '/oauth_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'campaign_status') {
    zinesh_json_response(['ok' => true, 'campaign' => zinesh_campaign_public_status()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'rd_reserve_public') {
    $platformStats = null;
    $platformStatsError = null;
    try {
        $platformStats = zinesh_founder_platform_stats();
    } catch (Throwable $e) {
        $platformStatsError = $e->getMessage();
    }
    $payload = ['ok' => true];
    if ($platformStats !== null) {
        $payload['platformStats'] = $platformStats;
    }
    if ($platformStatsError !== null) {
        $payload['platformStatsError'] = $platformStatsError;
    }
    zinesh_json_response($payload);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'oauth_config') {
    zinesh_json_response([
        'ok' => true,
        'google' => zinesh_google_oauth_public_config(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'google_start') {
    $referralCode = strtoupper(trim((string)($_GET['referralCode'] ?? $_GET['ref'] ?? '')));
    $start = zinesh_google_oauth_start_url($referralCode);
    if (!$start['ok'] || empty($start['url'])) {
        zinesh_json_response(['message' => $start['message'] ?? 'Google girişi başlatılamadı.'], 503);
    }
    header('Location: ' . $start['url'], true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'google_callback') {
    $cfg = zinesh_mail_config();
    $siteUrl = rtrim((string)$cfg['site_url'], '/');
    $error = trim((string)($_GET['error'] ?? ''));
    if ($error !== '') {
        header('Location: ' . $siteUrl . '/?google_error=' . urlencode('Google girişi iptal edildi.'), true, 302);
        exit;
    }
    $result = zinesh_google_oauth_handle_callback(
        trim((string)($_GET['code'] ?? '')),
        trim((string)($_GET['state'] ?? ''))
    );
    if (!$result['ok'] || empty($result['redirect'])) {
        header('Location: ' . $siteUrl . '/?google_error=' . urlencode($result['message'] ?? 'Google girişi başarısız.'), true, 302);
        exit;
    }
    header('Location: ' . $result['redirect'], true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'verify_email') {
    $token = trim((string)($_GET['token'] ?? ''));
    $cfg = zinesh_mail_config();
    $siteUrl = rtrim((string)$cfg['site_url'], '/');
    $result = zinesh_email_verify_token($token);

    if ($result['ok']) {
        $grant = $result['grant'] ?? [];
        $amount = (int)($grant['amount'] ?? 0);
        $claimed = !empty($grant['claimed']);
        $query = $claimed ? 'verified=1&reward=' . $amount : 'verified=1';
        header('Location: ' . $siteUrl . '/?' . $query, true, 302);
        exit;
    }

    $reason = (string)($result['reason'] ?? 'error');
    header('Location: ' . $siteUrl . '/?verify_error=' . urlencode($reason), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

$input = zinesh_input();
$action = (string)($input['action'] ?? '');
$limits = zinesh_config()['rate_limits'] ?? [];

if ($action === 'oauth_config') {
    zinesh_json_response([
        'ok' => true,
        'google' => zinesh_google_oauth_public_config(),
    ]);
}

if (
    $action === 'register'
    || $action === 'login'
    || $action === 'forgot_password_send'
    || $action === 'forgot_password_verify'
    || $action === 'forgot_password_reset'
    || $action === 'change_password'
    || $action === 'totp_reset_send_code'
    || $action === 'totp_reset_confirm'
    || $action === 'oauth_google'
    || $action === 'oauth_google_access'
    || $action === 'oauth_exchange'
    || $action === 'oauth_google_totp'
    || $action === 'oauth_confirm_link'
) {
    zinesh_rate_limit('auth', (int)($limits['auth'] ?? 20));
}

$users = zinesh_load_users();

if ($action === 'register') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $name = trim((string)($input['name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $role = (string)($input['role'] ?? 'web3');
    $referralCodeInput = strtoupper(trim((string)($input['referralCode'] ?? '')));

    if ($email === '' || $name === '' || $password === '') {
        zinesh_json_response(['message' => 'Lütfen tüm alanları doldurun.'], 400);
    }
    if (strlen($password) < 8) {
        zinesh_json_response(['message' => 'Şifre en az 8 karakter olmalı.'], 400);
    }
    if (!in_array($role, ['web3', 'real', 'dual'], true)) {
        $role = 'web3';
    }

    foreach ($users as $u) {
        if (isset($u['email']) && strtolower((string)$u['email']) === $email) {
            zinesh_json_response(['message' => 'Bu e-posta adresi zaten kayıtlı.'], 409);
        }
    }

    $uid = bin2hex(random_bytes(16));
    $clientIp = function_exists('zinesh_client_ip') ? zinesh_client_ip() : '';
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $deviceFingerprint = trim((string)($input['deviceFingerprint'] ?? $input['device_fingerprint'] ?? ''));
    $profile = [
        'uid' => $uid,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'ticketNumber' => zinesh_generate_member_ticket(['uid' => $uid, 'role' => $role]),
        'trustScore' => 50,
        'fiziBalance' => 0,
        'usdtBalance' => 0,
        'escrowBalance' => 0,
        'connectedWallets' => [],
        'walletAddress' => '0x' . bin2hex(random_bytes(20)),
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'createdAt' => date('c'),
        'kycStatus' => 'none',
        'referralCode' => strtoupper(substr($uid, 0, 8)),
        'foundingMember' => false,
        'foundingSlotConsumed' => false,
        'emailVerified' => false,
        'ipAddress' => $clientIp,
        'registrationIp' => $clientIp,
        'deviceFingerprint' => $deviceFingerprint,
        'userAgent' => $userAgent,
        'campaignsClaimed' => [],
        'campaignStats' => [
            'completedJobs' => 0,
            'juryDuties' => 0,
            'correctJuryVotes' => 0,
            'foundingReferrals' => 0,
            'referralsVerified' => 0,
        ],
    ];

    if ($referralCodeInput !== '') {
        $refCheck = zinesh_campaign_validate_referral_code($referralCodeInput, $uid);
        if (!$refCheck['ok']) {
            $messages = [
                'invalid_format' => 'Davet kodu 6-12 karakter olmalı (harf ve rakam).',
                'not_found' => 'Bu davet kodu geçerli değil. Kodu kontrol edip tekrar dene.',
                'self_referral' => 'Kendi davet kodunu kullanamazsın.',
                'referral_loop' => 'Referral loop detected',
            ];
            zinesh_json_response([
                'message' => $refCheck['message'] ?? ($messages[$refCheck['reason'] ?? ''] ?? 'Geçersiz davet kodu.'),
            ], 400);
        }
        $profile['pendingReferralCode'] = $referralCodeInput;
    }

    $users[] = $profile;
    zinesh_save_users($users);

    $profile = zinesh_find_user_by_uid($profile['uid']) ?? $profile;
    $emailResult = zinesh_email_prepare_verification($profile['uid'], true);
    $profile = zinesh_find_user_by_uid($profile['uid']) ?? $profile;

    $token = zinesh_create_session($profile['uid']);
    zinesh_audit('register', ['uid' => $profile['uid'], 'email' => $email, 'emailSent' => $emailResult['mailSent'] ?? false]);
    zinesh_json_response([
        'ok' => true,
        'user' => zinesh_email_sanitize_public_user(array_merge($profile, [
            'sessionToken' => $token,
            'campaign' => zinesh_campaign_user_progress($profile),
        ])),
        'emailVerification' => [
            'required' => true,
            'sent' => $emailResult['mailSent'] ?? false,
            'signupRewardAmount' => (float)zinesh_campaign_rewards_map()['founding_signup'],
        ],
        'campaign' => zinesh_campaign_public_status(),
    ]);
}

if ($action === 'oauth_google') {
    $idToken = trim((string)($input['idToken'] ?? $input['credential'] ?? ''));
    $referralCodeInput = strtoupper(trim((string)($input['referralCode'] ?? '')));
    $totpCode = trim((string)($input['totpCode'] ?? ''));
    if ($idToken === '') {
        zinesh_json_response(['message' => 'Google oturum bilgisi eksik.'], 400);
    }
    $oauth = zinesh_google_oauth_config();
    if (!$oauth['enabled']) {
        zinesh_json_response(['message' => 'Google ile giriş şu an kapalı.'], 503);
    }
    $result = zinesh_oauth_google_login_or_register($idToken, $referralCodeInput, $totpCode);
    if (!$result['ok']) {
        if (!empty($result['needsPasswordLink'])) {
            zinesh_json_response([
                'ok' => false,
                'needsPasswordLink' => true,
                'oauthLinkState' => (string)($result['oauthLinkState'] ?? ''),
                'email' => (string)($result['email'] ?? ''),
                'message' => $result['message'] ?? 'Google hesabını bağlamak için şifrenizi girin.',
            ], 403);
        }
        if (!empty($result['needsTotp']) || !empty($result['needsTotpSetup'])) {
            zinesh_json_response([
                'ok' => false,
                'needsTotp' => !empty($result['needsTotp']),
                'needsTotpSetup' => !empty($result['needsTotpSetup']),
                'totpQrUri' => $result['totpQrUri'] ?? null,
                'message' => $result['message'] ?? '2FA doğrulaması gerekli.',
            ], 401);
        }
        zinesh_json_response(['message' => $result['message'] ?? 'Google ile giriş başarısız.'], 401);
    }
    zinesh_json_response([
        'ok' => true,
        'isNew' => !empty($result['isNew']),
        'user' => $result['user'],
        'campaign' => zinesh_campaign_public_status(),
    ]);
}

if ($action === 'oauth_google_access') {
    $accessToken = trim((string)($input['accessToken'] ?? ''));
    $referralCodeInput = strtoupper(trim((string)($input['referralCode'] ?? '')));
    $totpCode = trim((string)($input['totpCode'] ?? ''));
    if ($accessToken === '') {
        zinesh_json_response(['message' => 'Google oturum bilgisi eksik.'], 400);
    }
    $oauth = zinesh_google_oauth_config();
    if (!$oauth['enabled']) {
        zinesh_json_response(['message' => 'Google ile giriş şu an kapalı.'], 503);
    }
    $identity = zinesh_google_fetch_userinfo($accessToken);
    if ($identity === null) {
        zinesh_json_response(['message' => 'Google oturumu doğrulanamadı. Tekrar dene.'], 401);
    }
    $result = zinesh_oauth_google_login_or_register_identity($identity, $referralCodeInput, $totpCode);
    if (!$result['ok']) {
        if (!empty($result['needsPasswordLink'])) {
            zinesh_json_response([
                'ok' => false,
                'needsPasswordLink' => true,
                'oauthLinkState' => (string)($result['oauthLinkState'] ?? ''),
                'email' => (string)($result['email'] ?? ''),
                'message' => $result['message'] ?? 'Google hesabını bağlamak için şifrenizi girin.',
            ], 403);
        }
        if (!empty($result['needsTotp']) || !empty($result['needsTotpSetup'])) {
            zinesh_json_response([
                'ok' => false,
                'needsTotp' => !empty($result['needsTotp']),
                'needsTotpSetup' => !empty($result['needsTotpSetup']),
                'totpQrUri' => $result['totpQrUri'] ?? null,
                'message' => $result['message'] ?? '2FA doğrulaması gerekli.',
            ], 401);
        }
        zinesh_json_response(['message' => $result['message'] ?? 'Google ile giriş başarısız.'], 401);
    }
    zinesh_json_response([
        'ok' => true,
        'isNew' => !empty($result['isNew']),
        'user' => $result['user'],
        'campaign' => zinesh_campaign_public_status(),
    ]);
}

if ($action === 'oauth_exchange') {
    $code = trim((string)($input['code'] ?? ''));
    if ($code === '' && !empty($_COOKIE[zinesh_oauth_exchange_cookie_name()])) {
        $code = trim((string)$_COOKIE[zinesh_oauth_exchange_cookie_name()]);
    }
    if ($code === '') {
        zinesh_json_response(['message' => 'Oturum kodu eksik.'], 400);
    }
    $user = zinesh_oauth_consume_exchange_code($code);
    zinesh_oauth_clear_exchange_cookie();
    if ($user === null) {
        zinesh_json_response(['message' => 'Oturum kodu geçersiz veya süresi dolmuş.'], 401);
    }
    zinesh_json_response([
        'ok' => true,
        'user' => $user,
        'campaign' => zinesh_campaign_public_status(),
    ]);
}

if ($action === 'oauth_confirm_link') {
    $linkState = trim((string)($input['oauthLinkState'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($linkState === '' || $password === '') {
        zinesh_json_response(['message' => 'Şifre ve bağlantı bilgisi gerekli.'], 400);
    }
    $result = zinesh_oauth_confirm_google_link($linkState, $password);
    if (!$result['ok']) {
        if (!empty($result['needsTotp']) || !empty($result['needsTotpSetup'])) {
            zinesh_json_response([
                'ok' => false,
                'needsTotp' => !empty($result['needsTotp']),
                'needsTotpSetup' => !empty($result['needsTotpSetup']),
                'totpQrUri' => $result['totpQrUri'] ?? null,
                'message' => $result['message'] ?? '2FA doğrulaması gerekli.',
            ], 401);
        }
        zinesh_json_response(['message' => $result['message'] ?? 'Google bağlantısı başarısız.'], 401);
    }
    zinesh_json_response([
        'ok' => true,
        'isNew' => !empty($result['isNew']),
        'user' => $result['user'],
        'campaign' => zinesh_campaign_public_status(),
    ]);
}

if ($action === 'oauth_google_totp') {
    $oauthState = trim((string)($input['oauthState'] ?? ''));
    $totpCode = trim((string)($input['totpCode'] ?? ''));
    if ($oauthState === '' || $totpCode === '') {
        zinesh_json_response(['message' => '2FA kodu gerekli.'], 400);
    }
    $result = zinesh_google_oauth_complete_totp($oauthState, $totpCode);
    if (!$result['ok']) {
        if (!empty($result['needsTotp']) || !empty($result['needsTotpSetup'])) {
            zinesh_json_response([
                'ok' => false,
                'needsTotp' => !empty($result['needsTotp']),
                'needsTotpSetup' => !empty($result['needsTotpSetup']),
                'totpQrUri' => $result['totpQrUri'] ?? null,
                'message' => $result['message'] ?? '2FA doğrulaması gerekli.',
            ], 401);
        }
        zinesh_json_response(['message' => $result['message'] ?? 'Google ile giriş başarısız.'], 401);
    }
    zinesh_json_response([
        'ok' => true,
        'isNew' => !empty($result['isNew']),
        'user' => $result['user'],
        'campaign' => zinesh_campaign_public_status(),
    ]);
}

if ($action === 'login') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $loginCode = trim((string)($input['loginVerificationCode'] ?? $input['loginCode'] ?? ''));

    if ($email === '') {
        zinesh_json_response(['message' => 'E-posta gerekli.'], 400);
    }

    if (!zinesh_login_lock_acquire($email)) {
        zinesh_json_response(['message' => 'Bu hesap için başka bir giriş işlemi devam ediyor.'], 429);
    }

    try {
        if (zinesh_login_requires_verification($email)) {
            if ($loginCode === '') {
                zinesh_json_response([
                    'message' => 'E-posta doğrulaması gerekli.',
                    'login_verification_required' => true,
                ], 403);
            }
            $codeCheck = zinesh_login_verify_code($email, $loginCode);
            if (!$codeCheck['ok']) {
                zinesh_json_response([
                    'message' => $codeCheck['message'] ?? 'Doğrulama kodu hatalı.',
                    'login_verification_required' => true,
                ], 401);
            }
        }

        foreach ($users as $u) {
            if (
                isset($u['email'], $u['passwordHash']) &&
                strtolower((string)$u['email']) === $email &&
                password_verify($password, (string)$u['passwordHash'])
            ) {
                $uid = (string)$u['uid'];
                zinesh_login_clear_failures($email);
                zinesh_ensure_wallet_fields($u);
                zinesh_campaign_ensure_user_fields($u);
                zinesh_email_sync_legacy_verified($uid);
                $u = zinesh_persist_user_ticket_if_needed($uid) ?? $u;
                $u = zinesh_campaign_persist_user_fields($uid) ?? zinesh_find_user_by_uid($uid) ?? $u;

                if (empty($u['campaignsClaimed']['founding_signup']) && !empty($u['emailVerified'])) {
                    zinesh_campaign_try_grant_signup_reward($uid);
                    $u = zinesh_find_user_by_uid($uid) ?? $u;
                }

                $u = zinesh_find_user_by_uid($uid) ?? $u;
                $totpCode = trim((string)($input['totpCode'] ?? ''));
                $totpGate = zinesh_founder_login_totp_gate($uid, $u, $totpCode);
                if (!$totpGate['ok']) {
                    zinesh_json_response([
                        'ok' => false,
                        'needsTotp' => !empty($totpGate['needsTotp']),
                        'needsTotpSetup' => !empty($totpGate['needsTotpSetup']),
                        'totpQrUri' => $totpGate['totpQrUri'] ?? null,
                        'message' => $totpGate['message'] ?? '2FA doğrulaması gerekli.',
                    ], 401);
                }
                $u = zinesh_find_user_by_uid($uid) ?? $u;

                $token = zinesh_ensure_session($uid, null);
                zinesh_audit('login', ['uid' => $uid]);
                if (zinesh_is_founder($u)) {
                    $u = zinesh_sync_founder_treasury_wallets($uid);
                }
                if (function_exists('zinesh_emit_session_cookie')) {
                    zinesh_emit_session_cookie($token);
                }
                zinesh_json_response([
                    'ok' => true,
                    'user' => zinesh_email_sanitize_public_user(array_merge($u, [
                        'sessionToken' => $token,
                        'campaign' => zinesh_campaign_user_progress($u),
                        'isFounder' => zinesh_is_founder($u),
                    ])),
                ]);
            }
        }

        $fail = zinesh_login_record_failure($email);
        zinesh_audit('login_failed', ['email' => $email]);
        if (!empty($fail['emailCooldown'])) {
            zinesh_json_response([
                'message' => 'Doğrulama kodu zaten gönderildi. Lütfen e-postanızı kontrol edin.',
            ], 429);
        }
        if (!empty($fail['requiresVerification'])) {
            zinesh_json_response([
                'message' => $fail['message'] ?? 'E-posta doğrulaması gerekli.',
                'login_verification_required' => true,
            ], 401);
        }
        zinesh_json_response(['message' => 'E-posta veya şifre hatalı.'], 401);
    } finally {
        zinesh_login_lock_release($email);
    }
}

if ($action === 'logout') {
    $token = trim((string)($input['sessionToken'] ?? ''));
    zinesh_revoke_session($token);
    zinesh_json_response(['ok' => true]);
}

if ($action === 'forgot_password_send') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        zinesh_json_response(['message' => 'Geçerli bir e-posta adresi gir.'], 400);
    }
    $result = zinesh_password_reset_send_code($email);
    if (!$result['ok']) {
        $status = isset($result['retryAfter']) ? 429 : 400;
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
            'retryAfter' => $result['retryAfter'] ?? null,
        ], $status);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'mailSent' => $result['mailSent'] ?? true,
        'retryAfter' => $result['retryAfter'] ?? 60,
    ]);
}

if ($action === 'forgot_password_verify') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $code = trim((string)($input['code'] ?? ''));
    if ($email === '' || $code === '') {
        zinesh_json_response(['message' => 'E-posta ve kod gerekli.'], 400);
    }
    $verify = zinesh_password_reset_verify_code($email, $code, true);
    if (!$verify['ok']) {
        zinesh_json_response([
            'ok' => false,
            'message' => $verify['message'] ?? 'Doğrulama başarısız.',
            'reason' => $verify['reason'] ?? 'error',
        ], 400);
    }
    zinesh_json_response(['ok' => true, 'message' => 'Kod doğrulandı. Yeni şifreni belirleyebilirsin.']);
}

if ($action === 'forgot_password_reset') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $code = trim((string)($input['code'] ?? ''));
    $newPassword = (string)($input['newPassword'] ?? $input['password'] ?? '');
    $confirm = (string)($input['confirmPassword'] ?? $input['newPasswordConfirm'] ?? '');
    if ($email === '' || $code === '' || $newPassword === '') {
        zinesh_json_response(['message' => 'E-posta, kod ve yeni şifre gerekli.'], 400);
    }
    if ($confirm !== '' && $newPassword !== $confirm) {
        zinesh_json_response(['message' => 'Yeni şifreler eşleşmiyor.'], 400);
    }
    $result = zinesh_password_reset_apply($email, $code, $newPassword);
    if (!$result['ok']) {
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
            'reason' => $result['reason'] ?? 'error',
        ], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'requiresLogin' => true,
    ]);
}

if ($action === 'change_password') {
    $user = zinesh_require_auth($input);
    $current = (string)($input['currentPassword'] ?? $input['password'] ?? '');
    $newPassword = (string)($input['newPassword'] ?? '');
    $confirm = (string)($input['confirmPassword'] ?? $input['newPasswordConfirm'] ?? '');
    if ($current === '' || $newPassword === '') {
        zinesh_json_response(['message' => 'Mevcut ve yeni şifre gerekli.'], 400);
    }
    if ($confirm !== '' && $newPassword !== $confirm) {
        zinesh_json_response(['message' => 'Yeni şifreler eşleşmiyor.'], 400);
    }
    $result = zinesh_change_password((string)$user['uid'], $current, $newPassword);
    if (!$result['ok']) {
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
            'reason' => $result['reason'] ?? 'error',
        ], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'requiresLogin' => !empty($result['requiresLogin']),
    ]);
}

if ($action === 'totp_reset_send_code') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    if ($email === '' || $password === '') {
        zinesh_json_response(['message' => 'E-posta ve mevcut şifre gerekli.'], 400);
    }
    $result = zinesh_totp_reset_send_code($email, $password);
    if (!$result['ok']) {
        $status = isset($result['retryAfter']) ? 429 : 403;
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
            'retryAfter' => $result['retryAfter'] ?? null,
        ], $status);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'mailSent' => $result['mailSent'] ?? true,
        'retryAfter' => $result['retryAfter'] ?? 60,
    ]);
}

if ($action === 'totp_reset_confirm') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $code = trim((string)($input['code'] ?? ''));
    if ($email === '' || $password === '' || $code === '') {
        zinesh_json_response(['message' => 'E-posta, şifre ve doğrulama kodu gerekli.'], 400);
    }
    $result = zinesh_totp_reset_apply($email, $password, $code);
    if (!$result['ok']) {
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
        ], 403);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'needsTotpSetup' => !empty($result['needsTotpSetup']),
        'totpQrUri' => $result['totpQrUri'] ?? null,
    ]);
}

if ($action === 'resend_verification') {
    zinesh_rate_limit('email_resend', (int)($limits['auth'] ?? 10));
    $user = zinesh_require_auth($input);
    $result = zinesh_email_resend_verification((string)$user['uid']);
    if (!$result['sent']) {
        $status = isset($result['retryAfter']) ? 429 : 400;
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
            'retryAfter' => $result['retryAfter'] ?? null,
        ], $status);
    }
    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token === '' || !zinesh_uid_from_token($token)) {
        $token = zinesh_create_session((string)$user['uid']);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'mailSent' => $result['mailSent'] ?? false,
        'retryAfter' => $result['retryAfter'] ?? 60,
        'user' => zinesh_email_sanitize_public_user(array_merge($user, [
            'sessionToken' => $token,
            'campaign' => zinesh_campaign_user_progress($user),
        ])),
    ]);
}

if ($action === 'send_verification_code') {
    zinesh_rate_limit('email_resend', (int)($limits['auth'] ?? 10));
    $user = zinesh_require_auth($input);
    $result = zinesh_email_resend_verification((string)$user['uid']);
    if (!$result['sent']) {
        $status = isset($result['retryAfter']) ? 429 : 400;
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
            'retryAfter' => $result['retryAfter'] ?? null,
        ], $status);
    }
    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token === '' || !zinesh_uid_from_token($token)) {
        $token = zinesh_create_session((string)$user['uid']);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'mailSent' => $result['mailSent'] ?? false,
        'retryAfter' => $result['retryAfter'] ?? 60,
        'user' => zinesh_email_sanitize_public_user(array_merge($user, [
            'sessionToken' => $token,
            'campaign' => zinesh_campaign_user_progress($user),
        ])),
    ]);
}

if ($action === 'verification_link') {
    zinesh_rate_limit('email_resend', (int)($limits['auth'] ?? 15));
    $user = zinesh_require_auth($input);
    if (!empty($user['emailVerified'])) {
        zinesh_json_response(['ok' => true, 'message' => 'E-posta zaten doğrulanmış.', 'verifyLink' => '']);
    }
    $result = zinesh_email_create_verification_link((string)$user['uid']);
    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token === '' || !zinesh_uid_from_token($token)) {
        $token = zinesh_create_session((string)$user['uid']);
    }
    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'verifyLink' => $result['verifyLink'] ?? '',
        'mailSent' => $result['mailSent'] ?? false,
        'user' => zinesh_email_sanitize_public_user(array_merge($user, [
            'sessionToken' => $token,
            'campaign' => zinesh_campaign_user_progress($user),
        ])),
    ]);
}

if ($action === 'verify_email_code') {
    zinesh_rate_limit('email_verify', (int)($limits['auth'] ?? 20));
    $code = trim((string)($input['code'] ?? ''));
    $user = zinesh_resolve_user_from_auth_input($input, ['allowVerifyCode' => true]);
    if (!$user) {
        zinesh_json_response(['message' => 'Hesap bulunamadı. Lütfen çıkış yapıp tekrar giriş yap.'], 401);
    }
    $result = zinesh_email_verify_code((string)$user['uid'], $code);

    if (!$result['ok']) {
        $messages = [
            'code_expired' => 'Kodun süresi dolmuş. Yeni kod gönder.',
            'code_invalid' => 'Doğrulama kodu hatalı.',
            'invalid_code' => 'Geçerli 6 haneli kod gir.',
        ];
        $reason = (string)($result['reason'] ?? 'error');
        zinesh_json_response([
            'ok' => false,
            'reason' => $reason,
            'message' => $messages[$reason] ?? 'Doğrulama başarısız.',
        ], 400);
    }

    $user = $result['user'];
    $grant = $result['grant'] ?? [];
    $sessionToken = trim((string)($input['sessionToken'] ?? ''));
    $uid = (string)($user['uid'] ?? '');
    $outUser = $user;

    if ($sessionToken !== '' && zinesh_uid_from_token($sessionToken) === $uid) {
        $outUser = array_merge($user, ['sessionToken' => $sessionToken]);
    } else {
        $outUser = array_merge($user, ['sessionToken' => zinesh_create_session($uid)]);
    }

    zinesh_json_response([
        'ok' => true,
        'message' => !empty($grant['claimed'])
            ? ((int)$grant['amount'] . ' FİZİ kurucu kayıt ödülün hesabına yazıldı.')
            : 'E-posta adresin doğrulandı.',
        'user' => zinesh_email_sanitize_public_user(array_merge($outUser, [
            'campaign' => zinesh_campaign_user_progress($user),
        ])),
        'grant' => $grant,
    ]);
}

if ($action === 'verify_email') {
    zinesh_rate_limit('email_verify', (int)($limits['auth'] ?? 20));
    $plainToken = trim((string)($input['token'] ?? ''));
    $result = zinesh_email_verify_token($plainToken);

    if (!$result['ok']) {
        $messages = [
            'token_expired' => 'Doğrulama bağlantısının süresi dolmuş. Konsoldan yeni e-posta iste.',
            'token_not_found' => 'Doğrulama bağlantısı geçersiz veya kullanılmış.',
            'invalid_token' => 'Geçersiz doğrulama bağlantısı.',
        ];
        $reason = (string)($result['reason'] ?? 'error');
        zinesh_json_response([
            'ok' => false,
            'reason' => $reason,
            'message' => $messages[$reason] ?? 'E-posta doğrulaması başarısız.',
        ], 400);
    }

    $user = $result['user'];
    $grant = $result['grant'] ?? [];
    $sessionToken = trim((string)($input['sessionToken'] ?? ''));
    $uid = (string)($user['uid'] ?? '');
    $outUser = $user;

    if ($sessionToken !== '' && zinesh_uid_from_token($sessionToken) === $uid) {
        $outUser = array_merge($user, ['sessionToken' => $sessionToken]);
    } else {
        $outUser = array_merge($user, ['sessionToken' => zinesh_create_session($uid)]);
    }

    zinesh_json_response([
        'ok' => true,
        'message' => !empty($grant['claimed'])
            ? ((int)$grant['amount'] . ' FİZİ kurucu kayıt ödülün hesabına yazıldı.')
            : 'E-posta adresin doğrulandı.',
        'user' => zinesh_email_sanitize_public_user(array_merge($outUser, [
            'campaign' => zinesh_campaign_user_progress($user),
        ])),
        'grant' => $grant,
    ]);
}

if ($action === 'validate_referral') {
    $code = strtoupper(trim((string)($input['referralCode'] ?? $input['code'] ?? '')));
    $excludeUid = null;
    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token !== '') {
        $excludeUid = zinesh_uid_from_token($token) ?: null;
    }
    $result = zinesh_campaign_validate_referral_code($code, $excludeUid);
    if (!$result['ok']) {
        $messages = [
            'empty' => 'Davet kodu gir.',
            'invalid_format' => 'Davet kodu 6-12 karakter olmalı (harf ve rakam).',
            'not_found' => 'Bu davet kodu geçerli değil.',
            'self_referral' => 'Kendi davet kodunu kullanamazsın.',
        ];
        zinesh_json_response([
            'ok' => false,
            'message' => $messages[$result['reason'] ?? ''] ?? 'Geçersiz davet kodu.',
        ], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'referrerName' => $result['referrerName'],
        'message' => ($result['referrerName'] ?? 'Kurucu üye') . ' davet kodu geçerli.',
    ]);
}

if ($action === 'session') {
    $user = zinesh_resolve_user_for_session_refresh($input);
    if (!$user) {
        zinesh_json_response(['message' => 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.', 'code' => 'session_expired'], 401);
    }
    $uid = (string)$user['uid'];
    zinesh_email_sync_legacy_verified($uid);
    $user = zinesh_persist_user_ticket_if_needed($uid) ?? $user;
    $user = zinesh_campaign_persist_user_fields($uid) ?? $user;

    if (empty($user['campaignsClaimed']['founding_signup']) && !empty($user['emailVerified'])) {
        zinesh_campaign_try_grant_signup_reward($uid);
        $user = zinesh_find_user_by_uid($uid) ?? $user;
    }

    $isFounder = zinesh_is_founder($user);
    if ($isFounder) {
        $user = zinesh_sync_founder_treasury_wallets($uid);
    }

    $sessionToken = trim((string)($input['sessionToken'] ?? ''));
    // Mevcut geçerli oturumu koru; rotasyon yok (mobilde token değişimi oturumu düşürüyordu).
    $sessionToken = zinesh_ensure_session($uid, $sessionToken !== '' ? $sessionToken : null);
    if (function_exists('zinesh_emit_session_cookie')) {
        zinesh_emit_session_cookie($sessionToken);
    }

    zinesh_json_response([
        'ok' => true,
        'user' => zinesh_email_sanitize_public_user(array_merge($user, [
            'sessionToken' => $sessionToken,
            'campaign' => zinesh_campaign_user_progress($user),
            'isFounder' => $isFounder,
        ])),
        'isFounder' => $isFounder,
    ]);
}

if ($action === 'submit_kyc') {
    zinesh_rate_limit('kyc_submit', (int)($limits['auth'] ?? 10));
    $user = zinesh_require_auth($input);
    $result = zinesh_kyc_submit((string)$user['uid'], $input);
    if (!$result['ok']) {
        zinesh_json_response([
            'ok' => false,
            'message' => $result['message'],
            'reason' => $result['reason'] ?? 'error',
        ], 400);
    }

    $user = $result['user'] ?? zinesh_find_user_by_uid((string)$user['uid']);
    $grant = $result['grant'] ?? [];
    $sessionToken = trim((string)($input['sessionToken'] ?? ''));
    $uid = (string)($user['uid'] ?? '');
    if ($sessionToken === '' || zinesh_uid_from_token($sessionToken) !== $uid) {
        $sessionToken = zinesh_create_session($uid);
    }

    zinesh_json_response([
        'ok' => true,
        'message' => $result['message'],
        'user' => zinesh_email_sanitize_public_user(array_merge($user, [
            'sessionToken' => $sessionToken,
            'campaign' => zinesh_campaign_user_progress($user),
        ])),
        'grant' => $grant,
    ]);
}

if ($action === 'deposit_addresses') {
    zinesh_json_response(['ok' => true, 'depositAddresses' => zinesh_deposit_addresses()]);
}

if ($action === 'campaign_status') {
    zinesh_json_response(['ok' => true, 'campaign' => zinesh_campaign_public_status()]);
}

if ($action === 'rd_reserve_public') {
    zinesh_json_response([
        'ok' => true,
        'platformStats' => zinesh_founder_platform_stats(),
    ]);
}

if ($action === 'status') {
    require_once __DIR__ . '/withdraw_executor.php';
    $campaign = zinesh_campaign_public_status();
    $response = [
        'ok' => true,
        'time' => date('c'),
        'tlMode' => function_exists('zinesh_tl_mode_enabled') && zinesh_tl_mode_enabled(),
        'foundersRemaining' => (int)($campaign['slotsRemaining'] ?? 0),
    ];

    $token = trim((string)($input['sessionToken'] ?? ''));
    $uid = $token !== '' ? zinesh_uid_from_token($token) : null;
    if ($uid) {
        $founderUser = zinesh_find_user_by_uid($uid);
        if ($founderUser && zinesh_is_founder($founderUser)) {
            $treasury = zinesh_treasury_panel_stats();
            $response['status_private'] = [
                'pending_withdrawals' => count(array_filter(
                    zinesh_json_read('withdrawals.json'),
                    static fn($w) => in_array($w['status'] ?? '', ['pending', 'pending_manual_review'], true)
                )),
                'userLiabilities' => (float)($treasury['kullaniciBorcu'] ?? 0),
                'availableReserve' => (float)($treasury['kullanilabilirRezerv'] ?? 0),
            ];
        }
    }

    zinesh_json_response($response);
}

zinesh_json_response(['message' => 'Geçersiz işlem.'], 400);
