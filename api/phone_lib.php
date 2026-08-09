<?php
declare(strict_types=1);

require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/kyc_lib.php';
require_once __DIR__ . '/verification_lib.php';
require_once __DIR__ . '/firebase_auth_lib.php';

function zinesh_phone_normalize(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '90') && strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (str_starts_with($digits, '0') && strlen($digits) === 11) {
        $digits = substr($digits, 1);
    }
    return $digits;
}

function zinesh_phone_format_display(string $digits): string
{
    $digits = zinesh_phone_normalize($digits);
    if (strlen($digits) === 10) {
        return '+90 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2);
    }
    return $digits;
}

function zinesh_phone_resend_cooldown_remaining(array $user): int
{
    $sentAt = strtotime((string)($user['phoneVerifySentAt'] ?? ''));
    if ($sentAt <= 0) {
        return 0;
    }
    return max(0, 60 - (time() - $sentAt));
}

/**
 * @return array{sent:bool, message:string, mailSent:bool, retryAfter?:int}
 */
function zinesh_phone_prepare_verification(string $uid, string $phoneInput): array
{
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['sent' => false, 'message' => 'Kullanıcı bulunamadı.', 'mailSent' => false];
    }
    if (!zinesh_user_email_verified($user)) {
        return ['sent' => false, 'message' => 'Önce e-postanı doğrula.', 'mailSent' => false];
    }
    if (!empty($user['phoneVerified'])) {
        return ['sent' => false, 'message' => 'Telefon zaten doğrulanmış.', 'mailSent' => false];
    }

    $phone = zinesh_phone_normalize($phoneInput);
    if (strlen($phone) < 10 || strlen($phone) > 11) {
        return ['sent' => false, 'message' => 'Geçerli bir cep telefonu numarası gir (10 haneli).', 'mailSent' => false];
    }
    $fakePhone = zinesh_kyc_reject_obvious_fake_number($phone);
    if ($fakePhone !== null) {
        return ['sent' => false, 'message' => $fakePhone, 'mailSent' => false];
    }

    $retryAfter = zinesh_phone_resend_cooldown_remaining($user);
    if ($retryAfter > 0) {
        return [
            'sent' => false,
            'message' => "Yeni kod için {$retryAfter} saniye bekle.",
            'mailSent' => false,
            'retryAfter' => $retryAfter,
        ];
    }

    $issued = zinesh_email_issue_verification_code();
    zinesh_update_user($uid, static function (array &$u) use ($issued, $phone) {
        $u['pendingPhone'] = $phone;
        $u['phoneVerifyCode'] = $issued['hash'];
        $u['phoneVerifyExpires'] = $issued['expires'];
        $u['phoneVerifySentAt'] = date('c');
    });

    $displayPhone = zinesh_phone_format_display($phone);
    $name = (string)($user['name'] ?? 'Üye');
    $email = (string)($user['email'] ?? '');
    $plainCode = $issued['plain'];
    $subject = 'Zinesh — Telefon doğrulama kodun';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="tr">
<body style="margin:0;padding:0;background:#06060a;font-family:Arial,sans-serif;color:#e4e4e7;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#06060a;padding:32px 16px;">
    <tr><td align="center">
      <table width="100%" style="max-width:520px;background:#0c0c12;border:1px solid #27272a;border-radius:16px;padding:32px;">
        <tr><td>
          <p style="margin:0 0 8px;font-size:11px;letter-spacing:0.12em;color:#38bdf8;text-transform:uppercase;">Zinesh Güvenlik</p>
          <p style="margin:0 0 16px;font-size:18px;line-height:1.5;color:#fff;font-weight:600;">Merhaba {$name},</p>
          <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#a1a1aa;">
            <strong style="color:#e4e4e7;">{$displayPhone}</strong> numarasını hesabına bağlamak için aşağıdaki 6 haneli kodu profil → Telefon bölümüne gir.
          </p>
          <p style="margin:24px 0;text-align:center;font-size:36px;font-weight:bold;letter-spacing:0.35em;color:#38bdf8;font-family:monospace;">
            {$plainCode}
          </p>
          <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#71717a;text-align:center;">
            Kod 15 dakika geçerlidir. Bu talebi sen yapmadıysan şifreni değiştir.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    $text = "Merhaba {$name},\n\nTelefon doğrulama kodun ({$displayPhone}): {$plainCode}\n\nKod 15 dakika geçerlidir.\n";
    $sent = zinesh_send_mail($email, $subject, $html, $text);
    zinesh_audit('phone_verification_sent', ['uid' => $uid, 'sent' => $sent]);

    if (!$sent) {
        return [
            'sent' => false,
            'message' => 'Doğrulama kodu e-postası gönderilemedi. Bir dakika sonra tekrar dene.',
            'mailSent' => false,
        ];
    }

    return [
        'sent' => true,
        'message' => 'Doğrulama kodu e-postana gönderildi. Kodu profil sayfasından gir.',
        'mailSent' => true,
    ];
}

/**
 * @return array{ok:bool, reason?:string, message?:string, user?:array}
 */
function zinesh_phone_verify_code(string $uid, string $code): array
{
    $code = preg_replace('/\D/', '', trim($code));
    if ($code !== '' && strlen($code) < 6) {
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);
    }
    if ($code === '' || strlen($code) !== 6) {
        return ['ok' => false, 'reason' => 'invalid_code', 'message' => 'Geçerli 6 haneli kod gir.'];
    }

    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['ok' => false, 'reason' => 'user_not_found', 'message' => 'Hesap bulunamadı.'];
    }
    if (!empty($user['phoneVerified'])) {
        return ['ok' => true, 'user' => $user];
    }

    $hash = zinesh_email_verify_code_hash($code);
    $stored = (string)($user['phoneVerifyCode'] ?? '');
    if ($stored === '' || !hash_equals($stored, $hash)) {
        return ['ok' => false, 'reason' => 'code_invalid', 'message' => 'Doğrulama kodu hatalı.'];
    }
    if ((int)($user['phoneVerifyExpires'] ?? 0) < time()) {
        return ['ok' => false, 'reason' => 'code_expired', 'message' => 'Kodun süresi dolmuş. Yeni kod iste.'];
    }

    $pendingPhone = zinesh_phone_normalize((string)($user['pendingPhone'] ?? ''));
    if ($pendingPhone === '') {
        return ['ok' => false, 'reason' => 'no_pending_phone', 'message' => 'Önce telefon numaranı girip kod iste.'];
    }

    zinesh_update_user($uid, static function (array &$u) use ($pendingPhone) {
        $u['phone'] = $pendingPhone;
        $u['phoneVerified'] = true;
        $u['phoneVerifiedAt'] = date('c');
        unset($u['pendingPhone'], $u['phoneVerifyCode'], $u['phoneVerifyExpires']);
    });

    $user = zinesh_find_user_by_uid($uid);
    zinesh_audit('phone_verified', ['uid' => $uid]);

    return ['ok' => true, 'user' => $user];
}

function zinesh_phone_find_uid_by_normalized(string $normalizedPhone, ?string $excludeUid = null): ?string
{
    if ($normalizedPhone === '') {
        return null;
    }
    foreach (zinesh_load_users() as $u) {
        $uid = (string)($u['uid'] ?? '');
        if ($uid === '' || ($excludeUid !== null && $uid === $excludeUid)) {
            continue;
        }
        if (empty($u['phoneVerified'])) {
            continue;
        }
        $stored = zinesh_phone_normalize((string)($u['phone'] ?? ''));
        if ($stored !== '' && $stored === $normalizedPhone) {
            return $uid;
        }
    }
    return null;
}

/**
 * Firebase Phone Auth idToken — SMS OTP sonrası phone_verified güncelle.
 *
 * @return array{ok:bool, reason?:string, message?:string, user?:array}
 */
function zinesh_phone_verify_firebase_token(string $uid, string $idToken): array
{
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['ok' => false, 'reason' => 'user_not_found', 'message' => 'Hesap bulunamadı.'];
    }
    if (!zinesh_user_email_verified($user)) {
        return ['ok' => false, 'reason' => 'email_not_verified', 'message' => 'Önce e-postanı doğrula.'];
    }
    if (!empty($user['phoneVerified'])) {
        return ['ok' => true, 'user' => $user];
    }

    $idToken = trim($idToken);
    if ($idToken === '') {
        return ['ok' => false, 'reason' => 'missing_token', 'message' => 'Firebase doğrulama token\'ı gerekli.'];
    }

    $firebaseUser = zinesh_firebase_lookup_id_token($idToken);
    if ($firebaseUser === null) {
        return ['ok' => false, 'reason' => 'invalid_token', 'message' => 'Firebase doğrulaması geçersiz veya süresi dolmuş.'];
    }

    $e164 = trim((string)($firebaseUser['phoneNumber'] ?? ''));
    if ($e164 === '') {
        return ['ok' => false, 'reason' => 'no_phone', 'message' => 'Firebase oturumunda telefon numarası bulunamadı.'];
    }

    $phone = zinesh_phone_normalize($e164);
    if (strlen($phone) < 10 || strlen($phone) > 11) {
        return ['ok' => false, 'reason' => 'invalid_phone', 'message' => 'Geçerli bir telefon numarası doğrulanamadı.'];
    }
    $fakePhone = zinesh_kyc_reject_obvious_fake_number($phone);
    if ($fakePhone !== null) {
        return ['ok' => false, 'reason' => 'fake_phone', 'message' => $fakePhone];
    }

    $otherUid = zinesh_phone_find_uid_by_normalized($phone, $uid);
    if ($otherUid !== null) {
        return [
            'ok' => false,
            'reason' => 'phone_taken',
            'message' => 'Bu telefon numarası başka bir hesapta doğrulanmış.',
        ];
    }

    $firebaseUid = trim((string)($firebaseUser['localId'] ?? ''));
    zinesh_update_user($uid, static function (array &$u) use ($phone, $firebaseUid) {
        $u['phone'] = $phone;
        $u['phoneVerified'] = true;
        $u['phoneVerifiedAt'] = date('c');
        $u['phoneVerifiedVia'] = 'firebase';
        if ($firebaseUid !== '') {
            $u['firebasePhoneUid'] = $firebaseUid;
        }
        unset($u['pendingPhone'], $u['phoneVerifyCode'], $u['phoneVerifyExpires'], $u['phoneVerifySentAt']);
    });

    $user = zinesh_find_user_by_uid($uid);
    zinesh_audit('phone_verified_firebase', ['uid' => $uid, 'phone' => '***' . substr($phone, -4)]);

    return ['ok' => true, 'user' => $user];
}
