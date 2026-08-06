<?php
declare(strict_types=1);

require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/notifications_lib.php';

const ZINESH_PASSWORD_RESET_TTL = 900; // 15 dakika
const ZINESH_PASSWORD_RESET_MAX_ATTEMPTS = 5;

function zinesh_password_reset_generic_message(): string {
    return 'Kayıtlı bir hesabın varsa sıfırlama kodu e-postana gönderildi.';
}

function zinesh_find_user_by_email(string $email): ?array {
    $email = strtolower(trim($email));
    if ($email === '') {
        return null;
    }
    foreach (zinesh_load_users() as $u) {
        if (strtolower((string)($u['email'] ?? '')) === $email) {
            return $u;
        }
    }
    return null;
}

function zinesh_password_reset_cooldown_remaining(array $user): int {
    $sentAt = strtotime((string)($user['passwordResetSentAt'] ?? ''));
    if ($sentAt <= 0) {
        return 0;
    }
    return max(0, 60 - (time() - $sentAt));
}

function zinesh_send_password_reset_email(string $email, string $name, string $code): bool {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = 'Zinesh şifre sıfırlama kodun: ' . $code;
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
            Şifre sıfırlama talebin alındı. Aşağıdaki 6 haneli kodu kullanarak yeni şifreni belirleyebilirsin.
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

    $text = "Merhaba {$name},\n\nŞifre sıfırlama kodun: {$code}\n\nKod 15 dakika geçerlidir.\n";

    $sent = zinesh_send_mail($email, $subject, $html, $text);
    zinesh_audit('password_reset_code_sent', ['email' => $email, 'sent' => $sent]);
    return $sent;
}

/**
 * @return array{ok:bool, message:string, mailSent?:bool, retryAfter?:int}
 */
function zinesh_password_reset_send_code(string $email): array {
    $generic = zinesh_password_reset_generic_message();
    $user = zinesh_find_user_by_email($email);
    if (!$user) {
        return ['ok' => true, 'message' => $generic];
    }

    $uid = (string)($user['uid'] ?? '');
    $retryAfter = zinesh_password_reset_cooldown_remaining($user);
    if ($retryAfter > 0) {
        return [
            'ok' => false,
            'message' => "Yeni kod için {$retryAfter} saniye bekle.",
            'retryAfter' => $retryAfter,
        ];
    }

    $issued = zinesh_email_issue_verification_code();
    zinesh_update_user($uid, static function (array &$u) use ($issued) {
        $u['passwordResetCode'] = $issued['hash'];
        $u['passwordResetExpires'] = $issued['expires'];
        $u['passwordResetAttempts'] = 0;
        $u['passwordResetSentAt'] = date('c');
    });

    $sent = zinesh_send_password_reset_email(
        (string)$user['email'],
        (string)($user['name'] ?? 'Üye'),
        $issued['plain']
    );

    if (!$sent) {
        return [
            'ok' => false,
            'message' => 'E-posta şu an gönderilemedi. Bir dakika sonra tekrar dene.',
        ];
    }

    return ['ok' => true, 'message' => $generic, 'mailSent' => true, 'retryAfter' => 60];
}

/**
 * @return array{ok:bool, reason?:string, message?:string, uid?:string}
 */
function zinesh_password_reset_verify_code(string $email, string $code, bool $incrementOnFail = true): array {
    $user = zinesh_find_user_by_email($email);
    if (!$user) {
        return ['ok' => false, 'reason' => 'code_invalid', 'message' => 'Doğrulama kodu hatalı.'];
    }

    $uid = (string)($user['uid'] ?? '');
    $code = preg_replace('/\D/', '', trim($code));
    if ($code !== '' && strlen($code) < 6) {
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);
    }
    if ($code === '' || strlen($code) !== 6) {
        return ['ok' => false, 'reason' => 'invalid_code', 'message' => 'Geçerli 6 haneli kod gir.'];
    }

    $stored = (string)($user['passwordResetCode'] ?? '');
    if ($stored === '') {
        return ['ok' => false, 'reason' => 'no_code', 'message' => 'Aktif sıfırlama kodu yok. Yeni kod iste.'];
    }

    if ((int)($user['passwordResetExpires'] ?? 0) < time()) {
        return ['ok' => false, 'reason' => 'code_expired', 'message' => 'Kodun süresi dolmuş. Yeni kod gönder.'];
    }

    $attempts = (int)($user['passwordResetAttempts'] ?? 0);
    if ($attempts >= ZINESH_PASSWORD_RESET_MAX_ATTEMPTS) {
        return [
            'ok' => false,
            'reason' => 'too_many_attempts',
            'message' => 'Çok fazla hatalı deneme. Yeni kod iste.',
        ];
    }

    $hash = zinesh_email_verify_code_hash($code);
    if (!hash_equals($stored, $hash)) {
        if ($incrementOnFail) {
            zinesh_update_user($uid, static function (array &$u) {
                $u['passwordResetAttempts'] = (int)($u['passwordResetAttempts'] ?? 0) + 1;
            });
        }
        return ['ok' => false, 'reason' => 'code_invalid', 'message' => 'Doğrulama kodu hatalı.'];
    }

    return ['ok' => true, 'uid' => $uid];
}

/**
 * @return array{ok:bool, message:string, reason?:string}
 */
function zinesh_password_reset_apply(string $email, string $code, string $newPassword): array {
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'Şifre en az 8 karakter olmalı.', 'reason' => 'weak_password'];
    }

    $verify = zinesh_password_reset_verify_code($email, $code, true);
    if (!$verify['ok']) {
        return [
            'ok' => false,
            'message' => $verify['message'] ?? 'Doğrulama başarısız.',
            'reason' => $verify['reason'] ?? 'error',
        ];
    }

    $uid = (string)($verify['uid'] ?? '');
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    zinesh_update_user($uid, static function (array &$u) use ($hash) {
        $u['passwordHash'] = $hash;
        $u['passwordChangedAt'] = date('c');
        unset(
            $u['passwordResetCode'],
            $u['passwordResetExpires'],
            $u['passwordResetAttempts'],
            $u['passwordResetSentAt']
        );
    });

    zinesh_revoke_all_user_sessions($uid);
    zinesh_audit('password_reset_completed', ['uid' => $uid]);
    zinesh_notify_user($uid, 'PASSWORD_RESET');

    return [
        'ok' => true,
        'message' => 'Şifren güncellendi. Tüm oturumların kapatıldı — tekrar giriş yap.',
    ];
}

/**
 * @return array{ok:bool, message:string, reason?:string, requiresLogin?:bool}
 */
function zinesh_change_password(string $uid, string $currentPassword, string $newPassword): array {
    if (strlen($newPassword) < 8) {
        return ['ok' => false, 'message' => 'Yeni şifre en az 8 karakter olmalı.', 'reason' => 'weak_password'];
    }

    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['ok' => false, 'message' => 'Kullanıcı bulunamadı.', 'reason' => 'not_found'];
    }

    if ($currentPassword === '' || !isset($user['passwordHash']) || !password_verify($currentPassword, (string)$user['passwordHash'])) {
        return ['ok' => false, 'message' => 'Mevcut şifre hatalı.', 'reason' => 'wrong_password'];
    }

    if (password_verify($newPassword, (string)$user['passwordHash'])) {
        return ['ok' => false, 'message' => 'Yeni şifre mevcut şifreyle aynı olamaz.', 'reason' => 'same_password'];
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    zinesh_update_user($uid, static function (array &$u) use ($hash) {
        $u['passwordHash'] = $hash;
        $u['passwordChangedAt'] = date('c');
    });

    zinesh_revoke_all_user_sessions($uid);
    zinesh_audit('password_changed', ['uid' => $uid]);
    zinesh_notify_user($uid, 'PASSWORD_CHANGED');

    return [
        'ok' => true,
        'message' => 'Şifren güncellendi. Güvenlik için tüm oturumlar kapatıldı — tekrar giriş yap.',
        'requiresLogin' => true,
    ];
}

function zinesh_password_reset_strip_private_fields(array $user): array {
    unset(
        $user['passwordResetCode'],
        $user['passwordResetExpires'],
        $user['passwordResetAttempts'],
        $user['passwordResetSentAt']
    );
    return $user;
}
