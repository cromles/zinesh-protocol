<?php
declare(strict_types=1);

/** Google Authenticator uyumlu TOTP (RFC 6238) — harici bağımlılık yok. */

function zinesh_totp_base32_encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $chunks = str_split($binary, 5);
    $encoded = '';
    foreach ($chunks as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $encoded .= $alphabet[bindec($chunk)];
    }
    return $encoded;
}

function zinesh_totp_base32_decode(string $secret): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/\s+/', '', $secret));
    $binary = '';
    foreach (str_split($secret) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) {
            continue;
        }
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = str_split($binary, 8);
    $decoded = '';
    foreach ($bytes as $byte) {
        if (strlen($byte) < 8) {
            break;
        }
        $decoded .= chr(bindec($byte));
    }
    return $decoded;
}

function zinesh_totp_generate_secret(int $bytes = 20): string {
    return zinesh_totp_base32_encode(random_bytes($bytes));
}

function zinesh_totp_code(string $secret, ?int $timeSlice = null): string {
    $timeSlice = $timeSlice ?? (int)floor(time() / 30);
    $key = zinesh_totp_base32_decode($secret);
    if ($key === '') {
        return '000000';
    }
    $time = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $otp = (
        ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff)
    ) % 1_000_000;
    return str_pad((string)$otp, 6, '0', STR_PAD_LEFT);
}

function zinesh_totp_verify(string $secret, string $code, int $window = 2): bool {
    $code = preg_replace('/\D/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $timeSlice = (int)floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(zinesh_totp_code($secret, $timeSlice + $i), $code)) {
            return true;
        }
    }
    return false;
}

function zinesh_totp_provisioning_uri(string $secret, string $email, string $issuer = 'Zinesh'): string {
    $label = rawurlencode($issuer . ':' . $email);
    return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret) . '&issuer=' . rawurlencode($issuer);
}

function zinesh_founder_requires_totp(array $user): bool {
    return function_exists('zinesh_is_founder') && zinesh_is_founder($user);
}

function zinesh_totp_sanitize_user(array $user): array {
    unset($user['totpSecret'], $user['totpPendingSecret']);
    return $user;
}

/**
 * Kurucu girişi TOTP adımı — oturum açmadan önce çağrılır.
 *
 * @return array{ok:bool,needsTotpSetup?:bool,needsTotp?:bool,totpQrUri?:string,message?:string}
 */
function zinesh_founder_login_totp_gate(string $uid, array $user, string $totpCode): array {
    if (!zinesh_founder_requires_totp($user)) {
        return ['ok' => true];
    }

    $fresh = zinesh_find_user_by_uid($uid);
    if (is_array($fresh)) {
        $user = $fresh;
    }

    $totpCode = trim($totpCode);
    $pending = trim((string)($user['totpPendingSecret'] ?? ''));
    $secret = trim((string)($user['totpSecret'] ?? ''));
    $enabled = !empty($user['totpEnabled']);
    $email = (string)($user['email'] ?? '');

    if (!$enabled && $secret === '' && $pending === '') {
        $newSecret = zinesh_totp_generate_secret();
        zinesh_update_user($uid, static function (array &$u) use ($newSecret) {
            $u['totpPendingSecret'] = $newSecret;
        });
        $saved = zinesh_find_user_by_uid($uid);
        $activePending = trim((string)($saved['totpPendingSecret'] ?? $newSecret));
        return [
            'ok' => false,
            'needsTotpSetup' => true,
            'needsTotp' => false,
            'totpQrUri' => zinesh_totp_provisioning_uri($activePending, $email),
            'message' => 'Kurucu hesabı için Google Authenticator kurulumu gerekli. Aşağıdaki kurulum anahtarını veya QR kodunu kullanın, ardından 6 haneli kodu girin.',
        ];
    }

    $activeSecret = $enabled ? $secret : $pending;
    if ($activeSecret === '') {
        return ['ok' => false, 'needsTotpSetup' => true, 'needsTotp' => false, 'message' => '2FA kurulumu tamamlanamadı. Tekrar deneyin.'];
    }

    if ($totpCode === '') {
        return [
            'ok' => false,
            'needsTotp' => $enabled,
            'needsTotpSetup' => !$enabled,
            'totpQrUri' => !$enabled ? zinesh_totp_provisioning_uri($activeSecret, $email) : null,
            'message' => $enabled
                ? 'Google Authenticator kodunu girin.'
                : 'Authenticator kurulumunu tamamlamak için 6 haneli kodu girin.',
        ];
    }

    if (!zinesh_totp_verify($activeSecret, $totpCode)) {
        return [
            'ok' => false,
            'needsTotp' => $enabled,
            'needsTotpSetup' => !$enabled,
            'totpQrUri' => !$enabled ? zinesh_totp_provisioning_uri($activeSecret, $email) : null,
            'message' => $enabled
                ? 'Google Authenticator kodu hatalı. Telefon saatinin otomatik olduğundan emin olun ve yeni kodu deneyin.'
                : 'Kod hatalı veya süresi doldu. Aynı kurulum anahtarını kullandığından ve telefon saatinin doğru olduğundan emin olun.',
        ];
    }

    if (!$enabled && $pending !== '') {
        zinesh_update_user($uid, static function (array &$u) use ($pending) {
            $u['totpSecret'] = $pending;
            unset($u['totpPendingSecret']);
            $u['totpEnabled'] = true;
            $u['totpEnabledAt'] = date('c');
        });
    }

    return ['ok' => true];
}
