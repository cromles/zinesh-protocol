<?php
declare(strict_types=1);

require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/password_reset_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/totp_lib.php';
const ZINESH_TOTP_RESET_ALLOWED_EMAIL = 'yasinkarademir147@gmail.com';
const ZINESH_TOTP_RESET_TTL = 900;
const ZINESH_TOTP_RESET_MAX_ATTEMPTS = 5;

function zinesh_totp_reset_email_allowed(string $email): bool {
    return strtolower(trim($email)) === ZINESH_TOTP_RESET_ALLOWED_EMAIL;
}

function zinesh_totp_reset_require_founder_user(string $email, string $password): array {
    $email = strtolower(trim($email));
    if (!zinesh_totp_reset_email_allowed($email)) {
        return ['ok' => false, 'message' => 'Bu işlem yalnızca kurucu hesabı içindir.', 'reason' => 'not_allowed'];
    }

    $user = zinesh_find_user_by_email($email);
    if (!$user) {
        return ['ok' => false, 'message' => 'Hesap bulunamadı.', 'reason' => 'not_found'];
    }

    if (!zinesh_is_founder($user)) {
        return ['ok' => false, 'message' => 'Bu işlem yalnızca kurucu hesabı içindir.', 'reason' => 'not_founder'];
    }

    if ($password === '' || !isset($user['passwordHash']) || !password_verify($password, (string)$user['passwordHash'])) {
        return ['ok' => false, 'message' => 'Mevcut şifre hatalı.', 'reason' => 'wrong_password'];
    }

    return ['ok' => true, 'user' => $user, 'uid' => (string)($user['uid'] ?? '')];
}

function zinesh_totp_reset_cooldown_remaining(array $user): int {
    $sentAt = strtotime((string)($user['totpResetSentAt'] ?? ''));
    if ($sentAt <= 0) {
        return 0;
    }
    return max(0, 60 - (time() - $sentAt));
}

function zinesh_send_totp_reset_email(string $email, string $name, string $code): bool {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = 'Zinesh Authenticator sıfırlama kodun: ' . $code;
    $html = <<<HTML
<!DOCTYPE html>
<html lang="tr">
<body style="margin:0;padding:0;background:#06060a;font-family:Arial,sans-serif;color:#e4e4e7;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#06060a;padding:32px 16px;">
    <tr><td align="center">
      <table width="100%" style="max-width:520px;background:#0c0c12;border:1px solid #27272a;border-radius:16px;padding:32px;">
        <tr><td>
          <p style="margin:0 0 8px;font-size:11px;letter-spacing:0.12em;color:#a78bfa;text-transform:uppercase;">Zinesh Güvenlik</p>
          <p style="margin:0 0 16px;font-size:18px;line-height:1.5;color:#fff;font-weight:600;">Merhaba {$safeName},</p>
          <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#a1a1aa;">
            Google Authenticator kurulumunu sıfırlama talebin alındı. Aşağıdaki 6 haneli kodu kullan.
          </p>
          <p style="margin:24px 0;text-align:center;font-size:36px;font-weight:bold;letter-spacing:0.35em;color:#f59e0b;font-family:monospace;">
            {$safeCode}
          </p>
          <p style="margin:16px 0 0;font-size:12px;line-height:1.6;color:#71717a;text-align:center;">
            Kod 15 dakika geçerlidir. Bu talebi sen yapmadıysan bu e-postayı yok say.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $text = "Merhaba {$name},\n\nAuthenticator sıfırlama kodun: {$code}\n\nKod 15 dakika geçerlidir.\n";
    $sent = zinesh_send_mail($email, $subject, $html, $text);
    zinesh_audit('totp_reset_code_sent', ['email' => $email, 'sent' => $sent]);
    return $sent;
}

/**
 * @return array{ok:bool, message:string, mailSent?:bool, retryAfter?:int}
 */
function zinesh_totp_reset_send_code(string $email, string $password): array {
    $gate = zinesh_totp_reset_require_founder_user($email, $password);
    if (!$gate['ok']) {
        return ['ok' => false, 'message' => $gate['message']];
    }

    $user = $gate['user'];
    $uid = $gate['uid'];
    $retryAfter = zinesh_totp_reset_cooldown_remaining($user);
    if ($retryAfter > 0) {
        return [
            'ok' => false,
            'message' => "Yeni kod için {$retryAfter} saniye bekle.",
            'retryAfter' => $retryAfter,
        ];
    }

    $issued = zinesh_email_issue_verification_code();
    zinesh_update_user($uid, static function (array &$u) use ($issued) {
        $u['totpResetCode'] = $issued['hash'];
        $u['totpResetExpires'] = $issued['expires'];
        $u['totpResetAttempts'] = 0;
        $u['totpResetSentAt'] = date('c');
    });

    $sent = zinesh_send_totp_reset_email(
        (string)$user['email'],
        (string)($user['name'] ?? 'Kurucu'),
        $issued['plain']
    );

    if (!$sent) {
        return ['ok' => false, 'message' => 'E-posta şu an gönderilemedi. Bir dakika sonra tekrar dene.'];
    }

    return [
        'ok' => true,
        'message' => 'Doğrulama kodu e-postana gönderildi.',
        'mailSent' => true,
        'retryAfter' => 60,
    ];
}

/**
 * @return array{ok:bool, reason?:string, message?:string}
 */
function zinesh_totp_reset_verify_code(string $email, string $code, bool $incrementOnFail = true): array {
    if (!zinesh_totp_reset_email_allowed($email)) {
        return ['ok' => false, 'reason' => 'not_allowed', 'message' => 'Bu işlem yalnızca kurucu hesabı içindir.'];
    }

    $user = zinesh_find_user_by_email($email);
    if (!$user || !zinesh_is_founder($user)) {
        return ['ok' => false, 'reason' => 'not_found', 'message' => 'Doğrulama kodu hatalı.'];
    }

    $uid = (string)($user['uid'] ?? '');
    $code = preg_replace('/\D/', '', trim($code));
    if ($code !== '' && strlen($code) < 6) {
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);
    }
    if ($code === '' || strlen($code) !== 6) {
        return ['ok' => false, 'reason' => 'invalid_code', 'message' => 'Geçerli 6 haneli kod gir.'];
    }

    $stored = (string)($user['totpResetCode'] ?? '');
    if ($stored === '') {
        return ['ok' => false, 'reason' => 'no_code', 'message' => 'Aktif doğrulama kodu yok. Önce kod iste.'];
    }

    if ((int)($user['totpResetExpires'] ?? 0) < time()) {
        return ['ok' => false, 'reason' => 'code_expired', 'message' => 'Kodun süresi dolmuş. Yeni kod iste.'];
    }

    $attempts = (int)($user['totpResetAttempts'] ?? 0);
    if ($attempts >= ZINESH_TOTP_RESET_MAX_ATTEMPTS) {
        return ['ok' => false, 'reason' => 'too_many_attempts', 'message' => 'Çok fazla hatalı deneme. Yeni kod iste.'];
    }

    $hash = zinesh_email_verify_code_hash($code);
    if (!hash_equals($stored, $hash)) {
        if ($incrementOnFail) {
            zinesh_update_user($uid, static function (array &$u) {
                $u['totpResetAttempts'] = (int)($u['totpResetAttempts'] ?? 0) + 1;
            });
        }
        return ['ok' => false, 'reason' => 'code_invalid', 'message' => 'Doğrulama kodu hatalı.'];
    }

    return ['ok' => true, 'uid' => $uid];
}

/**
 * @return array{ok:bool, message:string, totpQrUri?:string, needsTotpSetup?:bool}
 */
function zinesh_totp_reset_apply(string $email, string $password, string $code): array {
    $gate = zinesh_totp_reset_require_founder_user($email, $password);
    if (!$gate['ok']) {
        return ['ok' => false, 'message' => $gate['message']];
    }

    $verify = zinesh_totp_reset_verify_code($email, $code, true);
    if (!$verify['ok']) {
        return ['ok' => false, 'message' => $verify['message'] ?? 'Doğrulama başarısız.'];
    }

    $uid = (string)($verify['uid'] ?? $gate['uid']);
    $newSecret = zinesh_totp_generate_secret();
    zinesh_update_user($uid, static function (array &$u) use ($newSecret) {
        unset(
            $u['totpSecret'],
            $u['totpPendingSecret'],
            $u['totpResetCode'],
            $u['totpResetExpires'],
            $u['totpResetAttempts'],
            $u['totpResetSentAt']
        );
        $u['totpEnabled'] = false;
        unset($u['totpEnabledAt']);
        $u['totpPendingSecret'] = $newSecret;
    });

    zinesh_audit('totp_reset_completed', ['uid' => $uid, 'email' => ZINESH_TOTP_RESET_ALLOWED_EMAIL]);

    return [
        'ok' => true,
        'message' => 'Authenticator kurulumu sıfırlandı. Yeni QR kodunu tarayıp ilk kodu gir.',
        'needsTotpSetup' => true,
        'totpQrUri' => zinesh_totp_provisioning_uri($newSecret, ZINESH_TOTP_RESET_ALLOWED_EMAIL),
    ];
}
