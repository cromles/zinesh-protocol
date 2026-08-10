<?php
declare(strict_types=1);

require_once __DIR__ . '/smtp_lib.php';
require_once __DIR__ . '/brevo_api_lib.php';
require_once __DIR__ . '/verification_lib.php';

/** Brevo'da doğrulanmış gönderen (domain SPF/DKIM tamamlanana kadar noreply@ kullanılamaz). */
function zinesh_mail_verified_sender_email(): string {
    $env = getenv('ZINESH_MAIL_FROM');
    if (is_string($env) && filter_var(trim($env), FILTER_VALIDATE_EMAIL)) {
        return strtolower(trim($env));
    }

    $cfg = zinesh_config();
    $founders = $cfg['founder_emails'] ?? [];
    if (is_array($founders)) {
        foreach ($founders as $email) {
            $email = strtolower(trim((string)$email));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }
    }

    $configured = strtolower(trim((string)($cfg['mail']['from_email'] ?? '')));
    if ($configured !== '' && !zinesh_mail_is_unverified_sender($configured)) {
        return $configured;
    }

    return 'yasinkarademir147@gmail.com';
}

function zinesh_mail_is_unverified_sender(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return true;
    }

    return $email === 'noreply@zinesh.com';
}

function zinesh_mail_config(): array {
    $verifiedFrom = zinesh_mail_verified_sender_email();
    $defaults = [
        'from_email' => $verifiedFrom,
        'from_name' => 'Zinesh',
        'site_url' => 'https://www.zinesh.com',
        'verify_path' => '/api/verify_email.php',
        'smtp' => [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'timeout' => 20,
        ],
    ];
    $cfg = array_merge($defaults, zinesh_config()['mail'] ?? []);
    if (!isset($cfg['smtp']) || !is_array($cfg['smtp'])) {
        $cfg['smtp'] = $defaults['smtp'];
    } else {
        $cfg['smtp'] = array_merge($defaults['smtp'], $cfg['smtp']);
    }

    if (function_exists('zinesh_load_server_secrets')) {
        $secrets = zinesh_load_server_secrets();
        if (!empty($secrets['smtp']) && is_array($secrets['smtp'])) {
            $cfg['smtp'] = array_merge($cfg['smtp'], $secrets['smtp']);
            if (!empty($secrets['smtp']['host'])) {
                $cfg['smtp']['enabled'] = (bool)($secrets['smtp']['enabled'] ?? true);
            }
        }
        if (!empty($secrets['mail_from'])) {
            $cfg['from_email'] = (string)$secrets['mail_from'];
        }
        if (!empty($secrets['mail_reply_to'])) {
            $cfg['reply_to'] = (string)$secrets['mail_reply_to'];
        }
    }

    if (zinesh_mail_is_unverified_sender((string)$cfg['from_email'])) {
        $cfg['from_email'] = $verifiedFrom;
    }

    if (!isset($cfg['reply_to'])) {
        $cfg['reply_to'] = $cfg['from_email'];
    }
    $cfg['reply_to'] = zinesh_mail_normalize_reply_to((string)$cfg['reply_to'], (string)$cfg['from_email']);

    return $cfg;
}

/** SMTP kullanıcı adı veya teknik adresleri Reply-To yapma — spam skorunu düşürür. */
function zinesh_mail_normalize_reply_to(string $replyTo, string $fallback = ''): string {
    if ($fallback === '' || zinesh_mail_is_unverified_sender($fallback)) {
        $fallback = zinesh_mail_verified_sender_email();
    }
    $replyTo = strtolower(trim($replyTo));
    if ($replyTo === '' || !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        return $fallback;
    }
    if (preg_match('/@(smtp-brevo\.com|sendinblue\.com)$/i', $replyTo)) {
        return $fallback;
    }
    return $replyTo;
}

function zinesh_email_verify_code_hash(string $code): string {
    return hash('sha256', preg_replace('/\D/', '', $code));
}

function zinesh_email_issue_verification_code(): array {
    $plain = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    return [
        'plain' => $plain,
        'hash' => zinesh_email_verify_code_hash($plain),
        'expires' => time() + 60 * 15,
    ];
}

function zinesh_email_verify_token_hash(string $token): string {
    return hash('sha256', $token);
}

function zinesh_email_issue_verification_token(): array {
    $plain = bin2hex(random_bytes(32));
    return [
        'plain' => $plain,
        'hash' => zinesh_email_verify_token_hash($plain),
        'expires' => time() + 60 * 60 * 24,
    ];
}

function zinesh_email_ensure_user_fields(array &$user): void {
    if (!isset($user['emailVerified'])) {
        $user['emailVerified'] = !empty($user['campaignsClaimed']['founding_signup']);
    }
}

/** Eski üyeler: kayıt ödülü almışsa doğrulanmış say */
function zinesh_email_sync_legacy_verified(string $uid): void {
    zinesh_update_user($uid, static function (array &$u) {
        zinesh_email_ensure_user_fields($u);
        if (!empty($u['campaignsClaimed']['founding_signup']) && empty($u['emailVerified'])) {
            $u['emailVerified'] = true;
            unset($u['emailVerifyToken'], $u['emailVerifyExpires']);
        }
    });
}

function zinesh_email_public_fields(array $user): array {
    zinesh_email_ensure_user_fields($user);
    $verified = !empty($user['emailVerified']);
    $pending = !$verified && (!empty($user['emailVerifyCode']) || !empty($user['emailVerifyToken']));
    $signupClaimed = !empty($user['campaignsClaimed']['founding_signup']);

    return [
        'emailVerified' => $verified,
        'emailVerificationPending' => $pending,
        'signupRewardPending' => !$signupClaimed && zinesh_campaign_is_active(zinesh_campaign_ensure_state()),
        'signupRewardAmount' => (float)(zinesh_campaign_rewards_map()['founding_signup'] ?? 100),
        'googleLinked' => trim((string)($user['googleSub'] ?? '')) !== '',
        'totpEnabled' => !empty($user['totpEnabled']),
        'jobHistoryPublic' => !array_key_exists('jobHistoryPublic', $user) || !empty($user['jobHistoryPublic']),
    ];
}

function zinesh_email_sanitize_public_user(array $user): array {
    $publicFields = zinesh_email_public_fields($user);
    if (function_exists('zinesh_verification_public_fields')) {
        $publicFields = array_merge($publicFields, zinesh_verification_public_fields($user));
    }
    unset(
        $user['emailVerifyToken'],
        $user['emailVerifyExpires'],
        $user['emailVerifyCode'],
        $user['passwordHash'],
        $user['pendingReferralCode'],
        $user['nationalIdHash'],
        $user['kycProfile'],
        $user['phoneVerifyCode'],
        $user['phoneVerifyExpires'],
        $user['pendingPhone'],
        $user['firebasePhoneUid'],
        $user['totpSecret'],
        $user['totpPendingSecret'],
        $user['passwordResetCode'],
        $user['passwordResetExpires'],
        $user['passwordResetAttempts'],
        $user['passwordResetSentAt'],
        $user['googleSub']
    );
    return array_merge($user, $publicFields);
}

function zinesh_email_verification_url(string $plainToken): string {
    $cfg = zinesh_mail_config();
    $base = rtrim((string)$cfg['site_url'], '/');
    return $base . '/?verify_token=' . urlencode($plainToken);
}

function zinesh_send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool {
    $cfg = zinesh_mail_config();
    $fromEmail = (string)$cfg['from_email'];
    $fromName = (string)$cfg['from_name'];
    $replyTo = (string)($cfg['reply_to'] ?? $fromEmail);

    if ($textBody === '') {
        $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    }

    if (zinesh_brevo_api_configured()) {
        $apiResult = zinesh_brevo_send_transactional(
            $to,
            $subject,
            $htmlBody,
            $textBody,
            $fromEmail,
            $fromName,
            $replyTo
        );
        if ($apiResult['ok']) {
            zinesh_audit('mail_sent_brevo_api', ['to' => $to, 'messageId' => $apiResult['messageId'] ?? '']);
            return true;
        }
        zinesh_audit('mail_brevo_api_failed', ['to' => $to, 'error' => $apiResult['error'] ?? '']);
    }

    if (zinesh_smtp_configured()) {
        $boundary = 'zinesh_' . bin2hex(random_bytes(8));
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= $textBody . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlBody . "\r\n\r\n";
        $body .= "--{$boundary}--";
        $mimeBody = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n" . $body;

        $result = zinesh_smtp_send(
            zinesh_mail_config()['smtp'],
            $fromEmail,
            $fromName,
            $to,
            $subject,
            $mimeBody,
            $replyTo
        );
        if ($result['ok']) {
            zinesh_audit('mail_sent_smtp', ['to' => $to]);
            return true;
        }
        zinesh_audit('mail_smtp_failed', ['to' => $to, 'error' => $result['error'] ?? 'unknown']);
    } else {
        zinesh_audit('mail_smtp_not_configured', ['to' => $to]);
    }

    zinesh_audit('mail_send_failed', ['to' => $to, 'reason' => 'no_transport']);
    return false;
}

function zinesh_send_verification_code_email(string $email, string $name, string $code): bool {
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

    $subject = 'Zinesh — E-posta doğrulama kodun';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="tr">
<body style="margin:0;padding:0;background:#06060a;font-family:Arial,sans-serif;color:#e4e4e7;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#06060a;padding:32px 16px;">
    <tr><td align="center">
      <table width="100%" style="max-width:520px;background:#0c0c12;border:1px solid #27272a;border-radius:16px;padding:32px;">
        <tr><td>
          <p style="margin:0 0 8px;font-size:11px;letter-spacing:0.12em;color:#a78bfa;text-transform:uppercase;">Zinesh Güven Protokolü</p>
          <p style="margin:0 0 16px;font-size:18px;line-height:1.5;color:#fff;font-weight:600;">
            Hoş geldin {$safeName}.
          </p>
          <p style="margin:0 0 20px;font-size:14px;line-height:1.7;color:#a1a1aa;">
            Zinesh'e katıldığın için teşekkür ederiz.
          </p>
          <p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#a1a1aa;text-align:center;">
            Hesabını doğrulamak için aşağıdaki kodu kullan:
          </p>
          <p style="margin:24px 0;text-align:center;font-size:36px;font-weight:bold;letter-spacing:0.35em;color:#f59e0b;font-family:monospace;">
            {$safeCode}
          </p>
          <p style="margin:0 0 16px;font-size:13px;line-height:1.6;color:#a1a1aa;text-align:center;">
            Bu kod 15 dakika boyunca geçerlidir.
          </p>
          <p style="margin:0;font-size:12px;line-height:1.6;color:#71717a;text-align:center;">
            Bu işlemi sen başlatmadıysan bu e-postayı dikkate alabilirsin.
          </p>
          <p style="margin:24px 0 0;font-size:11px;line-height:1.6;color:#52525b;text-align:center;">Zinesh</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $text = "Zinesh Güven Protokolü\n\n"
        . "Hoş geldin {$name}.\n\n"
        . "Zinesh'e katıldığın için teşekkür ederiz.\n\n"
        . "Hesabını doğrulamak için aşağıdaki kodu kullan:\n\n"
        . "{$code}\n\n"
        . "Bu kod 15 dakika boyunca geçerlidir.\n\n"
        . "Bu işlemi sen başlatmadıysan bu e-postayı dikkate alabilirsin.\n\n"
        . "Zinesh\n";

    $sent = zinesh_send_mail($email, $subject, $html, $text);
    zinesh_audit('email_verification_code_sent', ['email' => $email, 'sent' => $sent]);
    return $sent;
}

/** @deprecated Bağlantı tabanlı — yalnızca geri uyumluluk */
function zinesh_send_verification_email(string $email, string $name, string $plainToken): bool {
    $url = zinesh_email_verification_url($plainToken);
    $reward = (int)(zinesh_campaign_rewards_map()['founding_signup'] ?? 100);
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    $subject = 'Zinesh — E-posta adresini doğrula';
    $html = <<<HTML
<!DOCTYPE html>
<html lang="tr">
<body style="margin:0;padding:0;background:#06060a;font-family:Arial,sans-serif;color:#e4e4e7;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#06060a;padding:32px 16px;">
    <tr><td align="center">
      <table width="100%" style="max-width:520px;background:#0c0c12;border:1px solid #27272a;border-radius:16px;padding:32px;">
        <tr><td>
          <p style="margin:0 0 8px;font-size:11px;letter-spacing:0.12em;color:#a78bfa;text-transform:uppercase;">Zinesh Güven Protokolü</p>
          <h1 style="margin:0 0 16px;font-size:22px;color:#fff;">Merhaba {$safeName},</h1>
          <p style="margin:0 0 16px;font-size:14px;line-height:1.6;color:#a1a1aa;">
            Hesabın oluşturuldu. Kurucu görevlerinde her adım
            <strong style="color:#fbbf24;">+{$reward} FİZİ</strong>; beş görev bitince
            <strong style="color:#fbbf24;">500 FİZİ</strong> cüzdanına geçer. FİZİ uygulama içi avantajlar içindir.
          </p>
          <p style="margin:0 0 24px;font-size:14px;line-height:1.6;color:#a1a1aa;">
            Aşağıdaki butona tıkla — e-posta doğrulandıktan sonra görevlere başlayabilirsin.
          </p>
          <p style="margin:0 0 24px;text-align:center;">
            <a href="{$url}" style="display:inline-block;padding:14px 28px;background:#f59e0b;color:#000;text-decoration:none;font-weight:bold;border-radius:12px;font-size:14px;">
              E-postamı Doğrula
            </a>
          </p>
          <p style="margin:0 0 8px;font-size:12px;color:#71717a;">Bağlantı 24 saat geçerlidir. Buton çalışmazsa bu adresi kopyala:</p>
          <p style="margin:0;font-size:11px;word-break:break-all;color:#a78bfa;">{$url}</p>
        </td></tr>
      </table>
      <p style="margin:16px 0 0;font-size:11px;color:#52525b;">Bu e-postayı sen istemediysen görmezden gelebilirsin.</p>
    </td></tr>
  </table>
</body>
</html>
HTML;

    $text = "Merhaba {$name},\n\nZinesh hesabını doğrulamak için bağlantıya tıkla:\n{$url}\n\nKurucu görevlerde her adım +{$reward} FİZİ; beş görev bitince 500 FİZİ cüzdanına geçer.\n";

    $sent = zinesh_send_mail($email, $subject, $html, $text);
    zinesh_audit('email_verification_sent', ['email' => $email, 'sent' => $sent]);
    return $sent;
}

/**
 * @return array{ok:bool, reason:?string, user:?array, grant:?array}
 */
function zinesh_email_verify_code(string $uid, string $code): array {
    $code = preg_replace('/\D/', '', trim($code));
    if ($code !== '' && strlen($code) < 6) {
        $code = str_pad($code, 6, '0', STR_PAD_LEFT);
    }
    if ($code === '' || strlen($code) !== 6) {
        return ['ok' => false, 'reason' => 'invalid_code', 'user' => null, 'grant' => null];
    }

    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['ok' => false, 'reason' => 'user_not_found', 'user' => null, 'grant' => null];
    }

    if (!empty($user['emailVerified'])) {
        $grant = zinesh_campaign_try_grant_signup_reward($uid);
        $user = zinesh_find_user_by_uid($uid);
        return ['ok' => true, 'reason' => 'already_verified', 'user' => $user, 'grant' => $grant];
    }

    $hash = zinesh_email_verify_code_hash($code);
    $stored = (string)($user['emailVerifyCode'] ?? '');
    if ($stored === '' || !hash_equals($stored, $hash)) {
        return ['ok' => false, 'reason' => 'code_invalid', 'user' => null, 'grant' => null];
    }

    if ((int)($user['emailVerifyExpires'] ?? 0) < time()) {
        return ['ok' => false, 'reason' => 'code_expired', 'user' => null, 'grant' => null];
    }

    zinesh_update_user($uid, static function (array &$u) {
        $u['emailVerified'] = true;
        $u['emailVerifiedAt'] = date('c');
        unset($u['emailVerifyCode'], $u['emailVerifyToken'], $u['emailVerifyExpires']);
    });

    $grant = zinesh_campaign_try_grant_signup_reward($uid);
    $user = zinesh_find_user_by_uid($uid);
    zinesh_audit('email_verified_code', ['uid' => $uid, 'grant' => $grant['claimed'] ?? false]);

    return ['ok' => true, 'reason' => null, 'user' => $user, 'grant' => $grant];
}

/**
 * @return array{ok:bool, reason:?string, user:?array, grant:?array}
 */
function zinesh_email_verify_token(string $plainToken): array {
    return [
        'ok' => false,
        'reason' => 'link_disabled',
        'user' => null,
        'grant' => null,
    ];
}

function zinesh_email_resend_cooldown_remaining(array $user): int {
    $sentAt = strtotime((string)($user['emailVerifySentAt'] ?? ''));
    if ($sentAt <= 0) {
        return 0;
    }
    return max(0, 60 - (time() - $sentAt));
}

/**
 * @return array{sent:bool, message:string, mailSent:bool, retryAfter?:int}
 */
function zinesh_email_prepare_verification(string $uid, bool $skipCooldown = false): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['sent' => false, 'message' => 'Kullanıcı bulunamadı.', 'mailSent' => false];
    }
    if (!empty($user['emailVerified'])) {
        return ['sent' => false, 'message' => 'E-posta zaten doğrulanmış.', 'mailSent' => false];
    }

    $retryAfter = zinesh_email_resend_cooldown_remaining($user);
    if (!$skipCooldown && $retryAfter > 0) {
        return [
            'sent' => false,
            'message' => "Yeni kod için {$retryAfter} saniye bekle. E-postandaki son kodu kullan.",
            'mailSent' => false,
            'retryAfter' => $retryAfter,
        ];
    }

    $issued = zinesh_email_issue_verification_code();
    zinesh_update_user($uid, static function (array &$u) use ($issued) {
        $u['emailVerifyCode'] = $issued['hash'];
        $u['emailVerifyExpires'] = $issued['expires'];
        $u['emailVerifySentAt'] = date('c');
        unset($u['emailVerifyToken']);
    });

    $sent = zinesh_send_verification_code_email(
        (string)$user['email'],
        (string)($user['name'] ?? 'Üye'),
        $issued['plain']
    );

    if ($sent) {
        return [
            'sent' => true,
            'message' => 'Doğrulama kodu e-postana gönderildi. Gelen kutunu ve spam klasörünü kontrol et.',
            'mailSent' => true,
            'retryAfter' => 60,
        ];
    }

    return [
        'sent' => false,
        'message' => 'E-posta şu an gönderilemedi. Bir dakika sonra tekrar dene.',
        'mailSent' => false,
    ];
}

/** @return array{sent:bool, message:string, verifyLink:string, mailSent:bool} */
function zinesh_email_create_verification_link(string $uid): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['sent' => false, 'message' => 'Kullanıcı bulunamadı.', 'verifyLink' => '', 'mailSent' => false];
    }
    if (!empty($user['emailVerified'])) {
        return ['sent' => false, 'message' => 'E-posta zaten doğrulanmış.', 'verifyLink' => '', 'mailSent' => false];
    }
    return [
        'sent' => false,
        'message' => 'Doğrulama artık e-posta kodu ile yapılır. "Kod gönder" butonunu kullan.',
        'verifyLink' => '',
        'mailSent' => false,
    ];
}

/** @return array{sent:bool, message:string, mailSent:bool} */
function zinesh_email_resend_verification(string $uid): array {
    return zinesh_email_prepare_verification($uid);
}
