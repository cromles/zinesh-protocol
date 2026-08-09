<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/protocol_constants.php';

const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c04a063c689ca5516e53f';

function zinesh_load_users(): array {
    return zinesh_json_read('users.json');
}

function zinesh_save_users(array $users): void {
    zinesh_json_write('users.json', $users);
}

/**
 * Eksik veri dosyalarını oluşturur (yeni sunucu / taşıma sonrası).
 */
function zinesh_ensure_core_data_files(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    $defaults = [
        'users.json' => [],
        'sessions.json' => [],
        'withdrawals.json' => [],
        'escrow_rooms.json' => [],
        'escrow_room_messages.json' => [],
        'zinesh_events.json' => [],
        'contract_versions.json' => [],
        'havale_pending.json' => [],
    ];

    foreach ($defaults as $file => $default) {
        $path = zinesh_data_path($file);
        if (!file_exists($path)) {
            zinesh_json_write($file, $default);
        }
    }

    $backupPath = zinesh_data_path('backup_health.json');
    if (!file_exists($backupPath)) {
        zinesh_json_write('backup_health.json', [
            'source' => 'Sunucu yerel yedek',
            'mode' => 'local',
            'localBackupOnly' => true,
            'gdrive' => false,
            'gdriveConnected' => false,
            'lastBackup' => date('Y-m-d H:i'),
            'note' => 'İlk kurulum — Google Drive isteğe bağlı.',
        ]);
    }
}

function zinesh_find_user_by_uid(string $uid): ?array {
    foreach (zinesh_load_users() as $u) {
        if (($u['uid'] ?? '') === $uid) return $u;
    }
    return null;
}

function zinesh_find_user_index(string $uid): ?int {
    foreach (zinesh_load_users() as $i => $u) {
        if (($u['uid'] ?? '') === $uid) return $i;
    }
    return null;
}

/** WhatsApp/kopyala-yapıştır kaynaklı boşluk ve tire varyantlarını tek forma getirir (4–6 hane). */
function zinesh_member_ticket_digits(string $raw): string {
    $t = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $raw) ?? $raw;
    $t = strtoupper(trim($t));
    if ($t === '') {
        return '';
    }
    $t = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}", '–', '—', '−'], '-', $t);
    $t = preg_replace('/[\s_]+/', '', $t) ?? $t;
    $t = preg_replace('/-+/', '-', $t) ?? $t;

    if (preg_match('/^ZN-SH-(?:WEB3|REAL|DUAL)-(\d{4,6})$/', $t, $m)) {
        return $m[1];
    }
    if (preg_match('/^ZNSH(?:WEB3|REAL|DUAL)(\d{4,6})$/', $t, $m)) {
        return $m[1];
    }
    if (preg_match('/^(?:WEB3|REAL|DUAL)-(\d{4,6})$/', $t, $m)) {
        return $m[1];
    }
    if (preg_match('/^\d{4,6}$/', $t)) {
        return $t;
    }
    $digits = preg_replace('/\D/', '', $t) ?? '';
    if (preg_match('/^\d{4,6}$/', $digits)) {
        return $digits;
    }
    return '';
}

function zinesh_normalize_member_ticket(string $raw): string {
    return zinesh_member_ticket_digits($raw);
}

function zinesh_generate_member_ticket(array $user): string {
    unset($user);
    for ($i = 0; $i < 60; $i++) {
        $candidate = (string) random_int(10000, 99999);
        if (zinesh_find_user_by_ticket($candidate) === null) {
            return $candidate;
        }
    }
    return (string) random_int(100000, 999999);
}

/** Eksik veya bozuk üye numarasını düzeltir; yeni atandıysa veya eski formattan dönüştürüldüyse true. */
function zinesh_ensure_user_ticket(array &$user): bool {
    $existing = zinesh_member_ticket_digits((string)($user['ticketNumber'] ?? ''));
    if ($existing !== '') {
        if ((string)($user['ticketNumber'] ?? '') !== $existing) {
            $user['ticketNumber'] = $existing;
            return true;
        }
        return false;
    }
    $user['ticketNumber'] = zinesh_generate_member_ticket($user);
    return true;
}

/** @return array<string,mixed>|null */
function zinesh_persist_user_ticket_if_needed(string $uid): ?array {
    $updated = null;
    zinesh_json_atomic('users.json', static function (array &$users) use ($uid, &$updated) {
        foreach ($users as $i => $u) {
            if (!is_array($u) || ($u['uid'] ?? '') !== $uid) {
                continue;
            }
            if (!zinesh_ensure_user_ticket($users[$i])) {
                return false;
            }
            $updated = $users[$i];
            return true;
        }
        return false;
    });
    return $updated;
}

/** Eski hesaplarda eksik üye numaralarını tek seferde tamamlar. */
function zinesh_backfill_missing_user_tickets(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    zinesh_json_atomic('users.json', static function (array &$users) {
        $changed = false;
        foreach ($users as $i => $u) {
            if (!is_array($u)) {
                continue;
            }
            if (zinesh_ensure_user_ticket($users[$i])) {
                $changed = true;
            }
        }
        return $changed;
    });
}

function zinesh_looks_like_referral_code(string $raw): bool {
    $t = strtoupper(preg_replace('/[\s_-]+/', '', trim($raw)) ?? '');
    if ($t === '' || str_starts_with($t, 'ZN-SH') || str_starts_with($t, 'ZNSH')) {
        return false;
    }
    if (preg_match('/^\d{4,6}$/', $t)) {
        return false;
    }
    return (bool)preg_match('/^[A-Z0-9]{6,12}$/', $t);
}

function zinesh_find_user_by_ticket(string $ticket): ?array {
    $ticket = zinesh_normalize_member_ticket($ticket);
    if ($ticket === '') {
        return null;
    }
    foreach (zinesh_load_users() as $u) {
        $stored = zinesh_normalize_member_ticket((string)($u['ticketNumber'] ?? ''));
        if ($stored !== '' && $stored === $ticket) {
            return $u;
        }
    }
    return null;
}

function zinesh_public_user(array $user): array {
    if (function_exists('zinesh_email_sanitize_public_user')) {
        return zinesh_email_sanitize_public_user($user);
    }
    unset($user['passwordHash'], $user['emailVerifyToken'], $user['emailVerifyExpires'], $user['pendingReferralCode']);
    return $user;
}

function zinesh_ensure_wallet_fields(array &$user): void {
    $user['usdtBalance'] = isset($user['usdtBalance']) ? (float)$user['usdtBalance'] : 0.0;
    $user['fiziBalance'] = isset($user['fiziBalance']) ? (float)$user['fiziBalance'] : 0.0;
    $user['campaignFiziBalance'] = isset($user['campaignFiziBalance']) ? (float)$user['campaignFiziBalance'] : 0.0;
    $user['platformFiziBalance'] = isset($user['platformFiziBalance']) ? (float)$user['platformFiziBalance'] : 0.0;
    $user['purchasedFiziBalance'] = isset($user['purchasedFiziBalance']) ? (float)$user['purchasedFiziBalance'] : null;
    $user['escrowBalance'] = isset($user['escrowBalance']) ? (float)$user['escrowBalance'] : 0.0;
    $user['vitrinPurchasedFizi'] = isset($user['vitrinPurchasedFizi']) ? (float)$user['vitrinPurchasedFizi'] : 0.0;
    if (!isset($user['connectedWallets']) || !is_array($user['connectedWallets'])) {
        $user['connectedWallets'] = [];
    }
}

function zinesh_revoke_all_user_sessions(string $uid): void {
    if ($uid === '') {
        return;
    }
    zinesh_json_atomic('sessions.json', static function (array &$sessions) use ($uid) {
        foreach (array_keys($sessions) as $token) {
            if (($sessions[$token]['uid'] ?? '') === $uid) {
                unset($sessions[$token]);
            }
        }
        return true;
    });
}

/** Hareketsizlik zaman aşımı (saniye) — config.local.php ile test için 10 yapılabilir */
function zinesh_session_idle_seconds(): int {
    $seconds = (int)(zinesh_config()['session_idle_seconds'] ?? 300);
    if ($seconds <= 0) {
        return 0;
    }
    return max(10, min($seconds, 86400));
}

/** Token mutlak ömrü (saniye) */
function zinesh_session_max_seconds(): int {
    $seconds = (int)(zinesh_config()['session_max_seconds'] ?? 60 * 60 * 24 * 14);
    return max(600, min($seconds, 60 * 60 * 24 * 90));
}

/** @param array<string,mixed> $session */
function zinesh_session_last_activity(array $session): int {
    if (isset($session['lastActivity']) && is_numeric($session['lastActivity'])) {
        return (int)$session['lastActivity'];
    }
    if (!empty($session['createdAt'])) {
        $parsed = strtotime((string)$session['createdAt']);
        if ($parsed !== false) {
            return $parsed;
        }
    }
    return 0;
}

/** @param array<string,mixed> $session */
function zinesh_session_is_active(array $session, ?int $now = null): bool {
    $now = $now ?? time();
    if (($session['expires'] ?? 0) < $now) {
        return false;
    }
    $last = zinesh_session_last_activity($session);
    if ($last <= 0) {
        return true;
    }
    $idle = zinesh_session_idle_seconds();
    if ($idle <= 0) {
        return true;
    }
    return ($now - $last) <= $idle;
}

/**
 * Token doğrula; geçerliyse lastActivity güncelle (touch).
 * Önce salt okuma — kilit/yazma hatası geçerli oturumu düşürmez.
 *
 * @return array{ok:bool, uid?:string, code?:string}
 */
function zinesh_validate_session_token(string $token, bool $touch = true): array {
    if ($token === '') {
        return ['ok' => false, 'code' => 'missing'];
    }

    $idle = zinesh_session_idle_seconds();
    $touchInterval = zinesh_session_touch_interval();
    $now = time();

    $sessions = zinesh_json_read('sessions.json');
    $session = is_array($sessions[$token] ?? null) ? $sessions[$token] : null;
    if (!is_array($session)) {
        return ['ok' => false, 'code' => 'missing'];
    }

    if (($session['expires'] ?? 0) < $now) {
        try {
            zinesh_json_atomic('sessions.json', static function (array &$rows) use ($token, $now): bool {
                if (isset($rows[$token]) && (($rows[$token]['expires'] ?? 0) < $now)) {
                    unset($rows[$token]);
                    return true;
                }
                return false;
            });
        } catch (Throwable) {
            /* ignore */
        }
        return ['ok' => false, 'code' => 'session_expired'];
    }

    $last = zinesh_session_last_activity($session);
    if ($idle > 0 && $last > 0 && ($now - $last) > $idle) {
        try {
            zinesh_json_atomic('sessions.json', static function (array &$rows) use ($token, $idle, $now): bool {
                $s = $rows[$token] ?? null;
                if (!is_array($s)) {
                    return false;
                }
                $l = zinesh_session_last_activity($s);
                if ($l > 0 && ($now - $l) > $idle) {
                    unset($rows[$token]);
                    return true;
                }
                return false;
            });
        } catch (Throwable) {
            /* ignore */
        }
        return ['ok' => false, 'code' => 'session_expired'];
    }

    $uid = (string)($session['uid'] ?? '');
    if ($uid === '') {
        return ['ok' => false, 'code' => 'missing'];
    }

    $needsTouch = $touch && ($now - $last) >= $touchInterval;
    // Mobil: her doğrulamada süreyi kaydır — arka plana alınca oturum düşmesin.
    $needsSlide = ($session['expires'] ?? 0) < ($now + (zinesh_session_max_seconds() / 2));
    if ($needsTouch || $needsSlide) {
        try {
            zinesh_json_atomic('sessions.json', static function (array &$rows) use ($token, $now, $needsTouch): bool {
                if (!isset($rows[$token]) || !is_array($rows[$token])) {
                    return false;
                }
                if ($needsTouch) {
                    $rows[$token]['lastActivity'] = $now;
                }
                $rows[$token]['expires'] = $now + zinesh_session_max_seconds();
                return true;
            });
        } catch (Throwable) {
            /* touch/slide başarısız olsa da oturum geçerli sayılır */
        }
    }

    return ['ok' => true, 'uid' => $uid];
}

/**
 * Aynı kullanıcı için varsa mevcut oturumu döndür, yoksa yeni oluştur.
 * Kurucu paneli sürekli yeni token üretip kendini düşürmesin.
 */
function zinesh_ensure_session(string $uid, ?string $preferredToken = null): string {
    if ($preferredToken) {
        $validated = zinesh_validate_session_token($preferredToken, true);
        if (($validated['ok'] ?? false) && (string)($validated['uid'] ?? '') === $uid) {
            return $preferredToken;
        }
    }
    $existing = zinesh_find_active_session_token_for_uid($uid);
    if ($existing !== null && $existing !== '') {
        return $existing;
    }
    return zinesh_create_session($uid);
}

function zinesh_create_session(string $uid): string {
    $token = bin2hex(random_bytes(32));
    $now = time();
    $fp = zinesh_session_bind_fingerprint() ? zinesh_client_fingerprint() : '';
    zinesh_json_atomic('sessions.json', function (array &$sessions) use ($uid, $token, $now, $fp) {
        $sessions[$token] = [
            'uid' => $uid,
            'expires' => $now + zinesh_session_max_seconds(),
            'lastActivity' => $now,
            'issuedAt' => $now,
            'ip' => zinesh_client_ip(),
            'fp' => $fp,
            'createdAt' => date('c'),
        ];
        return true;
    });
    return $token;
}

/**
 * Token rotasyonu — eski token silinir, yeni döner (replay penceresini kapatır).
 * Eşzamanlı rotasyonda eski token yoksa null döner (çağıran yeni oturum açmalı).
 */
function zinesh_rotate_session_token(string $oldToken): ?string {
    if ($oldToken === '') {
        return null;
    }
    $newToken = bin2hex(random_bytes(32));
    $now = time();
    $fp = zinesh_session_bind_fingerprint() ? zinesh_client_fingerprint() : '';
    $rotated = zinesh_json_atomic('sessions.json', static function (array &$sessions) use ($oldToken, $newToken, $now, $fp) {
        $session = $sessions[$oldToken] ?? null;
        if (!is_array($session)) {
            return false;
        }
        $uid = (string)($session['uid'] ?? '');
        if ($uid === '') {
            unset($sessions[$oldToken]);
            return false;
        }
        unset($sessions[$oldToken]);
        $sessions[$newToken] = [
            'uid' => $uid,
            'expires' => (int)($session['expires'] ?? ($now + zinesh_session_max_seconds())),
            'lastActivity' => $now,
            'issuedAt' => $now,
            'ip' => zinesh_client_ip(),
            'fp' => $fp !== '' ? $fp : (string)($session['fp'] ?? ''),
            'createdAt' => date('c'),
            'prevToken' => substr($oldToken, 0, 16),
        ];
        return true;
    });
    return $rotated === true ? $newToken : null;
}

/** Aynı uid için hâlâ geçerli bir oturum token'ı bul (eşzamanlı rotasyon sonrası). */
function zinesh_find_active_session_token_for_uid(string $uid): ?string {
    if ($uid === '') {
        return null;
    }
    $sessions = zinesh_json_read('sessions.json');
    if (!is_array($sessions)) {
        return null;
    }
    $bestToken = null;
    $bestIssued = 0;
    $now = time();
    foreach ($sessions as $token => $session) {
        if (!is_array($session) || (string)($session['uid'] ?? '') !== $uid) {
            continue;
        }
        if (!zinesh_session_is_active($session, $now)) {
            continue;
        }
        $issued = (int)($session['issuedAt'] ?? $session['lastActivity'] ?? 0);
        if ($issued >= $bestIssued) {
            $bestIssued = $issued;
            $bestToken = (string)$token;
        }
    }
    return $bestToken;
}

/** Oturum yenilemede periyodik rotasyon gerekli mi? */
function zinesh_session_should_rotate(string $token): bool {
    if ($token === '') {
        return false;
    }
    $sessions = zinesh_json_read('sessions.json');
    $session = $sessions[$token] ?? null;
    if (!is_array($session)) {
        return false;
    }
    $issuedAt = (int)($session['issuedAt'] ?? 0);
    if ($issuedAt <= 0) {
        $issuedAt = zinesh_session_last_activity($session);
    }
    return (time() - $issuedAt) >= zinesh_session_rotate_seconds();
}

function zinesh_uid_from_token(?string $token, bool $touch = true): ?string {
    if (!$token) {
        return null;
    }
    $validated = zinesh_validate_session_token($token, $touch);
    return ($validated['ok'] ?? false) ? ($validated['uid'] ?? null) : null;
}

/**
 * Kullanıcı kimliği — yalnızca geçerli session token, doğru şifre veya (opsiyonel) geçerli e-posta doğrulama kodu.
 *
 * @param array<string,mixed> $input
 * @param array{allowVerifyCode?:bool} $options
 */
function zinesh_resolve_user_from_auth_input(array $input, array $options = []): ?array {
    $allowVerifyCode = !empty($options['allowVerifyCode']);

    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token !== '') {
        $validated = zinesh_validate_session_token($token, true);
        if ($validated['ok'] ?? false) {
            $user = zinesh_find_user_by_uid((string)$validated['uid']);
            if ($user) {
                return $user;
            }
        }
        return null;
    }

    $email = strtolower(trim((string)($input['email'] ?? '')));
    if ($email === '') {
        return null;
    }

    $user = null;
    foreach (zinesh_load_users() as $u) {
        if (strtolower((string)($u['email'] ?? '')) === $email) {
            $user = $u;
            break;
        }
    }
    if (!$user) {
        return null;
    }

    $password = (string)($input['password'] ?? '');
    if ($password !== '' && isset($user['passwordHash']) && password_verify($password, (string)$user['passwordHash'])) {
        return $user;
    }

    if ($allowVerifyCode) {
        $code = trim((string)($input['code'] ?? ''));
        if ($code !== '' && preg_match('/^\d{6}$/', $code)) {
            $hash = function_exists('zinesh_email_verify_code_hash')
                ? zinesh_email_verify_code_hash($code)
                : hash('sha256', preg_replace('/\D/', '', $code));
            $stored = (string)($user['emailVerifyCode'] ?? '');
            $expires = (int)($user['emailVerifyExpires'] ?? 0);
            if ($stored !== '' && $expires >= time() && hash_equals($stored, $hash)) {
                return $user;
            }
        }
    }

    return null;
}

/**
 * Oturum yenileme — geçerli token veya (tokensız) şifre ile kullanıcı çözümle.
 * Süresi dolmuş token ile uid/e-posta yedeği kabul edilmez (güvenlik).
 *
 * @param array<string,mixed> $input
 */
function zinesh_resolve_user_for_session_refresh(array $input): ?array {
    $token = trim((string)($input['sessionToken'] ?? ''));
    if ($token === '') {
        return null;
    }
    $validated = zinesh_validate_session_token($token, true);
    if (!($validated['ok'] ?? false)) {
        return null;
    }
    $user = zinesh_find_user_by_uid((string)($validated['uid'] ?? ''));
    return $user ?: null;
}

function zinesh_require_auth(array $input): array {
    $user = zinesh_resolve_user_for_session_refresh($input);
    if (!$user) {
        zinesh_json_response(['message' => 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.', 'code' => 'session_expired'], 401);
    }
    return $user;
}

function zinesh_update_user(string $uid, callable $mutator): array {
    $result = null;
    zinesh_json_atomic('users.json', function (array &$users) use ($uid, $mutator, &$result) {
        foreach ($users as $i => $u) {
            if (($u['uid'] ?? '') !== $uid) {
                continue;
            }
            zinesh_ensure_wallet_fields($users[$i]);
            $mutator($users[$i]);
            $result = $users[$i];
            return true;
        }
        zinesh_json_response(['message' => 'Kullanıcı bulunamadı.'], 404);
    });
    return $result;
}

/** Atomik cüzdan mutasyonlarında exit yerine exception — rollback için. */
function zinesh_wallet_abort(string $message): never
{
    throw new RuntimeException($message);
}

/**
 * TL emanet ödemesi: işveren escrow + usdt düşülür, işçiye aktarılır.
 *
 * @throws RuntimeException
 */
function zinesh_wallet_apply_tl_escrow_settlement(
    string $employerUid,
    string $workerUid,
    float $lockAmount,
    float $workerPayout,
    float $workerCollateralRelease = 0.0
): void {
    if ($lockAmount <= 0) {
        zinesh_wallet_abort('Geçersiz kilit tutarı.');
    }
    if ($workerPayout < 0) {
        zinesh_wallet_abort('Geçersiz ödeme tutarı.');
    }

    zinesh_json_atomic('users.json', static function (array &$users) use (
        $employerUid,
        $workerUid,
        $lockAmount,
        $workerPayout,
        $workerCollateralRelease
    ) {
        $employerIdx = $workerIdx = null;
        foreach ($users as $i => $u) {
            if (($u['uid'] ?? '') === $employerUid) {
                $employerIdx = $i;
            }
            if (($u['uid'] ?? '') === $workerUid) {
                $workerIdx = $i;
            }
        }
        if ($employerIdx === null || $workerIdx === null) {
            zinesh_wallet_abort('Taraflar bulunamadı.');
        }
        zinesh_ensure_wallet_fields($users[$employerIdx]);
        zinesh_ensure_wallet_fields($users[$workerIdx]);

        $employerEscrow = (float)$users[$employerIdx]['escrowBalance'];
        $employerUsdt = (float)$users[$employerIdx]['usdtBalance'];
        if ($employerEscrow + 1e-9 < $lockAmount) {
            zinesh_wallet_abort('Kilitli tutar yetersiz.');
        }
        if ($employerUsdt + 1e-9 < $lockAmount) {
            zinesh_wallet_abort('İşveren bakiyesi ödeme için yetersiz.');
        }

        $users[$employerIdx]['escrowBalance'] = round($employerEscrow - $lockAmount, 2);
        $users[$employerIdx]['usdtBalance'] = round($employerUsdt - $lockAmount, 2);
        $users[$workerIdx]['usdtBalance'] = round((float)$users[$workerIdx]['usdtBalance'] + $workerPayout, 2);

        if ($workerCollateralRelease > 0) {
            $workerEscrow = (float)$users[$workerIdx]['escrowBalance'];
            if ($workerEscrow + 1e-9 < $workerCollateralRelease) {
                zinesh_wallet_abort('Teminat tutarı yetersiz.');
            }
            $users[$workerIdx]['escrowBalance'] = round($workerEscrow - $workerCollateralRelease, 2);
        }

        return true;
    });
}

/** @return array{ok:bool,totalUsdt:float,totalEscrow:float,issues:list<string>} */
function zinesh_wallet_invariant_audit(): array
{
    $users = zinesh_load_users();
    $totalUsdt = 0.0;
    $totalEscrow = 0.0;
    $issues = [];
    foreach ($users as $u) {
        if (!is_array($u)) {
            continue;
        }
        zinesh_ensure_wallet_fields($u);
        $usdt = (float)$u['usdtBalance'];
        $escrow = (float)$u['escrowBalance'];
        $totalUsdt += $usdt;
        $totalEscrow += $escrow;
        if ($escrow > $usdt + 0.01) {
            $issues[] = sprintf(
                'uid=%s escrow (%.2f) > usdt (%.2f)',
                (string)($u['uid'] ?? ''),
                $escrow,
                $usdt
            );
        }
        if ($usdt < -0.01 || $escrow < -0.01) {
            $issues[] = sprintf(
                'uid=%s negatif bakiye usdt=%.2f escrow=%.2f',
                (string)($u['uid'] ?? ''),
                $usdt,
                $escrow
            );
        }
    }
    return [
        'ok' => $issues === [],
        'totalUsdt' => round($totalUsdt, 2),
        'totalEscrow' => round($totalEscrow, 2),
        'issues' => $issues,
    ];
}

function zinesh_load_tx_index(): array {
    return zinesh_json_read('deposit_tx_index.json');
}

function zinesh_mark_tx_used(string $network, string $txHash): bool {
    $normalized = strtolower(trim($txHash));
    if (strtolower($network) === 'tron') {
        $n = zinesh_normalize_tron_txid($txHash);
        if ($n) {
            $normalized = $n;
        }
    }
    $key = strtolower($network) . ':' . $normalized;
    return (bool) zinesh_json_atomic('deposit_tx_index.json', function (array &$index) use ($key) {
        if (isset($index[$key])) {
            return false;
        }
        $index[$key] = ['at' => date('c')];
        return true;
    });
}

function zinesh_app_timezone(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone('Europe/Istanbul');
    }
    return $tz;
}

/** @return array{createdAt:string,date:string} */
function zinesh_tx_stamp(?DateTimeInterface $at = null): array
{
    if ($at === null) {
        $dt = new DateTimeImmutable('now', zinesh_app_timezone());
    } elseif ($at instanceof DateTimeImmutable) {
        $dt = $at->setTimezone(zinesh_app_timezone());
    } else {
        $dt = DateTimeImmutable::createFromInterface($at)->setTimezone(zinesh_app_timezone());
    }
    return [
        'createdAt' => $dt->format('c'),
        'date' => $dt->format('d.m.Y H:i'),
    ];
}

function zinesh_append_tx_log(string $uid, array $entry): void {
    if (empty($entry['createdAt'])) {
        $stamp = zinesh_tx_stamp();
        $entry['createdAt'] = $stamp['createdAt'];
        if (empty($entry['date'])) {
            $entry['date'] = $stamp['date'];
        }
    } elseif (empty($entry['date'])) {
        try {
            $entry['date'] = zinesh_tx_stamp(new DateTimeImmutable((string)$entry['createdAt']))['date'];
        } catch (Exception $e) {
            $entry['date'] = zinesh_tx_stamp()['date'];
        }
    }
    $logs = zinesh_json_read('wallet_tx_logs.json');
    if (!isset($logs[$uid]) || !is_array($logs[$uid])) $logs[$uid] = [];
    array_unshift($logs[$uid], $entry);
    $logs[$uid] = array_slice($logs[$uid], 0, 100);
    zinesh_json_write('wallet_tx_logs.json', $logs);
}

/**
 * Admin/kurucu: kullanıcı bakiyesine TL ekle veya mutlak değer ata.
 *
 * @return array{ok:bool,message:string,user?:array,wallet?:array}
 */
function zinesh_admin_set_user_balance(string $lookup, float $amount, string $mode = 'add', string $note = ''): array
{
    $lookup = trim($lookup);
    $amount = round($amount, 2);
    $mode = strtolower(trim($mode));
    if ($lookup === '') {
        return ['ok' => false, 'message' => 'E-posta, üye numarası veya uid girin.'];
    }
    if (!in_array($mode, ['add', 'set'], true)) {
        return ['ok' => false, 'message' => 'Mod add veya set olmalı.'];
    }
    if ($mode === 'add' && $amount == 0.0) {
        return ['ok' => false, 'message' => 'Tutar 0 olamaz.'];
    }
    if ($mode === 'set' && $amount < 0) {
        return ['ok' => false, 'message' => 'Bakiye negatif olamaz.'];
    }

    $user = null;
    if (str_contains($lookup, '@')) {
        $email = strtolower($lookup);
        foreach (zinesh_load_users() as $u) {
            if (strtolower((string)($u['email'] ?? '')) === $email) {
                $user = $u;
                break;
            }
        }
    }
    if (!$user) {
        $user = zinesh_find_user_by_ticket($lookup);
    }
    if (!$user) {
        $user = zinesh_find_user_by_uid($lookup);
    }
    if (!$user) {
        return ['ok' => false, 'message' => 'Kullanıcı bulunamadı.'];
    }

    $uid = (string)($user['uid'] ?? '');
    zinesh_ensure_wallet_fields($user);
    $before = round((float)$user['usdtBalance'], 2);
    $after = $mode === 'set' ? round($amount, 2) : round($before + $amount, 2);
    if ($after < 0) {
        return ['ok' => false, 'message' => 'Sonuç bakiye negatif olamaz.'];
    }
    $delta = round($after - $before, 2);

    $updated = zinesh_update_user($uid, static function (array &$u) use ($after): void {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = $after;
    });

    $txId = 'adm-' . substr(hash('sha256', $uid . microtime(true) . random_bytes(4)), 0, 12);
    zinesh_append_tx_log($uid, [
        'id' => $txId,
        'type' => 'deposit',
        'network' => 'admin',
        'amount' => number_format(abs($delta), 2, '.', ''),
        'asset' => 'TRY',
        'txHash' => $mode === 'set' ? 'set' : ($delta >= 0 ? 'credit' : 'debit'),
        'date' => date('d.m.Y H:i'),
        'status' => 'completed',
        'note' => mb_substr(trim($note), 0, 120),
    ]);

    zinesh_audit('admin_balance_adjust', [
        'uid' => $uid,
        'mode' => $mode,
        'amount' => $amount,
        'delta' => $delta,
        'before' => $before,
        'after' => $after,
        'note' => mb_substr(trim($note), 0, 120),
        'id' => $txId,
    ]);

    if ($delta > 0 && function_exists('zinesh_campaign_record_deposit')) {
        zinesh_campaign_record_deposit($uid, $delta);
    }

    return [
        'ok' => true,
        'message' => sprintf(
            '%s bakiyesi güncellendi: %s → %s TL (%s%s).',
            (string)($updated['email'] ?? $uid),
            number_format($before, 2, ',', '.'),
            number_format($after, 2, ',', '.'),
            $delta >= 0 ? '+' : '',
            number_format($delta, 2, ',', '.')
        ),
        'user' => zinesh_public_user($updated),
        'wallet' => zinesh_wallet_state($updated),
    ];
}

function zinesh_log_tl_escrow_settlement(
    string $employerUid,
    string $workerUid,
    string $refId,
    float $lockAmount,
    float $workerPayout
): void {
    zinesh_append_tx_log($employerUid, [
        'id' => 'es-out-' . substr(hash('sha256', $refId . $employerUid), 0, 12),
        'type' => 'escrow_send',
        'amount' => number_format($lockAmount, 2, '.', ''),
        'asset' => 'TL',
        'txHash' => $refId,
        'status' => 'completed',
        'label' => 'Emanet gönderimi',
    ]);
    zinesh_append_tx_log($workerUid, [
        'id' => 'es-in-' . substr(hash('sha256', $refId . $workerUid), 0, 12),
        'type' => 'escrow_receive',
        'amount' => number_format($workerPayout, 2, '.', ''),
        'asset' => 'TL',
        'txHash' => $refId,
        'status' => 'completed',
        'label' => 'Emanet ödemesi',
    ]);
}

function zinesh_log_tl_escrow_lock(
    string $employerUid,
    string $workerUid,
    string $refId,
    float $lockAmount,
    float $collateralAmount = 0.0
): void {
    zinesh_append_tx_log($employerUid, [
        'id' => 'el-out-' . substr(hash('sha256', $refId . $employerUid . ':lock'), 0, 12),
        'type' => 'escrow_lock',
        'amount' => number_format($lockAmount, 2, '.', ''),
        'asset' => 'TL',
        'txHash' => $refId,
        'status' => 'completed',
        'label' => 'Emanet kilidi',
    ]);
    if ($collateralAmount > 0.009) {
        zinesh_append_tx_log($workerUid, [
            'id' => 'el-col-' . substr(hash('sha256', $refId . $workerUid . ':col'), 0, 12),
            'type' => 'escrow_lock',
            'amount' => number_format($collateralAmount, 2, '.', ''),
            'asset' => 'TL',
            'txHash' => $refId,
            'status' => 'completed',
            'label' => 'Teminat kilidi',
        ]);
    }
}

function zinesh_get_tx_logs(string $uid): array {
    $logs = zinesh_json_read('wallet_tx_logs.json');
    return $logs[$uid] ?? [];
}

function zinesh_rpc_call(string $rpc, string $method, array $params): mixed {
    $body = json_encode(['jsonrpc' => '2.0', 'method' => $method, 'params' => $params, 'id' => 1]);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 45,
            'ignore_errors' => true,
        ],
    ]);
    $res = @file_get_contents($rpc, false, $ctx);
    if ($res === false) return null;
    $json = json_decode($res, true);
    return $json['result'] ?? null;
}

function zinesh_tron_headers(): array {
    $key = zinesh_config()['trongrid_api_key'] ?? '';
    return $key ? ['TRON-PRO-API-KEY' => $key] : [];
}

function zinesh_http_get(string $url, array $headers = []): ?array {
    $headerLines = "Accept: application/json\r\n";
    foreach ($headers as $k => $v) {
        $headerLines .= "{$k}: {$v}\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerLines,
            'timeout' => 45,
            'ignore_errors' => true,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return null;
    $json = json_decode($res, true);
    return is_array($json) ? $json : null;
}

function zinesh_tron_post(string $path, array $payload): ?array {
    $url = rtrim(zinesh_config()['rpc']['tron'] ?? 'https://api.trongrid.io', '/') . $path;
    $body = json_encode($payload);
    $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
    foreach (zinesh_tron_headers() as $k => $v) {
        $headers .= "{$k}: {$v}\r\n";
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => $headers,
            'content' => $body,
            'timeout' => 45,
            'ignore_errors' => true,
        ],
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) return null;
    $json = json_decode($res, true);
    return is_array($json) ? $json : null;
}

function zinesh_normalize_tron_txid(string $txId): ?string {
    $txId = trim($txId);
    if (str_starts_with(strtolower($txId), '0x')) {
        $txId = substr($txId, 2);
    }
    if (!preg_match('/^[a-fA-F0-9]{64}$/', $txId)) {
        return null;
    }
    return strtolower($txId);
}

function zinesh_tron_address_hex(string $base58): ?string {
    $res = zinesh_tron_post('/wallet/validateaddress', [
        'address' => $base58,
        'visible' => true,
    ]);
    if (!$res || empty($res['hexAddress'])) {
        return null;
    }
    $hex = strtolower((string)$res['hexAddress']);
    return str_starts_with($hex, '0x') ? $hex : '0x' . $hex;
}

/** Sunucuda anahtarı olan operasyonel kasa (yatırım + çekim + ARGE USDT). */
function zinesh_active_treasury(): array {
    $cfg = zinesh_config();
    $legacy = $cfg['treasury_legacy'] ?? $cfg['treasury'] ?? [];
    $override = $cfg['treasury_override'] ?? [];

    if (!empty($override)) {
        $evm = trim((string)($override['arbitrum'] ?? $override['ethereum'] ?? ''));
        return [
            'tron' => trim((string)($override['tron'] ?? $legacy['tron'] ?? '')),
            'arbitrum' => $evm ?: trim((string)($legacy['arbitrum'] ?? '')),
            'ethereum' => trim((string)($override['ethereum'] ?? ($evm ?: ($legacy['ethereum'] ?? '')))),
            'solana' => trim((string)($override['solana'] ?? $legacy['solana'] ?? '')),
        ];
    }

    $synced = zinesh_json_read('treasury_active.json');
    if (!empty($synced['active']) && is_array($synced['active'])) {
        $a = $synced['active'];
        return [
            'tron' => trim((string)($a['tron'] ?? '')) ?: trim((string)($override['tron'] ?? $legacy['tron'] ?? '')),
            'arbitrum' => trim((string)($a['arbitrum'] ?? '')) ?: trim((string)($override['arbitrum'] ?? $legacy['arbitrum'] ?? '')),
            'ethereum' => trim((string)($a['ethereum'] ?? '')) ?: trim((string)($override['ethereum'] ?? $legacy['ethereum'] ?? '')),
            'solana' => trim((string)($a['solana'] ?? '')) ?: trim((string)($override['solana'] ?? $legacy['solana'] ?? '')),
        ];
    }

    $hot = zinesh_json_read('hot_wallets.json');
    $secrets = function_exists('zinesh_load_secrets') ? zinesh_load_secrets() : [];
    $tron = trim((string)($hot['tron']['address'] ?? $secrets['hot_wallet_tron'] ?? ''));
    $evm = trim((string)($hot['evm']['address'] ?? $secrets['hot_wallet_evm'] ?? ''));
    if ($tron === '') {
        $tron = trim((string)($legacy['tron'] ?? ''));
    }
    if ($evm === '') {
        $evm = trim((string)($legacy['arbitrum'] ?? $legacy['ethereum'] ?? ''));
    }

    return [
        'tron' => $tron ?: trim((string)($override['tron'] ?? '')),
        'arbitrum' => $evm ?: trim((string)($override['arbitrum'] ?? '')),
        'ethereum' => $evm ?: trim((string)($override['ethereum'] ?? '')),
        'solana' => trim((string)($hot['solana']['address'] ?? $legacy['solana'] ?? $override['solana'] ?? '')),
    ];
}

/**
 * ARGE komisyonunun aktarıldığı EVM kasa — operasyonel Arbitrum (fallback Ethereum).
 */
function zinesh_arge_development_wallet_evm(): string {
    $t = zinesh_active_treasury();
    $evm = trim((string)($t['arbitrum'] ?? ''));
    if ($evm !== '') {
        return $evm;
    }
    return trim((string)($t['ethereum'] ?? ''));
}

/** @return array{tron:string,arbitrum:string,ethereum:string} */
function zinesh_arge_operational_treasury(): array {
    $t = zinesh_active_treasury();
    return [
        'tron' => trim((string)($t['tron'] ?? '')),
        'arbitrum' => trim((string)($t['arbitrum'] ?? '')),
        'ethereum' => trim((string)($t['ethereum'] ?? '')),
    ];
}

function zinesh_legacy_treasury(): array {
    $cfg = zinesh_config();
    return $cfg['treasury_legacy'] ?? $cfg['treasury'] ?? [];
}

/** Yatırım kabul edilen tüm kasa adresleri (aktif + eski). */
function zinesh_deposit_recipients(string $network): array {
    $network = strtolower($network);
    $active = zinesh_active_treasury();
    $legacy = zinesh_legacy_treasury();
    $list = [];
    if ($network === 'tron') {
        foreach ([$active['tron'] ?? '', $legacy['tron'] ?? ''] as $a) {
            if ($a !== '') $list[] = $a;
        }
    } elseif (in_array($network, ['arbitrum', 'ethereum'], true)) {
        foreach ([$active[$network] ?? '', $legacy[$network] ?? ''] as $a) {
            if ($a !== '') $list[] = strtolower($a);
        }
    } elseif ($network === 'solana') {
        foreach ([$active['solana'] ?? '', $legacy['solana'] ?? ''] as $a) {
            if ($a !== '') $list[] = $a;
        }
    }
    return array_values(array_unique(array_filter($list)));
}

function zinesh_total_user_usdt_liability(): float {
    $total = 0.0;
    foreach (zinesh_load_users() as $u) {
        zinesh_ensure_wallet_fields($u);
        $total += (float)($u['usdtBalance'] ?? 0);
    }
    return round($total, 6);
}

/** Satın alınmış FİZİ (kampanya ödülü hariç). */
function zinesh_purchased_fizi(array $user): float {
    zinesh_ensure_wallet_fields($user);
    if (isset($user['purchasedFiziBalance']) && $user['purchasedFiziBalance'] !== null) {
        return round(max(0.0, min((float)$user['purchasedFiziBalance'], (float)$user['fiziBalance'])), 6);
    }
    if (!function_exists('zinesh_campaign_fizi_held')) {
        require_once __DIR__ . '/campaign_lib.php';
    }
    $campaign = zinesh_campaign_fizi_held($user);
    $total = max(0.0, (float)($user['fiziBalance'] ?? 0));
    return round(max(0.0, $total - $campaign), 6);
}

function zinesh_campaign_fizi_sellable(): bool {
    $pc = zinesh_protocol_constants();
    $cfg = zinesh_config();
    return !empty($cfg['campaign_fizi_sellable'] ?? $pc['campaign_fizi_sellable'] ?? false);
}

/** Satışta önce purchased, kalan kampanya bakiyesinden düşülür. */
function zinesh_apply_fizi_sell_deduction(array &$user, float $amount): void {
    zinesh_ensure_wallet_fields($user);
    if ($amount <= 0) {
        return;
    }
    $purchased = zinesh_purchased_fizi($user);
    $fromPurchased = round(min($amount, $purchased), 6);
    $fromCampaign = round(max(0.0, $amount - $fromPurchased), 6);
    if ($fromPurchased > 0) {
        $user['purchasedFiziBalance'] = round(max(0.0, (float)($user['purchasedFiziBalance'] ?? 0) - $fromPurchased), 6);
    }
    if ($fromCampaign > 0) {
        $user['campaignFiziBalance'] = round(max(0.0, (float)($user['campaignFiziBalance'] ?? 0) - $fromCampaign), 6);
    }
}

/** Erken erişim şartları sağlandığında satılabilir FİZİ (kampanya dahil). */
function zinesh_sellable_fizi(array $user): float {
    if (function_exists('zinesh_tl_mode_enabled') && zinesh_tl_mode_enabled()) {
        return 0.0;
    }
    if (!function_exists('zinesh_early_access_sell_status')) {
        require_once __DIR__ . '/early_access_lib.php';
    }
    $sellGate = zinesh_early_access_sell_status($user);
    if (!$sellGate['allowed']) {
        return 0.0;
    }
    if (zinesh_campaign_fizi_sellable()) {
        return round(max(0.0, (float)($user['fiziBalance'] ?? 0)), 6);
    }
    return zinesh_purchased_fizi($user);
}

/** Erken erişim satış engeli mesajı (varsa). */
function zinesh_sell_block_reason(array $user): ?string {
    if (!function_exists('zinesh_early_access_sell_status')) {
        require_once __DIR__ . '/early_access_lib.php';
    }
    $status = zinesh_early_access_sell_status($user);
    return $status['allowed'] ? null : ($status['reason'] ?? 'FİZİ satışı şu an kapalı.');
}

/** @return array{treasuryUsdt:float,totalUserUsdt:float,availableUsdt:float,liquidityGap:float,liquidityRatio:float} */
function zinesh_liquidity_stats(): array {
    $treasury = zinesh_treasury_usdt_total();
    $liability = zinesh_total_user_usdt_liability();
    $available = max(0.0, round($treasury - $liability, 6));
    $gap = max(0.0, round($liability - $treasury, 6));
    $ratio = $liability > 0
        ? round($treasury / $liability, 6)
        : ($treasury > 0 ? 1.0 : 0.0);
    return [
        'treasuryUsdt' => $treasury,
        'totalUserUsdt' => $liability,
        'availableUsdt' => $available,
        'liquidityGap' => $gap,
        'liquidityRatio' => $ratio,
    ];
}

/**
 * FİZİ satışını rezerv ve kampanya kurallarına göre sınırla.
 *
 * @return array{
 *   ok:bool,
 *   message?:string,
 *   amount?:float,
 *   gross?:float,
 *   fee?:float,
 *   received?:float,
 *   partial?:bool,
 *   liquidity?:array
 * }
 */
function zinesh_compute_fizi_sell(array $user, float $requestedAmount, float $rate, float $feeRate): array {
    zinesh_ensure_wallet_fields($user);
    $liquidity = zinesh_liquidity_stats();
    $available = $liquidity['availableUsdt'];
    $sellableFizi = zinesh_sellable_fizi($user);

    if ($requestedAmount <= 0) {
        return ['ok' => false, 'message' => 'Geçerli bir miktar girin.', 'liquidity' => $liquidity];
    }
    if ($sellableFizi <= 0) {
        $blockReason = zinesh_sell_block_reason($user);
        if ($blockReason !== null) {
            return [
                'ok' => false,
                'message' => $blockReason,
                'liquidity' => $liquidity,
            ];
        }
        return [
            'ok' => false,
            'message' => 'Satılabilir FİZİ bakiyeniz yok.',
            'liquidity' => $liquidity,
        ];
    }
    if ($available <= 0) {
        return [
            'ok' => false,
            'message' => 'Likidite yetersiz. Lütfen daha sonra tekrar deneyin.',
            'liquidity' => $liquidity,
        ];
    }

    $amount = min($requestedAmount, $sellableFizi);
    $partial = $requestedAmount > $amount + 1e-9;
    $partialReason = $partial ? 'sellable_fizi' : null;

    $gross = $amount * $rate;
    if ($gross > $available + 1e-9) {
        $maxFizi = floor(($available / $rate) * 1e6) / 1e6;
        if ($maxFizi <= 0) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Kasada yalnızca $%s USDT boş rezerv var. Bu miktar satış için yeterli değil.',
                    number_format($available, 2)
                ),
                'liquidity' => $liquidity,
            ];
        }
        $amount = min($amount, $maxFizi);
        $partial = true;
        $partialReason = 'liquidity_cap';
        $gross = $amount * $rate;
    }

    if ($amount <= 0 || $gross <= 0) {
        return [
            'ok' => false,
            'message' => 'Likidite yetersiz. Lütfen daha sonra tekrar deneyin.',
            'liquidity' => $liquidity,
        ];
    }

    $fee = round($gross * $feeRate, 6);
    $received = round($gross - $fee, 6);
    if ($received <= 0) {
        return [
            'ok' => false,
            'message' => 'Likidite yetersiz. Lütfen daha sonra tekrar deneyin.',
            'liquidity' => $liquidity,
        ];
    }

    $newLiability = round($liquidity['totalUserUsdt'] + $received, 6);
    if ($newLiability > $liquidity['treasuryUsdt'] + 1e-6) {
        $maxNet = $available;
        $maxGross = $feeRate < 1 ? ($maxNet / (1 - $feeRate)) : $maxNet;
        $amount = floor(($maxGross / $rate) * 1e6) / 1e6;
        if ($amount <= 0) {
            return [
                'ok' => false,
                'message' => 'Likidite yetersiz. Lütfen daha sonra tekrar deneyin.',
                'liquidity' => $liquidity,
            ];
        }
        $gross = $amount * $rate;
        $fee = round($gross * $feeRate, 6);
        $received = round($gross - $fee, 6);
        $partial = true;
        $partialReason = 'liquidity_cap';
    }

    return [
        'ok' => true,
        'amount' => $amount,
        'gross' => $gross,
        'fee' => $fee,
        'received' => $received,
        'partial' => $partial,
        'partialReason' => $partialReason,
        'liquidity' => $liquidity,
    ];
}

function zinesh_circulating_fizi(): float {
    $pc = zinesh_protocol_constants();
    return (float)$pc['economic_supply_fizi'];
}

/** @return array{price:float,floor:float,treasuryUsdt:float,economicSupplyFizi:float,circulatingFizi:float,communityCirculatingFizi:float,fiziPools:array,rdReserve:array,guardTriggered?:bool} */
function zinesh_fizi_economics(): array {
    if (function_exists('zinesh_tl_mode_enabled') && zinesh_tl_mode_enabled()) {
        $treasury = zinesh_treasury_usdt_total();
        return [
            'price' => 0.0,
            'floor' => 0.0,
            'treasuryUsdt' => $treasury,
            'economicSupplyFizi' => 0.0,
            'circulatingFizi' => 0.0,
            'communityCirculatingFizi' => 0.0,
            'fiziPools' => [],
            'rdReserve' => [],
        ];
    }

    static $inProgress = false;
    if ($inProgress) {
        $pc = zinesh_protocol_constants();
        $economicSupply = (float)$pc['economic_supply_fizi'];
        return [
            'price' => zinesh_fizi_price_cached(),
            'floor' => 0.0,
            'treasuryUsdt' => 0.0,
            'economicSupplyFizi' => $economicSupply,
            'circulatingFizi' => $economicSupply,
            'communityCirculatingFizi' => 0.0,
            'fiziPools' => [],
            'rdReserve' => [],
            'guardTriggered' => true,
        ];
    }

    $inProgress = true;
    try {
        $pc = zinesh_protocol_constants();
        $economicSupply = (float)$pc['economic_supply_fizi'];
        $treasury = zinesh_treasury_usdt_total();
        $price = $economicSupply > 0 ? round($treasury / $economicSupply, 8) : 0.0;
        return [
            'price' => $price,
            'floor' => 0.0,
            'treasuryUsdt' => $treasury,
            'economicSupplyFizi' => $economicSupply,
            'circulatingFizi' => $economicSupply,
            'communityCirculatingFizi' => 0.0,
            'fiziPools' => [],
            'rdReserve' => [],
        ];
    } finally {
        $inProgress = false;
    }
}

/** Satış havuzundan dağıtılmış net FİZİ (kampanya ve AR-GE hariç). */
function zinesh_fizi_outstanding_from_sale_pool(): float {
    if (!function_exists('zinesh_campaign_fizi_held')) {
        require_once __DIR__ . '/campaign_lib.php';
    }
    $rdUid = (string)(zinesh_config()['rd_reserve_wallet_uid'] ?? 'rd_reserve_wallet');
    $total = 0.0;
    foreach (zinesh_load_users() as $u) {
        if (($u['uid'] ?? '') === $rdUid) {
            continue;
        }
        zinesh_ensure_wallet_fields($u);
        $fizi = max(0.0, (float)($u['fiziBalance'] ?? 0));
        $campaign = zinesh_campaign_fizi_held($u);
        $total += max(0.0, zinesh_purchased_fizi($u));
    }
    return round($total, 6);
}

/** Mevcut kullanıcı bakiyelerine göre satış havuzunu senkronize eder. */
function zinesh_fizi_pools_sync_sale_pool(): void {
    if (function_exists('zinesh_tl_mode_enabled') && zinesh_tl_mode_enabled()) {
        return;
    }
}

function zinesh_treasury_usdt_total(): float {
    $bal = zinesh_treasury_on_chain_balances();
    if (empty($bal['ok'])) {
        return 0.0;
    }
    return round(
        (float)($bal['tron']['usdt'] ?? 0)
        + (float)($bal['evm']['arbitrum']['usdt'] ?? 0)
        + (float)($bal['evm']['ethereum']['usdt'] ?? 0),
        6
    );
}

function zinesh_fizi_price_cached(): float {
    $pc = zinesh_protocol_constants();
    $economic = (float)($pc['economic_supply_fizi'] ?? 0);
    if ($economic <= 0) {
        return 0.0;
    }
    $cached = zinesh_treasury_on_chain_read_cache();
    if ($cached === null || empty($cached['ok'])) {
        return 0.0;
    }
    $treasury = round(
        (float)($cached['tron']['usdt'] ?? 0)
        + (float)($cached['evm']['arbitrum']['usdt'] ?? 0)
        + (float)($cached['evm']['ethereum']['usdt'] ?? 0),
        6
    );
    return $treasury > 0 ? round($treasury / $economic, 8) : 0.0;
}

function zinesh_fizi_current_price(): float {
    return zinesh_fizi_economics()['price'];
}

function zinesh_treasury_on_chain_cache_path(): string {
    return zinesh_data_path('treasury_on_chain_cache.json');
}

/** @return array<string,mixed>|null */
function zinesh_treasury_on_chain_read_cache(): ?array {
    $path = zinesh_treasury_on_chain_cache_path();
    if (!file_exists($path)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) && !empty($data['ok']) ? $data : null;
}

function zinesh_treasury_on_chain_write_cache(array $payload): void {
    $payload['fetchedAt'] = time();
    file_put_contents(
        zinesh_treasury_on_chain_cache_path(),
        json_encode($payload, JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function zinesh_treasury_on_chain_balances_fetch(): array {
    if (!function_exists('zinesh_run_node_script')) {
        require_once __DIR__ . '/withdraw_executor.php';
    }
    $active = zinesh_active_treasury();
    $cfg = zinesh_config();
    return zinesh_run_node_script('check-balances.mjs', [
        'tronAddress' => $active['tron'] ?? '',
        'evmAddress' => $active['arbitrum'] ?? '',
        'trongridApiKey' => $cfg['trongrid_api_key'] ?? '',
    ], 10);
}

function zinesh_treasury_on_chain_balances(bool $forceRefresh = false): array {
    $ttl = 120;
    $cached = zinesh_treasury_on_chain_read_cache();
    if (!$forceRefresh && $cached !== null) {
        $age = time() - (int)($cached['fetchedAt'] ?? 0);
        if ($age >= 0 && $age < $ttl) {
            return $cached;
        }
    }

    $fresh = zinesh_treasury_on_chain_balances_fetch();
    if (!empty($fresh['ok'])) {
        zinesh_treasury_on_chain_write_cache($fresh);
        return $fresh;
    }

    if ($cached !== null) {
        $cached['stale'] = true;
        $cached['staleError'] = (string)($fresh['error'] ?? 'Zincir okunamadı');
        return $cached;
    }

    return is_array($fresh) ? $fresh : ['ok' => false, 'error' => 'Zincir bakiyesi okunamadı'];
}

function zinesh_hot_usdt_check(string $network): array {
    $bal = zinesh_treasury_on_chain_balances();
    if (empty($bal['ok'])) {
        return [
            'ok' => false,
            'available' => null,
            'error' => (string)($bal['error'] ?? 'Zincir bakiyesi okunamadı'),
        ];
    }

    $network = strtolower($network);
    $available = 0.0;
    if ($network === 'tron') {
        $available = (float)($bal['tron']['usdt'] ?? 0);
    } elseif ($network === 'arbitrum') {
        $available = (float)($bal['evm']['arbitrum']['usdt'] ?? 0);
    } elseif ($network === 'ethereum') {
        $available = (float)($bal['evm']['ethereum']['usdt'] ?? 0);
    }

    return [
        'ok' => true,
        'available' => $available,
        'error' => null,
    ];
}

function zinesh_hot_usdt_available(string $network): float {
    $check = zinesh_hot_usdt_check($network);
    return $check['ok'] ? (float)$check['available'] : 0.0;
}

/** Hot wallet otomatik çekim limiti (USDT) — aşılırsa manuel onay gerekir. */
function zinesh_hot_wallet_max_usdt(): float {
    return (float)(zinesh_config()['hot_wallet_max_usdt'] ?? 500.0);
}

function zinesh_hot_wallet_blocks_auto_send(string $network): bool {
    $check = zinesh_hot_usdt_check($network);
    if (!$check['ok']) {
        return true;
    }
    return (float)$check['available'] > zinesh_hot_wallet_max_usdt();
}

/** @return array{amount:float,senders:string[]}|null */
function zinesh_verify_tron_usdt_details(string $txId): ?array {
    $recipients = zinesh_deposit_recipients('tron');
    $usdt = zinesh_config()['usdt_contracts']['tron'] ?? 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    if ($recipients === []) return null;

    $txId = zinesh_normalize_tron_txid($txId);
    if (!$txId) return null;

    $recipientHex = [];
    foreach ($recipients as $addr) {
        $h = zinesh_tron_address_hex($addr);
        if ($h) {
            $recipientHex[$addr] = '0x' . str_pad(substr(ltrim($h, '0x'), 0, 40), 64, '0', STR_PAD_LEFT);
        }
    }

    $total = 0.0;
    $senders = [];

    $events = zinesh_http_get(
        "https://api.trongrid.io/v1/transactions/{$txId}/events",
        zinesh_tron_headers()
    );
    if ($events && !empty($events['data'])) {
        foreach ($events['data'] as $ev) {
            if (($ev['event_name'] ?? '') !== 'Transfer') continue;
            $contract = $ev['contract_address'] ?? '';
            if ($contract !== $usdt) continue;
            $result = $ev['result'] ?? [];
            $to = $result['to'] ?? ($result['1'] ?? '');
            if (!in_array($to, $recipients, true)) continue;
            $from = trim((string)($result['from'] ?? $result['0'] ?? ''));
            if ($from !== '') {
                $senders[] = $from;
            }
            $value = $result['value'] ?? ($result['2'] ?? '0');
            $total += (float)$value / 1_000_000;
        }
    }

    if ($total <= 0) {
        $info = zinesh_tron_post('/wallet/gettransactioninfobyid', ['value' => $txId]);
        if (!$info || empty($info['log'])) return null;
        foreach ($info['log'] as $log) {
            $topics = $log['topics'] ?? [];
            if (count($topics) < 3) continue;
            if (($topics[0] ?? '') !== TRANSFER_TOPIC) continue;
            $matched = false;
            foreach ($recipientHex as $topic) {
                if (strtolower($topics[2] ?? '') === strtolower($topic)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) continue;
            $fromTopic = strtolower((string)($topics[1] ?? ''));
            if ($fromTopic !== '') {
                $senders[] = $fromTopic;
            }
            $raw = hexdec($log['data'] ?? '0x0');
            $total += $raw / 1_000_000;
        }
    }

    if ($total <= 0) {
        return null;
    }
    return ['amount' => round($total, 6), 'senders' => array_values(array_unique($senders))];
}

function zinesh_verify_tron_usdt(string $txId): ?float {
    $details = zinesh_verify_tron_usdt_details($txId);
    return $details ? $details['amount'] : null;
}

/** @return array{amount:float,senders:string[]}|null */
function zinesh_verify_evm_usdt_details(string $network, string $txHash): ?array {
    $cfg = zinesh_config();
    $rpc = $cfg['rpc'][$network] ?? null;
    $usdt = $cfg['usdt_contracts'][$network] ?? null;
    $recipients = zinesh_deposit_recipients($network);
    if (!$rpc || !$usdt || $recipients === []) return null;

    $txHash = trim($txHash);
    if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) return null;

    $topics = [];
    foreach ($recipients as $addr) {
        $topics[] = '0x' . str_pad(substr(strtolower(ltrim($addr, '0x')), 0, 40), 64, '0', STR_PAD_LEFT);
    }

    $receipt = zinesh_rpc_call($rpc, 'eth_getTransactionReceipt', [$txHash]);
    if (!$receipt || ($receipt['status'] ?? '') !== '0x1') return null;

    $total = 0.0;
    $senders = [];

    foreach ($receipt['logs'] ?? [] as $log) {
        if (strtolower($log['address'] ?? '') !== strtolower($usdt)) continue;
        if (($log['topics'][0] ?? '') !== TRANSFER_TOPIC) continue;
        if (!in_array(strtolower($log['topics'][2] ?? ''), array_map('strtolower', $topics), true)) continue;
        $from = strtolower((string)($log['topics'][1] ?? ''));
        if ($from !== '') {
            $senders[] = $from;
        }
        $raw = hexdec($log['data'] ?? '0x0');
        $total += $raw / 1_000_000;
    }

    if ($total <= 0) {
        return null;
    }
    return ['amount' => round($total, 6), 'senders' => array_values(array_unique($senders))];
}

function zinesh_verify_evm_usdt(string $network, string $txHash): ?float {
    $details = zinesh_verify_evm_usdt_details($network, $txHash);
    return $details ? $details['amount'] : null;
}

/** @return array{amount:float,senders:string[]}|null */
function zinesh_verify_solana_usdt_details(string $signature): ?array {
    $cfg = zinesh_config();
    $recipients = zinesh_deposit_recipients('solana');
    $mint = $cfg['usdt_contracts']['solana'] ?? '';
    $rpc = $cfg['rpc']['solana'] ?? '';
    if ($recipients === [] || $mint === '' || $rpc === '') return null;

    $signature = trim($signature);
    if ($signature === '') return null;

    $result = zinesh_rpc_call($rpc, 'getTransaction', [
        $signature,
        ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0],
    ]);
    if (!$result || ($result['meta']['err'] ?? null) !== null) return null;

    $pre = [];
    foreach ($result['meta']['preTokenBalances'] ?? [] as $bal) {
        $owner = $bal['owner'] ?? '';
        $idx = $bal['accountIndex'] ?? 0;
        if (($bal['mint'] ?? '') === $mint) {
            $pre[$idx] = ['owner' => $owner, 'amount' => (float)($bal['uiTokenAmount']['uiAmount'] ?? 0)];
        }
    }

    $received = 0.0;
    $senders = [];
    foreach ($result['meta']['postTokenBalances'] ?? [] as $bal) {
        $owner = $bal['owner'] ?? '';
        $idx = $bal['accountIndex'] ?? 0;
        if (($bal['mint'] ?? '') !== $mint) continue;
        $post = (float)($bal['uiTokenAmount']['uiAmount'] ?? 0);
        $before = (float)($pre[$idx]['amount'] ?? 0);
        $delta = $post - $before;
        if (in_array($owner, $recipients, true) && $delta > 0) {
            $received += $delta;
            continue;
        }
        if ($delta < -1e-9 && !in_array($owner, $recipients, true)) {
            $senders[] = $owner;
        }
    }

    if ($received <= 0) {
        return null;
    }
    return ['amount' => round($received, 6), 'senders' => array_values(array_unique($senders))];
}

function zinesh_verify_solana_usdt(string $signature): ?float {
    $details = zinesh_verify_solana_usdt_details($signature);
    return $details ? $details['amount'] : null;
}

/** @return array{amount:float,senders:string[]}|null */
function zinesh_verify_deposit_details(string $network, string $txHash): ?array {
    $network = strtolower($network);
    if ($network === 'tron') {
        return zinesh_verify_tron_usdt_details($txHash);
    }
    if (in_array($network, ['arbitrum', 'ethereum'], true)) {
        return zinesh_verify_evm_usdt_details($network, $txHash);
    }
    if ($network === 'solana') {
        return zinesh_verify_solana_usdt_details($txHash);
    }
    return null;
}

/** @return string[] */
function zinesh_user_deposit_wallet_addresses(array $user, string $network): array {
    $network = strtolower($network);
    $addrs = [];
    foreach ($user['connectedWallets'] ?? [] as $w) {
        if (!is_array($w)) {
            continue;
        }
        if (!empty($w['system']) || !empty($w['treasury'])) {
            continue;
        }
        if (strtolower((string)($w['network'] ?? '')) !== $network) {
            continue;
        }
        $addr = trim((string)($w['address'] ?? ''));
        if ($addr !== '') {
            $addrs[] = $addr;
        }
    }
    return $addrs;
}

function zinesh_deposit_addresses_equal(string $network, string $a, string $b): bool {
    $network = strtolower($network);
    $a = trim($a);
    $b = trim($b);
    if ($a === '' || $b === '') {
        return false;
    }
    if ($network === 'tron') {
        if (str_starts_with(strtolower($a), '0x') || str_starts_with(strtolower($b), '0x')) {
            $hexA = str_starts_with(strtolower($a), '0x') ? strtolower($a) : ('0x' . (zinesh_tron_address_hex($a) ?? ''));
            $hexB = str_starts_with(strtolower($b), '0x') ? strtolower($b) : ('0x' . (zinesh_tron_address_hex($b) ?? ''));
            return $hexA !== '0x' && $hexB !== '0x' && strtolower($hexA) === strtolower($hexB);
        }
        return strcasecmp($a, $b) === 0;
    }
    if (in_array($network, ['arbitrum', 'ethereum'], true)) {
        return strtolower(ltrim($a, '0x')) === strtolower(ltrim($b, '0x'));
    }
    return $a === $b;
}

/** @param string[] $senders */
function zinesh_deposit_sender_allowed(array $user, string $network, array $senders): array {
    $senders = array_values(array_filter(array_unique(array_map('trim', $senders))));
    if ($senders === []) {
        return ['ok' => false, 'reason' => 'sender_unknown'];
    }
    $allowed = zinesh_user_deposit_wallet_addresses($user, $network);
    if ($allowed === []) {
        return ['ok' => true, 'reason' => null, 'mode' => 'unbound'];
    }
    foreach ($senders as $sender) {
        foreach ($allowed as $wallet) {
            if (zinesh_deposit_addresses_equal($network, $sender, $wallet)) {
                return ['ok' => true, 'reason' => null, 'mode' => 'bound'];
            }
        }
    }
    return ['ok' => false, 'reason' => 'sender_mismatch'];
}

function zinesh_verify_deposit(string $network, string $txHash): ?float {
    $details = zinesh_verify_deposit_details($network, $txHash);
    return $details ? $details['amount'] : null;
}

function zinesh_add_treasury_fee(string $bucket, float $amount): void {
    if ($amount <= 0) return;
    $ledger = zinesh_json_read('treasury_ledger.json');
    $ledger[$bucket] = round((float)($ledger[$bucket] ?? 0) + $amount, 6);
    $ledger['updatedAt'] = date('c');
    zinesh_json_write('treasury_ledger.json', $ledger);
}

/**
 * Admin paneli kasa özeti — Gerçek Kasa, borç, rezerv ve sistem komisyonu.
 *
 * @return array{
 *   gercekKasa:float,
 *   kullaniciBorcu:float,
 *   kullanilabilirRezerv:float,
 *   sistemKomisyonu:float,
 *   argeGeliri:float,
 *   katkiHavuzu:float,
 *   yakimHavuzu:float,
 *   liquidityGap:float
 * }
 */
function zinesh_treasury_panel_stats(): array {
    $liquidity = zinesh_liquidity_stats();
    $ledger = zinesh_json_read('treasury_ledger.json');

    // Eski Web3/token havuzları + yeni tek kova — hepsi sistem komisyonu.
    $sistemKomisyonu = round(
        (float)($ledger['protocol_commission_tl'] ?? 0)
        + (float)($ledger['job_commission_system_tl'] ?? 0)
        + (float)($ledger['job_commission_dev_usdt'] ?? 0)
        + (float)($ledger['job_commission_reward_usdt'] ?? 0)
        + (float)($ledger['job_commission_contribution_usdt'] ?? 0)
        + (float)($ledger['job_commission_burn_usdt'] ?? 0)
        + (float)($ledger['commission_arge_usdt'] ?? 0)
        + (float)($ledger['commission_founder_usdt'] ?? 0)
        + (float)($ledger['commission_founder_unassigned_usdt'] ?? 0)
        + (float)($ledger['commission_contribution_usdt'] ?? 0)
        + (float)($ledger['commission_reward_usdt'] ?? 0)
        + (float)($ledger['commission_reward_usdt_pending'] ?? 0)
        + (float)($ledger['commission_dam_usdt'] ?? 0)
        + (float)($ledger['commission_burn_usdt'] ?? 0),
        2
    );

    return [
        'gercekKasa' => round((float)$liquidity['treasuryUsdt'], 2),
        'kullaniciBorcu' => round((float)$liquidity['totalUserUsdt'], 2),
        'kullanilabilirRezerv' => round((float)$liquidity['availableUsdt'], 2),
        'sistemKomisyonu' => $sistemKomisyonu,
        // Eski alanlar — geriye dönük uyumluluk (tek havuza yönlendirilir)
        'argeGeliri' => $sistemKomisyonu,
        'katkiHavuzu' => 0.0,
        'odulHavuzu' => 0.0,
        'yakimHavuzu' => 0.0,
        'dengeDamUsdt' => 0.0,
        'dengeLegacyUsdt' => 0.0,
        'liquidityGap' => round((float)$liquidity['liquidityGap'], 2),
    ];
}

/** @deprecated Token/Web3 dağılımı kalktı — komisyonun tamamı sisteme kalır. */
function zinesh_commission_rates(): array {
    return [
        'founder' => 0.0,
        'dam' => 0.0,
        'burn' => 0.0,
        'contribution' => 0.0,
        'reward' => 0.0,
        'system' => 1.0,
    ];
}

/** @return array<string,mixed>|null */
function zinesh_find_primary_founder_user(): ?array {
    $cfg = zinesh_config();
    $founderUids = $cfg['founder_uids'] ?? [];
    if (is_string($founderUids)) {
        $founderUids = array_filter(array_map('trim', explode(',', $founderUids)));
    }
    foreach ($founderUids as $uid) {
        $user = zinesh_find_user_by_uid((string)$uid);
        if ($user) {
            return $user;
        }
    }

    $founderEmails = $cfg['founder_emails'] ?? [];
    if (is_string($founderEmails)) {
        $founderEmails = array_filter(array_map('trim', explode(',', $founderEmails)));
    }
    foreach ($founderEmails as $email) {
        $needle = strtolower(trim((string)$email));
        if ($needle === '') {
            continue;
        }
        foreach (zinesh_load_users() as $user) {
            if (strtolower((string)($user['email'] ?? '')) === $needle) {
                return $user;
            }
        }
    }

    return null;
}

/** Operasyonel kasa adreslerini kurucu hesabına bağlar. */
function zinesh_sync_founder_treasury_wallets(string $uid): array {
    $treasury = zinesh_active_treasury();

    $toLink = [
        ['network' => 'tron', 'address' => trim((string)($treasury['tron'] ?? '')), 'name' => 'Operasyonel Kasa (TRON)', 'id' => 'treasury-tron'],
        ['network' => 'arbitrum', 'address' => trim((string)($treasury['arbitrum'] ?? '')), 'name' => 'Operasyonel Kasa (Arbitrum)', 'id' => 'treasury-arbitrum'],
        ['network' => 'ethereum', 'address' => trim((string)($treasury['ethereum'] ?? '')), 'name' => 'Operasyonel Kasa (Ethereum)', 'id' => 'treasury-ethereum'],
        ['network' => 'solana', 'address' => trim((string)($treasury['solana'] ?? '')), 'name' => 'Operasyonel Kasa (Solana)', 'id' => 'treasury-solana'],
    ];

    return zinesh_update_user($uid, static function (array &$u) use ($toLink) {
        zinesh_ensure_wallet_fields($u);
        $existing = [];
        foreach ($u['connectedWallets'] as $w) {
            if (!is_array($w)) {
                continue;
            }
            $net = strtolower((string)($w['network'] ?? ''));
            $addr = strtolower(trim((string)($w['address'] ?? '')));
            if ($net !== '' && $addr !== '') {
                $existing[$net . ':' . $addr] = true;
            }
        }

        foreach ($toLink as $item) {
            $address = trim((string)$item['address']);
            if ($address === '') {
                continue;
            }
            $key = strtolower($item['network'] . ':' . $address);
            if (isset($existing[$key])) {
                continue;
            }
            $u['connectedWallets'][] = [
                'id' => (string)$item['id'],
                'name' => (string)$item['name'],
                'network' => (string)$item['network'],
                'address' => $address,
                'system' => true,
                'treasury' => true,
            ];
            $existing[$key] = true;
        }
    });
}

/**
 * Komisyon dağıtımı — Web3/token kalktı; %5'in tamamı sistem kasasına yazılır.
 *
 * @return array<string,mixed>
 */
function zinesh_distribute_commission(float $amount, string $source = 'job', string $asset = 'FIZI'): array {
    $empty = [
        'founder' => 0.0,
        'dam' => 0.0,
        'burn' => 0.0,
        'reward' => 0.0,
        'contribution' => 0.0,
        'system' => 0.0,
        'founderUid' => null,
        'burnedFizi' => 0.0,
    ];
    if ($amount <= 1e-9) {
        return $empty;
    }
    $system = round($amount, 2);
    zinesh_add_treasury_fee('protocol_commission_tl', $system);
    if (function_exists('zinesh_audit')) {
        zinesh_audit('protocol_commission', [
            'amount' => $system,
            'source' => $source,
            'asset' => $asset,
        ]);
    }
    return [
        'founder' => 0.0,
        'dam' => 0.0,
        'burn' => 0.0,
        'reward' => 0.0,
        'contribution' => 0.0,
        'system' => $system,
        'founderUid' => null,
        'burnedFizi' => 0.0,
    ];
}

function zinesh_deposit_addresses(): array {
    $t = zinesh_active_treasury();
    return [
        'tron'     => ['label' => 'TRON (TRC20)', 'address' => $t['tron'], 'asset' => 'USDT', 'hint' => 'Binance TR — operasyonel kasa'],
        'arbitrum' => ['label' => 'Arbitrum', 'address' => $t['arbitrum'], 'asset' => 'USDT', 'hint' => 'Aynı 0x adres — Arbitrum ağında USDT gönder'],
        'ethereum' => ['label' => 'Ethereum', 'address' => $t['ethereum'], 'asset' => 'USDT', 'hint' => 'Aynı 0x adres — Ethereum ağında USDT gönder'],
        'solana'   => ['label' => 'Solana', 'address' => $t['solana'], 'asset' => 'USDT', 'hint' => 'SPL'],
    ];
}

function zinesh_wallet_state(array $user): array {
    zinesh_ensure_wallet_fields($user);
    if (!function_exists('zinesh_early_access_public')) {
        require_once __DIR__ . '/early_access_lib.php';
    }
    $cfg = zinesh_config();
    $siteFiziOn = function_exists('zinesh_site_fizi_ledger_enabled') && zinesh_site_fizi_ledger_enabled();
    $tlMode = function_exists('zinesh_tl_mode_enabled') && zinesh_tl_mode_enabled();
    if (!function_exists('zinesh_deposits_enabled')) {
        require_once __DIR__ . '/tl_mode_lib.php';
    }
    // TL mode: skip on-chain hot-wallet probes (Node + open_basedir noise → nginx 502).
    $treasuryUsdt = ['tron' => null, 'arbitrum' => null, 'ethereum' => null];
    if (!$tlMode) {
        foreach (array_keys($treasuryUsdt) as $net) {
            $check = zinesh_hot_usdt_check($net);
            $treasuryUsdt[$net] = $check['ok'] ? (float)$check['available'] : null;
        }
    }
    $economics = zinesh_fizi_economics();
    $liquidity = zinesh_liquidity_stats();
    $sellableFizi = zinesh_sellable_fizi($user);
    $campaignFizi = 0.0;
    if (!function_exists('zinesh_campaign_fizi_held')) {
        require_once __DIR__ . '/campaign_lib.php';
    }
    $campaignFizi = zinesh_campaign_fizi_held($user);
    $maxSellGrossUsdt = round(min($sellableFizi * $economics['price'], $liquidity['availableUsdt']), 6);
    $depositsEnabled = zinesh_deposits_enabled();
    $showEscrow = $siteFiziOn || $tlMode;
    $state = [
        'usdtBalance' => $user['usdtBalance'],
        'fiziBalance' => $siteFiziOn ? $user['fiziBalance'] : 0.0,
        'campaignFiziBalance' => $siteFiziOn ? $campaignFizi : 0.0,
        'platformFiziBalance' => (!$siteFiziOn && function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled())
            ? zinesh_campaign_fizi_held($user)
            : 0.0,
        'platformFiziEnabled' => !$siteFiziOn && function_exists('zinesh_platform_fizi_enabled') && zinesh_platform_fizi_enabled(),
        'sellableFiziBalance' => $siteFiziOn ? $sellableFizi : 0.0,
        'escrowBalance' => $showEscrow ? $user['escrowBalance'] : 0.0,
        'fiziEscrowBalance' => $showEscrow ? $user['escrowBalance'] : 0.0,
        'availableFizi' => $siteFiziOn
            ? round(max(0.0, (float)$user['fiziBalance'] - (float)$user['escrowBalance']), 6)
            : 0.0,
        'siteFiziLedgerEnabled' => $siteFiziOn,
        'depositsEnabled' => $depositsEnabled,
        'availableUsdt' => round(
            max(0.0, (float)$user['usdtBalance'] - (float)$user['escrowBalance']),
            $tlMode ? 2 : 6
        ),
        'liquidity' => $liquidity,
        'maxSellGrossUsdt' => $maxSellGrossUsdt,
        'connectedWallets' => $user['connectedWallets'],
        'depositAddresses' => zinesh_deposit_addresses(),
        'transactions' => zinesh_get_tx_logs($user['uid']),
        'fiziCurrentPrice' => $economics['price'],
        'fiziPriceFloor' => $economics['floor'],
        'treasuryUsdtTotal' => $economics['treasuryUsdt'],
        'circulatingFizi' => $economics['circulatingFizi'],
        'economicSupplyFizi' => $economics['economicSupplyFizi'],
        'fiziPools' => $economics['fiziPools'],
        'purchasedFiziBalance' => zinesh_purchased_fizi($user),
        'earlyAccess' => zinesh_early_access_public(),
        'earlyAccessSell' => zinesh_early_access_sell_status($user),
        'sellBlockReason' => zinesh_sell_block_reason($user),
        'fiziUsdtRate' => $economics['price'],
        'swapFeeRate' => $cfg['swap_fee_rate'],
        'minDepositUsdt' => $cfg['min_deposit_usdt'],
        'minWithdrawUsdt' => $cfg['min_withdraw_usdt'],
        'treasuryUsdtAvailable' => $treasuryUsdt,
        'autoWithdraw' => [
            'tron' => zinesh_auto_withdraw_enabled('tron'),
            'arbitrum' => zinesh_auto_withdraw_enabled('arbitrum'),
            'ethereum' => zinesh_auto_withdraw_enabled('ethereum'),
            'solana' => false,
        ],
    ];
    if ($tlMode) {
        if (!function_exists('zinesh_havale_public_info')) {
            require_once __DIR__ . '/tl_havale_lib.php';
        }
        $state['tlHavale'] = zinesh_havale_public_info($user);
    }
    return $state;
}
