<?php
declare(strict_types=1);

function zinesh_client_ip(): string {
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return $ip;
}

function zinesh_rate_limit_retry_after_sec(): int {
    $limits = zinesh_config()['rate_limits'] ?? [];
    return max(5, min(60, (int)($limits['retry_after'] ?? 15)));
}

function zinesh_rate_limit_deny(string $bucket, string $ip, int $retryAfterSec): void {
    $safeBucket = preg_replace('/[^a-z0-9:_-]/i', '', $bucket) ?: 'default';
    if ($safeBucket !== 'global_api') {
        zinesh_audit('rate_limit', ['bucket' => $bucket, 'ip' => $ip]);
    }
    $wait = max(1, $retryAfterSec);
    if (!headers_sent()) {
        header('Retry-After: ' . (string)$wait);
    }
    zinesh_json_response([
        'ok' => false,
        'message' => 'Çok fazla istek. Lütfen ' . $wait . ' saniye bekleyin.',
        'retryAfter' => $wait,
    ], 429);
}

function zinesh_rate_limit(string $bucket, int $max, int $windowSec = 60): void {
    $ip = zinesh_client_ip();
    $safeBucket = preg_replace('/[^a-z0-9:_-]/i', '', $bucket) ?: 'default';
    $key = $safeBucket . ':' . hash('sha256', $ip);
    $now = time();
    $retryAfterSec = zinesh_rate_limit_retry_after_sec();
    $blocked = false;
    $retryWait = $retryAfterSec;

    // IP başına ayrı temp dosya — tek rate_limits.json dosyasına DDoS yükü bindirmez
    $dir = sys_get_temp_dir() . '/zinesh_rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $path = $dir . '/' . $key . '.json';
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return;
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            return;
        }
        $raw = stream_get_contents($fp);
        $entry = json_decode($raw ?: '{}', true);
        if (!is_array($entry)) {
            $entry = [];
        }

        $penaltyUntil = (int)($entry['penalty_until'] ?? 0);
        if ($penaltyUntil > $now) {
            $blocked = true;
            $retryWait = max(1, $penaltyUntil - $now);
        } else {
            if ($penaltyUntil > 0) {
                unset($entry['penalty_until']);
                $entry['count'] = min((int)($entry['count'] ?? 0), max(1, (int)floor($max * 0.85)));
            }
            if ($now > (int)($entry['reset'] ?? 0)) {
                $entry = ['count' => 0, 'reset' => $now + $windowSec];
            }
            $entry['count'] = (int)($entry['count'] ?? 0) + 1;
            if ($entry['count'] > $max) {
                $entry['penalty_until'] = $now + $retryAfterSec;
                $blocked = true;
                $retryWait = $retryAfterSec;
            }
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($entry));
        fflush($fp);
        flock($fp, LOCK_UN);
    } finally {
        fclose($fp);
    }

    if ($blocked) {
        zinesh_rate_limit_deny($bucket, $ip, $retryWait);
    }
}

/** Tüm API uçları için IP başına genel sınır (zinesh_security_headers içinde). */
function zinesh_api_global_rate_limit(): void {
    $limits = zinesh_config()['rate_limits'] ?? [];
    $max = (int)($limits['global_api'] ?? 180);
    if ($max <= 0) {
        return;
    }
    zinesh_rate_limit('global_api', $max, 60);
}

function zinesh_api_register_handlers(): void {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    set_exception_handler(static function (Throwable $e): void {
        error_log('zinesh_api_uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => false,
            'message' => 'Sunucu hatası. Lütfen kısa süre sonra tekrar deneyin.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($err['type'], $fatalTypes, true)) {
            return;
        }
        error_log('zinesh_api_fatal: ' . ($err['message'] ?? '') . ' @ ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''));
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => false,
            'message' => 'Sunucu hatası. Lütfen kısa süre sonra tekrar deneyin.',
        ], JSON_UNESCAPED_UNICODE);
    });
}

function zinesh_audit(string $action, array $meta = []): void {
    zinesh_json_atomic('audit_log.json', static function (array &$logs) use ($action, $meta) {
        array_unshift($logs, [
            'at' => date('c'),
            'action' => $action,
            'ip' => zinesh_client_ip(),
            'meta' => $meta,
        ]);
        $logs = array_slice($logs, 0, 5000);
        return true;
    });
}

/** Oturum replay bağlama: User-Agent özeti (IP hariç — mobil/CGNAT IP değişimi oturumu düşürmesin). */
function zinesh_client_fingerprint(): string {
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200);
    return hash('sha256', 'ua|' . $ua);
}

function zinesh_session_bind_fingerprint(): bool {
    return !empty(zinesh_config()['session_bind_fingerprint']);
}

function zinesh_session_touch_interval(): int {
    $seconds = (int)(zinesh_config()['session_touch_interval'] ?? 30);
    return max(5, min($seconds, 300));
}

function zinesh_session_rotate_seconds(): int {
    $seconds = (int)(zinesh_config()['session_rotate_seconds'] ?? 1800);
    return max(300, min($seconds, 86400));
}

function zinesh_validate_withdraw_address(string $network, string $address): ?string {
    $network = strtolower($network);
    $address = trim($address);
    if ($address === '') {
        return 'Adres gerekli.';
    }
    if ($network === 'tron') {
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return 'Geçersiz TRON adresi.';
        }
        return null;
    }
    if (in_array($network, ['arbitrum', 'ethereum'], true)) {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return 'Geçersiz EVM adresi (0x...).';
        }
        return null;
    }
    if ($network === 'solana') {
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
            return 'Geçersiz Solana adresi.';
        }
        return null;
    }
    return 'Desteklenmeyen ağ.';
}

function zinesh_infer_address_network(string $address): ?string {
    $address = trim($address);
    if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
        return 'tron';
    }
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
        return 'evm';
    }
    if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
        return 'solana';
    }
    return null;
}

function zinesh_address_compatible_network(string $network, string $address): ?string {
    $network = strtolower(trim($network));
    $address = trim($address);
    $inferred = zinesh_infer_address_network($address);
    if ($network === 'tron') {
        if ($inferred !== 'tron') {
            return 'TRON (TRC20) çekimi için T ile başlayan bir adres girin.';
        }
        return null;
    }
    if (in_array($network, ['arbitrum', 'ethereum'], true)) {
        if ($inferred !== 'evm') {
            return ucfirst($network) . ' çekimi için 0x ile başlayan bir EVM adresi girin.';
        }
        return null;
    }
    if ($network === 'solana') {
        if ($inferred !== 'solana') {
            return 'Solana çekimi için geçerli bir SPL cüzdan adresi girin.';
        }
        return null;
    }
    return 'Desteklenmeyen ağ.';
}

function zinesh_daily_withdraw_total(string $uid): float {
    return zinesh_daily_withdraw_total_from_list(zinesh_json_read('withdrawals.json'), $uid);
}

/** @param array<int,array<string,mixed>> $withdrawals */
function zinesh_daily_withdraw_total_from_list(array $withdrawals, string $uid): float {
    $today = date('Y-m-d');
    $total = 0.0;
    foreach ($withdrawals as $w) {
        if (($w['uid'] ?? '') !== $uid) {
            continue;
        }
        $status = (string)($w['status'] ?? '');
        if (!in_array($status, ['pending', 'pending_manual_review', 'processing', 'completed'], true)) {
            continue;
        }
        $created = substr((string)($w['createdAt'] ?? ''), 0, 10);
        if ($created === $today) {
            $total += (float)($w['amount'] ?? 0);
        }
    }
    return $total;
}

/**
 * Çekim kaydı ekle — günlük limit kontrolü aynı kilit altında.
 *
 * @param array<string,mixed> $entry
 * @return array{ok:bool, reason?:string, entry?:array<string,mixed>}
 */
function zinesh_withdrawals_append_atomic(string $uid, array $entry, float $dailyLimit): array {
    $result = ['ok' => false, 'reason' => 'limit'];

    zinesh_json_atomic('withdrawals.json', static function (array &$withdrawals) use ($uid, $entry, $dailyLimit, &$result) {
        $amount = (float)($entry['amount'] ?? 0);
        if ($amount <= 0) {
            $result = ['ok' => false, 'reason' => 'invalid_amount'];
            return false;
        }

        $todayTotal = zinesh_daily_withdraw_total_from_list($withdrawals, $uid);
        if ($todayTotal + $amount > $dailyLimit + 1e-9) {
            $result = ['ok' => false, 'reason' => 'daily_limit'];
            return false;
        }

        $withdrawals[] = $entry;
        $result = ['ok' => true, 'entry' => $entry];
        return true;
    });

    return $result;
}

function zinesh_server_secrets_path(): string {
    return zinesh_data_path('server_secrets.json');
}

function zinesh_load_server_secrets(): array {
    $path = zinesh_server_secrets_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/** PHP-FPM (www-data) SMTP/API anahtarlarını okuyabilsin; dışarıya kapalı kalsın. */
function zinesh_secure_secrets_file(string $path): void {
    if (!is_file($path)) {
        return;
    }
    @chmod($path, 0660);
    if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
        return;
    }
    foreach (['www-data', 'www'] as $user) {
        $info = posix_getpwnam($user);
        if ($info === false) {
            continue;
        }
        @chown($path, (int)$info['uid']);
        @chgrp($path, (int)$info['gid']);
        break;
    }
}

function zinesh_admin_secret(): string {
    $s = zinesh_load_server_secrets();
    if (!empty($s['admin_secret'])) {
        return (string)$s['admin_secret'];
    }
    return (string)(zinesh_config()['admin_secret'] ?? '');
}

function zinesh_cron_secret(): string {
    $s = zinesh_load_server_secrets();
    if (!empty($s['cron_key'])) {
        return (string)$s['cron_key'];
    }
    return (string)(zinesh_config()['cron_key'] ?? zinesh_admin_secret());
}

function zinesh_revoke_session(string $token): void {
    if ($token === '') {
        return;
    }
    zinesh_json_atomic('sessions.json', function (array &$sessions) use ($token) {
        unset($sessions[$token]);
        return true;
    });
    if (function_exists('zinesh_clear_session_cookie')) {
        zinesh_clear_session_cookie();
    }
}

/** SPA + admin paneli için oturum çerezi (mobil yedek). */
function zinesh_emit_session_cookie(string $token): void {
    if ($token === '' || headers_sent()) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $params = [
        'expires' => time() + (function_exists('zinesh_session_max_seconds')
            ? zinesh_session_max_seconds()
            : 60 * 60 * 24 * 30),
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ];
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === 'zinesh.com' || str_ends_with($host, '.zinesh.com')) {
        $params['domain'] = '.zinesh.com';
    }
    setcookie('zinesh_session', $token, $params);
}

function zinesh_clear_session_cookie(): void {
    if (headers_sent()) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $base = [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
    ];
    setcookie('zinesh_session', '', $base);
    $withDomain = $base;
    $withDomain['domain'] = '.zinesh.com';
    setcookie('zinesh_session', '', $withDomain);
}

const ZINESH_LOGIN_FAIL_WINDOW_SEC = 900;
const ZINESH_LOGIN_FAIL_THRESHOLD = 3;
const ZINESH_LOGIN_LOCK_TTL_SEC = 2;
const ZINESH_LOGIN_VERIFY_CODE_TTL_SEC = 900;
const ZINESH_LOGIN_VERIFY_EMAIL_COOLDOWN_SEC = 300;

function zinesh_login_email_hash(string $email): string {
    return hash('sha256', strtolower(trim($email)));
}

function zinesh_login_lock_key(string $email): string {
    return 'login_lock_' . strtolower(trim($email));
}

/** Paralel giriş isteğini engelle (TTL 2 sn, atomic store + flock). */
function zinesh_login_lock_acquire(string $email): bool {
    $key = zinesh_login_lock_key($email);
    $now = time();
    $acquired = false;

    zinesh_json_atomic('login_locks.json', static function (array &$locks) use ($key, $now, &$acquired) {
        $expires = (int)($locks[$key] ?? 0);
        if ($expires > $now) {
            $acquired = false;
            return false;
        }
        $locks[$key] = $now + ZINESH_LOGIN_LOCK_TTL_SEC;
        $acquired = true;
        return true;
    });

    return $acquired;
}

function zinesh_login_lock_release(string $email): void {
    $key = zinesh_login_lock_key($email);
    zinesh_json_atomic('login_locks.json', static function (array &$locks) use ($key) {
        unset($locks[$key]);
        return true;
    });
}

function zinesh_login_attempts_key(string $email): string {
    return zinesh_login_email_hash($email);
}

/**
 * @return array{failTimes: int[], login_verification_required: bool, codeHash?: string, codeExpires?: int, codeSentAt?: string, verification_last_sent_at?: int}
 */
function zinesh_login_attempts_get(string $email): array {
    $key = zinesh_login_attempts_key($email);
    $store = zinesh_json_read('login_attempts.json');
    $entry = is_array($store[$key] ?? null) ? $store[$key] : [];
    $now = time();
    $failTimes = array_values(array_filter(
        (array)($entry['failTimes'] ?? []),
        static fn($t) => $now - (int)$t < ZINESH_LOGIN_FAIL_WINDOW_SEC
    ));
    $verificationRequired = !empty($entry['login_verification_required'])
        || !empty($entry['verificationRequired']);
    $codeExpires = (int)($entry['codeExpires'] ?? 0);
    $lastSentAt = (int)($entry['verification_last_sent_at'] ?? 0);
    if ($lastSentAt <= 0) {
        $lastSentAt = strtotime((string)($entry['codeSentAt'] ?? '')) ?: 0;
    }

    if ($verificationRequired && $codeExpires > 0 && $codeExpires < $now) {
        $verificationRequired = false;
        $entry['login_verification_required'] = false;
        unset($entry['verificationRequired'], $entry['codeHash'], $entry['codeExpires'], $entry['codeSentAt']);
        zinesh_login_attempts_save($email, array_merge($entry, ['failTimes' => $failTimes]));
    }

    return [
        'failTimes' => $failTimes,
        'login_verification_required' => $verificationRequired,
        'codeHash' => (string)($entry['codeHash'] ?? ''),
        'codeExpires' => $codeExpires,
        'codeSentAt' => (string)($entry['codeSentAt'] ?? ''),
        'verification_last_sent_at' => $lastSentAt,
    ];
}

function zinesh_login_attempts_save(string $email, array $entry): void {
    $key = zinesh_login_attempts_key($email);
    zinesh_json_atomic('login_attempts.json', static function (array &$store) use ($key, $entry) {
        $store[$key] = $entry;
        return true;
    });
}

function zinesh_login_clear_failures(string $email): void {
    $key = zinesh_login_attempts_key($email);
    zinesh_json_atomic('login_attempts.json', static function (array &$store) use ($key) {
        unset($store[$key]);
        return true;
    });
}

function zinesh_login_requires_verification(string $email): bool {
    $entry = zinesh_login_attempts_get($email);
    return !empty($entry['login_verification_required']);
}

function zinesh_login_send_verification_email(string $email, string $name, string $code): bool {
    require_once __DIR__ . '/email_lib.php';
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = 'Zinesh — Giriş doğrulama kodun';
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
            Hesabında ardışık hatalı giriş denemeleri algılandı. Devam etmek için aşağıdaki 6 haneli kodu gir.
          </p>
          <p style="margin:24px 0;text-align:center;font-size:36px;font-weight:bold;letter-spacing:0.35em;color:#f59e0b;font-family:monospace;">
            {$safeCode}
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
    $text = "Merhaba {$name},\n\nGiriş doğrulama kodun: {$code}\n\nKod 15 dakika geçerlidir.\n";
    $sent = zinesh_send_mail($email, $subject, $html, $text);
    zinesh_audit('login_verification_code_sent', ['email' => $email, 'sent' => $sent]);
    return $sent;
}

function zinesh_login_verification_email_cooldown_remaining(array $entry): int {
    $lastSent = (int)($entry['verification_last_sent_at'] ?? 0);
    if ($lastSent <= 0) {
        $lastSent = strtotime((string)($entry['codeSentAt'] ?? '')) ?: 0;
    }
    if ($lastSent <= 0) {
        return 0;
    }
    return max(0, ZINESH_LOGIN_VERIFY_EMAIL_COOLDOWN_SEC - (time() - $lastSent));
}

/**
 * @return array{ok:bool, cooldown?:bool, mailSent?:bool, message?:string}
 */
function zinesh_login_send_verification_code(string $email, array &$entry): array {
    require_once __DIR__ . '/email_lib.php';
    require_once __DIR__ . '/password_reset_lib.php';

    $cooldown = zinesh_login_verification_email_cooldown_remaining($entry);
    if ($cooldown > 0) {
        return [
            'ok' => false,
            'cooldown' => true,
            'message' => 'Doğrulama kodu zaten gönderildi. Lütfen e-postanızı kontrol edin.',
        ];
    }

    $user = zinesh_find_user_by_email($email);
    if (!$user) {
        return ['ok' => true, 'mailSent' => false];
    }

    $issued = zinesh_email_issue_verification_code();
    $entry['codeHash'] = $issued['hash'];
    $entry['codeExpires'] = time() + ZINESH_LOGIN_VERIFY_CODE_TTL_SEC;
    $entry['codeSentAt'] = date('c');
    $entry['verification_last_sent_at'] = time();

    $mailSent = zinesh_login_send_verification_email(
        (string)$user['email'],
        (string)($user['name'] ?? 'Üye'),
        $issued['plain']
    );

    if (!$mailSent) {
        return [
            'ok' => false,
            'message' => 'Doğrulama kodu e-postası gönderilemedi. Bir dakika sonra tekrar dene.',
            'mailSent' => false,
        ];
    }

    return ['ok' => true, 'mailSent' => true];
}

/**
 * @return array{ok:bool, message?:string, reason?:string}
 */
function zinesh_login_verify_code(string $email, string $code): array {
    require_once __DIR__ . '/email_lib.php';
    $entry = zinesh_login_attempts_get($email);
    if (empty($entry['login_verification_required'])) {
        return ['ok' => true];
    }

    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return ['ok' => false, 'reason' => 'invalid_code', 'message' => 'Geçerli 6 haneli kod gir.'];
    }

    $expires = (int)($entry['codeExpires'] ?? 0);
    if ($expires < time()) {
        return ['ok' => false, 'reason' => 'code_expired', 'message' => 'Doğrulama kodunun süresi doldu (15 dakika).'];
    }

    $hash = (string)($entry['codeHash'] ?? '');
    if ($hash === '' || !hash_equals($hash, zinesh_email_verify_code_hash($code))) {
        return ['ok' => false, 'reason' => 'code_invalid', 'message' => 'Doğrulama kodu hatalı.'];
    }

    zinesh_login_clear_failures($email);
    return ['ok' => true];
}

/**
 * @return array{requiresVerification:bool, emailCooldown?:bool, message?:string, mailSent?:bool}
 */
function zinesh_login_record_failure(string $email): array {
    $now = time();
    $entry = zinesh_login_attempts_get($email);
    $failTimes = $entry['failTimes'];
    $failTimes[] = $now;
    $entry['failTimes'] = $failTimes;

    if (count($failTimes) < ZINESH_LOGIN_FAIL_THRESHOLD) {
        zinesh_login_attempts_save($email, $entry);
        return ['requiresVerification' => false];
    }

    $entry['login_verification_required'] = true;
    unset($entry['verificationRequired']);

    $send = zinesh_login_send_verification_code($email, $entry);
    if (!empty($send['cooldown'])) {
        zinesh_login_attempts_save($email, $entry);
        return [
            'requiresVerification' => true,
            'emailCooldown' => true,
            'message' => $send['message'] ?? 'Doğrulama kodu zaten gönderildi. Lütfen e-postanızı kontrol edin.',
        ];
    }

    if (!$send['ok']) {
        // Mail altyapısı yokken hesabı doğrulama kapısına kilitleme.
        $entry['login_verification_required'] = false;
        unset(
            $entry['verificationRequired'],
            $entry['codeHash'],
            $entry['codeExpires'],
            $entry['codeSentAt'],
            $entry['verification_last_sent_at']
        );
        zinesh_login_attempts_save($email, $entry);
        zinesh_audit('login_verification_mail_failed', [
            'email' => $email,
            'message' => $send['message'] ?? '',
        ]);
        return [
            'requiresVerification' => false,
            'message' => 'E-posta veya şifre hatalı.',
            'mailSent' => false,
        ];
    }

    zinesh_login_attempts_save($email, $entry);

    return [
        'requiresVerification' => true,
        'message' => 'E-posta doğrulaması gerekli.',
        'mailSent' => $send['mailSent'] ?? false,
    ];
}

function zinesh_security_headers(): void {
    zinesh_api_register_handlers();
    zinesh_api_global_rate_limit();
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}

function zinesh_admin_cookie_name(): string {
    return 'zinesh_admin_sess';
}

function zinesh_admin_sign(int $expires): string {
    return hash_hmac('sha256', (string)$expires, zinesh_admin_secret());
}

function zinesh_admin_set_cookie(): void {
    $secret = zinesh_admin_secret();
    if ($secret === '') {
        return;
    }
    $exp = time() + 3600;
    $sig = zinesh_admin_sign($exp);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(zinesh_admin_cookie_name(), $exp . '.' . $sig, [
        'expires' => $exp,
        'path' => '/api',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function zinesh_admin_cookie_valid(): bool {
    $raw = (string)($_COOKIE[zinesh_admin_cookie_name()] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $raw, $m)) {
        return false;
    }
    $exp = (int)$m[1];
    if ($exp < time()) {
        return false;
    }
    return hash_equals(zinesh_admin_sign($exp), $m[2]);
}

function zinesh_admin_try_header(): bool {
    $secret = zinesh_admin_secret();
    if ($secret === '') {
        return false;
    }
    $header = trim((string)($_SERVER['HTTP_X_ADMIN_KEY'] ?? ''));
    if ($header !== '' && hash_equals($secret, $header)) {
        zinesh_admin_set_cookie();
        return true;
    }
    return false;
}

function zinesh_admin_is_authenticated(): bool {
    return zinesh_admin_try_header() || zinesh_admin_cookie_valid();
}

function zinesh_admin_login_form(string $error = ''): void {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    echo '<!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Kurucu yönetimi</title></head><body style="font-family:system-ui;background:#0a0a0f;color:#e4e4e7;padding:40px;max-width:480px;margin:0 auto;">';
    echo '<h1>Kurucu yönetimi</h1>';
    echo '<p style="color:#a1a1aa;font-size:14px;line-height:1.5;">Ayrı admin hesabı yok. Önce <a href="https://www.zinesh.com/" style="color:#86efac;">zinesh.com</a> üzerinden kurucu hesabınla giriş yap, sonra bu sayfayı yenile. Acil durum için yedek anahtar kullanılabilir.</p>';
    if ($error !== '') {
        echo '<p style="color:#fca5a5;">' . htmlspecialchars($error) . '</p>';
    }
    echo '<form method="post"><label>Yedek anahtar (opsiyonel)</label><br><input type="password" name="admin_login_key" style="width:100%;margin:8px 0;padding:8px;"><button type="submit" style="padding:8px 16px;">Anahtar ile giriş</button></form>';
    echo '</body></html>';
    exit;
}

/** Kurucu site oturumu ile admin paneline izin ver (tek hesap). */
function zinesh_admin_try_founder_session(): bool {
    if (!function_exists('zinesh_validate_session_token') || !function_exists('zinesh_is_founder')) {
        return false;
    }
    $token = trim((string)($_COOKIE['zinesh_session'] ?? ''));
    if ($token === '') {
        $token = trim((string)($_SERVER['HTTP_X_SESSION_TOKEN'] ?? ''));
    }
    if ($token === '') {
        $authHeader = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
            $token = trim($m[1]);
        }
    }
    if ($token === '' && isset($_GET['sessionToken'])) {
        $token = trim((string)$_GET['sessionToken']);
    }
    if ($token === '') {
        return false;
    }
    $validated = zinesh_validate_session_token($token, false);
    if (!($validated['ok'] ?? false)) {
        return false;
    }
    $user = function_exists('zinesh_find_user_by_uid')
        ? zinesh_find_user_by_uid((string)($validated['uid'] ?? ''))
        : null;
    if (!$user || !zinesh_is_founder($user)) {
        return false;
    }
    if (!zinesh_admin_cookie_valid()) {
        zinesh_admin_set_cookie();
    }
    return true;
}

/** HTML admin paneli — kurucu oturumu veya yedek anahtar */
function zinesh_admin_require_html(): void {
    $secret = zinesh_admin_secret();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login_key'])) {
        $key = (string)$_POST['admin_login_key'];
        if ($secret !== '' && hash_equals($secret, $key)) {
            zinesh_admin_set_cookie();
            header('Location: admin.php');
            exit;
        }
        zinesh_admin_login_form('Geçersiz anahtar.');
    }

    if (zinesh_admin_is_authenticated() || zinesh_admin_try_founder_session()) {
        return;
    }

    zinesh_admin_login_form();
}

function zinesh_admin_redirect(string $query = ''): void {
    $url = 'admin.php';
    if ($query !== '') {
        $url .= '?' . ltrim($query, '?');
    }
    header('Location: ' . $url);
    exit;
}

function zinesh_admin_csrf_token(): string {
    $secret = zinesh_admin_secret();
    if ($secret === '') {
        return '';
    }
    $exp = time() + 3600;
    $sig = hash_hmac('sha256', 'admin_csrf_' . $exp, $secret);
    return $exp . '.' . $sig;
}

function zinesh_admin_csrf_field(string $token): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/** POST işlemlerinde CSRF doğrula (giriş formu hariç). */
function zinesh_admin_verify_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    if (isset($_POST['admin_login_key'])) {
        return;
    }
    $token = (string)($_POST['csrf'] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $token, $m)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'CSRF token geçersiz.';
        exit;
    }
    $exp = (int)$m[1];
    if ($exp < time()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'CSRF token süresi dolmuş. Sayfayı yenileyin.';
        exit;
    }
    $expected = hash_hmac('sha256', 'admin_csrf_' . $exp, zinesh_admin_secret());
    if (!hash_equals($expected, $m[2])) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'CSRF token geçersiz.';
        exit;
    }
}
