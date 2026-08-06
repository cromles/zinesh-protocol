<?php
declare(strict_types=1);

/** Google oturum — Firebase veya doğrudan Google OAuth (redirect) ile. */

require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/wallet_lib.php';

function zinesh_google_oauth_config(): array
{
    $cfg = zinesh_config()['google_oauth'] ?? [];
    $clientId = trim((string)($cfg['client_id'] ?? ''));
    $clientSecret = trim((string)($cfg['client_secret'] ?? ''));
    return [
        'enabled' => !empty($cfg['enabled']),
        'firebase_web_api_key' => trim((string)($cfg['firebase_web_api_key'] ?? '')),
        'client_id' => $clientId !== '' ? $clientId : trim((string)($cfg['client_id'] ?? '')),
        'client_secret' => $clientSecret,
        'redirect_enabled' => $clientId !== '' && $clientSecret !== '',
    ];
}

function zinesh_google_oauth_public_config(): array
{
    $oauth = zinesh_google_oauth_config();
    // Secret olmadan veya geçersiz secret ile redirect bozulur; GSI/popup güvenli varsayılan.
    // Redirect yalnızca açıkça zorlanırsa (google_oauth.force_redirect=true).
    $forceRedirect = !empty(zinesh_config()['google_oauth']['force_redirect']);
    $mode = ($forceRedirect && $oauth['redirect_enabled']) ? 'redirect' : 'popup';
    return [
        'enabled' => $oauth['enabled'],
        'mode' => $mode,
        'clientId' => $oauth['client_id'] !== '' ? $oauth['client_id'] : null,
    ];
}

function zinesh_google_oauth_redirect_uri(): string
{
    $cfg = zinesh_mail_config();
    $base = rtrim((string)($cfg['site_url'] ?? 'https://www.zinesh.com'), '/');
    return $base . '/api/google_auth.php?action=callback';
}

function zinesh_oauth_states_path(): string
{
    return 'oauth_states.json';
}

function zinesh_oauth_prune_states(array $states): array
{
    $cutoff = time() - 600;
    foreach ($states as $key => $row) {
        if (!is_array($row) || (int)($row['createdAt'] ?? 0) < $cutoff) {
            unset($states[$key]);
        }
    }
    return $states;
}

function zinesh_oauth_save_state(string $state, array $payload): void
{
    zinesh_json_atomic(zinesh_oauth_states_path(), static function (array &$states) use ($state, $payload) {
        $states = zinesh_oauth_prune_states($states);
        $states[$state] = array_merge($payload, ['createdAt' => time()]);
    });
}

function zinesh_oauth_take_state(string $state): ?array
{
    $found = null;
    zinesh_json_atomic(zinesh_oauth_states_path(), static function (array &$states) use ($state, &$found) {
        $states = zinesh_oauth_prune_states($states);
        if (!isset($states[$state]) || !is_array($states[$state])) {
            return;
        }
        $found = $states[$state];
        unset($states[$state]);
    });
    return $found;
}

function zinesh_oauth_create_exchange_code(array $user): string
{
    $code = bin2hex(random_bytes(24));
    zinesh_oauth_save_state('ex_' . $code, [
        'type' => 'exchange',
        'user' => $user,
    ]);
    return $code;
}

function zinesh_oauth_consume_exchange_code(string $code): ?array
{
    $row = zinesh_oauth_take_state('ex_' . $code);
    if ($row === null || ($row['type'] ?? '') !== 'exchange' || !is_array($row['user'] ?? null)) {
        return null;
    }
    return $row['user'];
}

function zinesh_oauth_exchange_cookie_name(): string
{
    return 'zinesh_oauth_ex';
}

function zinesh_oauth_cookie_domain(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === 'zinesh.com' || str_ends_with($host, '.zinesh.com')) {
        return '.zinesh.com';
    }
    return '';
}

function zinesh_oauth_set_exchange_cookie(string $code): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $domain = zinesh_oauth_cookie_domain();
    $opts = [
        'expires' => time() + 120,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $opts['domain'] = $domain;
    }
    setcookie(zinesh_oauth_exchange_cookie_name(), $code, $opts);
}

function zinesh_oauth_clear_exchange_cookie(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $domain = zinesh_oauth_cookie_domain();
    $opts = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $opts['domain'] = $domain;
    }
    setcookie(zinesh_oauth_exchange_cookie_name(), '', $opts);
}

/**
 * @return array{ok:bool,redirect?:string,message?:string}
 */
function zinesh_oauth_redirect_after_login(array $result): array
{
    $siteUrl = rtrim((string)zinesh_mail_config()['site_url'], '/');
    if (!$result['ok']) {
        if (!empty($result['needsPasswordLink']) && !empty($result['oauthLinkState'])) {
            $query = http_build_query([
                'oauth_link' => (string)$result['oauthLinkState'],
                'email' => (string)($result['email'] ?? ''),
            ]);
            return ['ok' => true, 'redirect' => $siteUrl . '/?' . $query];
        }
        return [
            'ok' => true,
            'redirect' => $siteUrl . '/?google_error=' . urlencode((string)($result['message'] ?? 'Giriş başarısız.')),
        ];
    }
    $exchange = zinesh_oauth_create_exchange_code($result['user']);
    zinesh_oauth_set_exchange_cookie($exchange);
    return ['ok' => true, 'redirect' => $siteUrl . '/?oauth_pending=1'];
}

/**
 * @return array{ok:bool,message?:string,user?:array,isNew?:bool}
 */
function zinesh_oauth_confirm_google_link(string $linkState, string $password): array
{
    $pending = zinesh_oauth_take_state('lnk_' . $linkState);
    if ($pending === null || ($pending['type'] ?? '') !== 'link_pending' || !is_array($pending['identity'] ?? null)) {
        return ['ok' => false, 'message' => 'Bağlantı süresi doldu. Tekrar Google ile giriş yap.'];
    }
    if ($password === '') {
        zinesh_oauth_save_state('lnk_' . $linkState, $pending);
        return ['ok' => false, 'message' => 'Şifre gerekli.'];
    }
    return zinesh_oauth_google_login_or_register_identity(
        $pending['identity'],
        (string)($pending['referralCode'] ?? ''),
        '',
        $password
    );
}

function zinesh_verify_google_id_token_firebase(string $idToken): ?array
{
    $oauth = zinesh_google_oauth_config();
    if (!$oauth['enabled'] || $oauth['firebase_web_api_key'] === '' || $idToken === '') {
        return null;
    }

    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup?key='
        . urlencode($oauth['firebase_web_api_key']);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode(['idToken' => $idToken]),
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['users'][0]) || !is_array($data['users'][0])) {
        return null;
    }

    $user = $data['users'][0];
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    if (empty($user['emailVerified']) && ($user['emailVerified'] ?? '') !== 'true') {
        return null;
    }

    return [
        'sub' => trim((string)($user['localId'] ?? '')),
        'email' => $email,
        'name' => trim((string)($user['displayName'] ?? '')),
        'photoUrl' => trim((string)($user['photoUrl'] ?? '')),
    ];
}

function zinesh_verify_google_id_token_google(string $idToken): ?array
{
    if ($idToken === '') {
        return null;
    }
    $oauth = zinesh_google_oauth_config();
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !empty($data['error'])) {
        return null;
    }

    $aud = trim((string)($data['aud'] ?? ''));
    if ($oauth['client_id'] !== '' && $aud !== '' && $aud !== $oauth['client_id']) {
        return null;
    }

    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $verified = $data['email_verified'] ?? false;
    if ($verified !== true && $verified !== 'true' && $verified !== 1 && $verified !== '1') {
        return null;
    }
    if (isset($data['exp']) && (int)$data['exp'] < time()) {
        return null;
    }

    return [
        'sub' => trim((string)($data['sub'] ?? '')),
        'email' => $email,
        'name' => trim((string)($data['name'] ?? '')),
        'photoUrl' => trim((string)($data['picture'] ?? '')),
    ];
}

function zinesh_verify_google_id_token(string $idToken): ?array
{
    return zinesh_verify_google_id_token_firebase($idToken)
        ?? zinesh_verify_google_id_token_google($idToken);
}

function zinesh_google_fetch_userinfo(string $accessToken): ?array
{
    if ($accessToken === '') {
        return null;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
            'timeout' => 12,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents('https://www.googleapis.com/oauth2/v2/userinfo', false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !empty($data['error'])) {
        return null;
    }

    $email = strtolower(trim((string)($data['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $verified = $data['verified_email'] ?? $data['email_verified'] ?? false;
    if ($verified !== true && $verified !== 'true' && $verified !== 1 && $verified !== '1') {
        return null;
    }

    return [
        'sub' => trim((string)($data['id'] ?? $data['sub'] ?? '')),
        'email' => $email,
        'name' => trim((string)($data['name'] ?? '')),
        'photoUrl' => trim((string)($data['picture'] ?? '')),
    ];
}

function zinesh_google_identity_from_token_response(array $tokenData): ?array
{
    $idToken = trim((string)($tokenData['id_token'] ?? ''));
    if ($idToken !== '') {
        $identity = zinesh_verify_google_id_token($idToken);
        if ($identity !== null) {
            return $identity;
        }
    }
    $accessToken = trim((string)($tokenData['access_token'] ?? ''));
    if ($accessToken !== '') {
        return zinesh_google_fetch_userinfo($accessToken);
    }
    return null;
}

/**
 * @return array{ok:bool,url?:string,message?:string}
 */
function zinesh_google_oauth_start_url(string $referralCode = ''): array
{
    $oauth = zinesh_google_oauth_config();
    if (!$oauth['enabled']) {
        return ['ok' => false, 'message' => 'Google ile giriş kapalı.'];
    }
    if (!$oauth['redirect_enabled']) {
        return ['ok' => false, 'message' => 'Google OAuth anahtarları yapılandırılmamış.'];
    }

    $state = bin2hex(random_bytes(16));
    zinesh_oauth_save_state($state, [
        'type' => 'redirect',
        'referralCode' => strtoupper(trim($referralCode)),
    ]);

    $params = [
        'client_id' => $oauth['client_id'],
        'redirect_uri' => zinesh_google_oauth_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'prompt' => 'select_account',
        'access_type' => 'online',
    ];

    return [
        'ok' => true,
        'url' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params),
    ];
}

/**
 * @return array{ok:bool,redirect?:string,message?:string,needsTotp?:bool,needsTotpSetup?:bool,oauthState?:string}
 */
function zinesh_google_oauth_handle_callback(string $code, string $state): array
{
    $oauth = zinesh_google_oauth_config();
    if (!$oauth['redirect_enabled']) {
        return ['ok' => false, 'message' => 'Google OAuth yapılandırılmamış.'];
    }
    if ($code === '' || $state === '') {
        return ['ok' => false, 'message' => 'Google dönüş bilgisi eksik.'];
    }

    $pending = zinesh_oauth_take_state($state);
    if ($pending === null || ($pending['type'] ?? '') !== 'redirect') {
        return ['ok' => false, 'message' => 'Oturum süresi doldu. Tekrar dene.'];
    }

    $body = http_build_query([
        'code' => $code,
        'client_id' => $oauth['client_id'],
        'client_secret' => $oauth['client_secret'],
        'redirect_uri' => zinesh_google_oauth_redirect_uri(),
        'grant_type' => 'authorization_code',
    ]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $raw = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
    if ($raw === false || $raw === '') {
        return ['ok' => false, 'message' => 'Google token alınamadı (sunucu bağlantısı).'];
    }
    $tokenData = json_decode($raw, true);
    if (!is_array($tokenData) || empty($tokenData['access_token'])) {
        $detail = is_array($tokenData)
            ? trim((string)($tokenData['error_description'] ?? $tokenData['error'] ?? ''))
            : '';
        return [
            'ok' => false,
            'message' => 'Google token hatası' . ($detail !== '' ? ': ' . $detail : '. Client secret kontrol edilmeli.'),
        ];
    }

    $identity = zinesh_google_identity_from_token_response($tokenData);
    if ($identity === null || trim((string)($identity['sub'] ?? '')) === '') {
        return ['ok' => false, 'message' => 'Google oturumu doğrulanamadı. Tekrar dene.'];
    }

    $referral = (string)($pending['referralCode'] ?? '');
    $result = zinesh_oauth_google_login_or_register_identity($identity, $referral, '');
    $siteUrl = rtrim((string)zinesh_mail_config()['site_url'], '/');

    if (!$result['ok']) {
        if (!empty($result['needsTotp']) || !empty($result['needsTotpSetup'])) {
            $totpState = bin2hex(random_bytes(16));
            zinesh_oauth_save_state($totpState, [
                'type' => 'totp_pending',
                'identity' => $identity,
                'referralCode' => $referral,
                'needsTotpSetup' => !empty($result['needsTotpSetup']),
            ]);
            $query = http_build_query([
                'oauth_state' => $totpState,
                'needs_totp' => !empty($result['needsTotpSetup']) ? 'setup' : '1',
            ]);
            return ['ok' => true, 'redirect' => $siteUrl . '/?' . $query];
        }
        return zinesh_oauth_redirect_after_login($result);
    }

    return zinesh_oauth_redirect_after_login($result);
}

/**
 * @return array{ok:bool,message?:string,user?:array}
 */
function zinesh_google_oauth_complete_totp(string $oauthState, string $totpCode): array
{
    $pending = zinesh_oauth_take_state($oauthState);
    if ($pending === null || ($pending['type'] ?? '') !== 'totp_pending' || !is_array($pending['identity'] ?? null)) {
        return ['ok' => false, 'message' => 'Oturum süresi doldu. Tekrar Google ile giriş yap.'];
    }

    $result = zinesh_oauth_google_login_or_register_identity(
        $pending['identity'],
        (string)($pending['referralCode'] ?? ''),
        $totpCode
    );
    if (!$result['ok']) {
        if (!empty($result['needsTotp']) || !empty($result['needsTotpSetup'])) {
            zinesh_oauth_save_state($oauthState, $pending);
        }
        return $result;
    }
    return $result;
}

function zinesh_oauth_default_user_profile(string $email, string $name, string $googleSub, string $role = 'dual'): array
{
    $uid = bin2hex(random_bytes(16));
    $clientIp = function_exists('zinesh_client_ip') ? zinesh_client_ip() : '';
    $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

    return [
        'uid' => $uid,
        'name' => $name !== '' ? $name : explode('@', $email)[0],
        'email' => $email,
        'role' => in_array($role, ['web3', 'real', 'dual'], true) ? $role : 'dual',
        'ticketNumber' => zinesh_generate_member_ticket(['uid' => $uid, 'role' => 'dual']),
        'trustScore' => 50,
        'fiziBalance' => 0,
        'usdtBalance' => 0,
        'escrowBalance' => 0,
        'connectedWallets' => [],
        'walletAddress' => '0x' . bin2hex(random_bytes(20)),
        'passwordHash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
        'createdAt' => date('c'),
        'kycStatus' => 'none',
        'referralCode' => strtoupper(substr($uid, 0, 8)),
        'foundingMember' => false,
        'foundingSlotConsumed' => false,
        'emailVerified' => true,
        'authProviders' => ['google'],
        'googleSub' => $googleSub,
        'ipAddress' => $clientIp,
        'registrationIp' => $clientIp,
        'userAgent' => $userAgent,
        'campaignsClaimed' => [],
        'campaignStats' => [
            'completedJobs' => 0,
            'juryDuties' => 0,
            'correctJuryVotes' => 0,
            'foundingReferrals' => 0,
            'referralsVerified' => 0,
            'agreementsCompleted' => 0,
        ],
    ];
}

/**
 * @return array{ok:bool,message?:string,user?:array,isNew?:bool}
 */
/**
 * @param array{sub:string,email:string,name?:string,photoUrl?:string} $identity
 * @return array{ok:bool,message?:string,user?:array,isNew?:bool}
 */
function zinesh_oauth_google_login_or_register_identity(
    array $identity,
    string $referralCodeInput = '',
    string $totpCode = '',
    string $linkPassword = ''
): array {
    $email = strtolower(trim((string)($identity['email'] ?? '')));
    $googleSub = trim((string)($identity['sub'] ?? ''));
    if ($email === '' || $googleSub === '') {
        return ['ok' => false, 'message' => 'Google hesabı tanımlanamadı.'];
    }

    $users = zinesh_load_users();
    $existing = zinesh_find_user_by_email($email);
    $isNew = $existing === null;

    if ($isNew) {
        $profile = zinesh_oauth_default_user_profile($email, (string)($identity['name'] ?? ''), $googleSub);
        $referralCodeInput = strtoupper(trim($referralCodeInput));
        if ($referralCodeInput !== '') {
            $refCheck = zinesh_campaign_validate_referral_code($referralCodeInput, $profile['uid']);
            if (!$refCheck['ok']) {
                $messages = [
                    'invalid_format' => 'Davet kodu 6-12 karakter olmalı (harf ve rakam).',
                    'not_found' => 'Bu davet kodu geçerli değil. Kodu kontrol edip tekrar dene.',
                    'self_referral' => 'Kendi davet kodunu kullanamazsın.',
                    'referral_loop' => 'Referral loop detected',
                ];
                return [
                    'ok' => false,
                    'message' => $refCheck['message'] ?? ($messages[$refCheck['reason'] ?? ''] ?? 'Geçersiz davet kodu.'),
                ];
            }
            $profile['pendingReferralCode'] = $referralCodeInput;
        }
        $users[] = $profile;
        zinesh_save_users($users);
        $uid = $profile['uid'];
        zinesh_audit('register_google', ['uid' => $uid, 'email' => $email]);
    } else {
        $uid = (string)$existing['uid'];
        $idx = zinesh_find_user_index($uid);
        if ($idx === null) {
            return ['ok' => false, 'message' => 'Hesap bulunamadı.'];
        }

        $existingSub = trim((string)($existing['googleSub'] ?? ''));
        if ($existingSub !== '' && $existingSub !== $googleSub) {
            return ['ok' => false, 'message' => 'Bu e-posta başka bir Google hesabına bağlı.'];
        }

        $needsPasswordLink = !empty($existing['passwordHash']) && $existingSub === '';
        if ($needsPasswordLink) {
            if ($linkPassword === '' || !password_verify($linkPassword, (string)$existing['passwordHash'])) {
                if ($linkPassword !== '') {
                    return ['ok' => false, 'message' => 'Şifre hatalı.'];
                }
                $linkState = bin2hex(random_bytes(16));
                zinesh_oauth_save_state('lnk_' . $linkState, [
                    'type' => 'link_pending',
                    'identity' => $identity,
                    'referralCode' => $referralCodeInput,
                ]);
                return [
                    'ok' => false,
                    'needsPasswordLink' => true,
                    'oauthLinkState' => $linkState,
                    'email' => $email,
                    'message' => 'Bu e-posta zaten kayıtlı. Google hesabını bağlamak için şifrenizi girin.',
                ];
            }
        }

        $providers = $users[$idx]['authProviders'] ?? [];
        if (!is_array($providers)) {
            $providers = [];
        }
        if (!in_array('google', $providers, true)) {
            $providers[] = 'google';
        }
        $users[$idx]['authProviders'] = $providers;
        $users[$idx]['googleSub'] = $googleSub;
        if (empty($users[$idx]['emailVerified'])) {
            $users[$idx]['emailVerified'] = true;
        }
        if (($identity['name'] ?? '') !== '' && trim((string)($users[$idx]['name'] ?? '')) === '') {
            $users[$idx]['name'] = (string)$identity['name'];
        }
        if (zinesh_ensure_user_ticket($users[$idx])) {
            // Eski hesaplara üye numarası atandı.
        }
        zinesh_save_users($users);
        zinesh_audit('login_google', ['uid' => $uid, 'email' => $email]);
    }

    $u = zinesh_find_user_by_uid($uid);
    if ($u === null) {
        return ['ok' => false, 'message' => 'Oturum oluşturulamadı.'];
    }

    zinesh_ensure_wallet_fields($u);
    zinesh_campaign_ensure_user_fields($u);
    zinesh_email_sync_legacy_verified($uid);
    $u = zinesh_campaign_persist_user_fields($uid) ?? zinesh_find_user_by_uid($uid) ?? $u;

    if (empty($u['campaignsClaimed']['founding_signup']) && !empty($u['emailVerified'])) {
        zinesh_campaign_try_grant_signup_reward($uid);
        $u = zinesh_find_user_by_uid($uid) ?? $u;
    }

    $totpGate = zinesh_founder_login_totp_gate($uid, $u, $totpCode);
    if (!$totpGate['ok']) {
        return [
            'ok' => false,
            'needsTotp' => !empty($totpGate['needsTotp']),
            'needsTotpSetup' => !empty($totpGate['needsTotpSetup']),
            'totpQrUri' => $totpGate['totpQrUri'] ?? null,
            'message' => $totpGate['message'] ?? '2FA doğrulaması gerekli.',
        ];
    }
    $u = zinesh_find_user_by_uid($uid) ?? $u;

    $token = zinesh_ensure_session($uid, null);
    if (function_exists('zinesh_emit_session_cookie')) {
        zinesh_emit_session_cookie($token);
    }
    if (zinesh_is_founder($u)) {
        $u = zinesh_sync_founder_treasury_wallets($uid);
    }

    return [
        'ok' => true,
        'isNew' => $isNew,
        'user' => zinesh_email_sanitize_public_user(array_merge($u, [
            'sessionToken' => $token,
            'campaign' => zinesh_campaign_user_progress($u),
            'isFounder' => zinesh_is_founder($u),
        ])),
    ];
}

/**
 * @return array{ok:bool,message?:string,user?:array,isNew?:bool}
 */
function zinesh_oauth_google_login_or_register(
    string $idToken,
    string $referralCodeInput = '',
    string $totpCode = ''
): array {
    $identity = zinesh_verify_google_id_token($idToken);
    if ($identity === null) {
        return ['ok' => false, 'message' => 'Google oturumu doğrulanamadı. Tekrar dene.'];
    }
    return zinesh_oauth_google_login_or_register_identity($identity, $referralCodeInput, $totpCode);
}
