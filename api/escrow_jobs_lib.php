<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/password_reset_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';

function zinesh_escrow_jobs_load(): array
{
    $jobs = zinesh_json_read('escrow_jobs.json');
    return is_array($jobs) ? $jobs : [];
}

function zinesh_escrow_jobs_save(array $jobs): void
{
    zinesh_json_write('escrow_jobs.json', array_values($jobs));
}

function zinesh_escrow_job_find(string $jobId): ?array
{
    foreach (zinesh_escrow_jobs_load() as $job) {
        if ((string)($job['id'] ?? '') === $jobId) {
            return $job;
        }
    }
    return null;
}

function zinesh_escrow_job_new_id(): string
{
    return 'ESC-' . strtoupper(bin2hex(random_bytes(5)));
}

/** WhatsApp ile paylaşılacak kısa emanet kodu (örn. ZN-K7M2QX). */
function zinesh_escrow_job_new_match_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $len = strlen($alphabet);
    $raw = '';
    for ($i = 0; $i < 6; $i++) {
        $raw .= $alphabet[random_int(0, $len - 1)];
    }
    return 'ZN-' . $raw;
}

function zinesh_escrow_normalize_match_code(string $code): string
{
    $code = strtoupper(trim($code));
    $code = preg_replace('/[^A-Z0-9\-]/', '', $code) ?? '';
    return $code;
}

function zinesh_escrow_job_find_by_match_code(string $code): ?array
{
    $code = zinesh_escrow_normalize_match_code($code);
    if ($code === '') {
        return null;
    }
    foreach (zinesh_escrow_jobs_load() as $job) {
        $match = zinesh_escrow_normalize_match_code((string)($job['matchCode'] ?? ''));
        $id = strtoupper(trim((string)($job['id'] ?? '')));
        if ($match !== '' && $match === $code) {
            return $job;
        }
        if ($id !== '' && $id === $code) {
            return $job;
        }
    }
    return null;
}

function zinesh_escrow_job_is_open_status(string $status): bool
{
    return in_array($status, ['pending_match', 'active', 'completion_pending'], true);
}

function zinesh_ensure_profile_fields(array &$user): void
{
    if (!isset($user['jobHistoryPublic'])) {
        $user['jobHistoryPublic'] = true;
    }
    if (!isset($user['expertise']) || !is_array($user['expertise'])) {
        $user['expertise'] = [];
    }
}

function zinesh_adjust_trust_score(string $uid, float $delta): void
{
    if ($uid === '') {
        return;
    }
    zinesh_update_user($uid, static function (array &$u) use ($delta) {
        $next = round((float)($u['trustScore'] ?? 50) + $delta, 2);
        $u['trustScore'] = min(100.0, max(0.0, $next));
    });
}

function zinesh_apply_trust_fault_penalty(string $uid, float $penalty = 5.0): void
{
    if ($uid === '') {
        return;
    }
    zinesh_update_user($uid, static function (array &$u) use ($penalty) {
        $u['trustScoreModifier'] = round((float)($u['trustScoreModifier'] ?? 0) - $penalty, 2);
    });
}

/** @return array{active:int,completed:int,unsuccessful:int} */
function zinesh_escrow_trust_stats_for_user(string $uid, string $email = ''): array
{
    $jobStats = zinesh_escrow_job_stats_for_user($uid, $email);
    if (!function_exists('zinesh_escrow_room_stats_for_user')) {
        require_once __DIR__ . '/escrow_room_lib.php';
    }
    $roomStats = zinesh_escrow_room_stats_for_user($uid);
    return [
        'active' => (int)$jobStats['active'] + (int)$roomStats['active'],
        'completed' => (int)$jobStats['completed'] + (int)$roomStats['completed'],
        'unsuccessful' => (int)$jobStats['unsuccessful'] + (int)$roomStats['unsuccessful'],
    ];
}

function zinesh_trust_score_from_stats(array $stats, float $modifier = 0.0): float
{
    $base = 50.0;
    $score = $base
        + ((int)($stats['completed'] ?? 0)) * 1.5
        - ((int)($stats['unsuccessful'] ?? 0)) * 1.0
        + $modifier;
    return min(100.0, max(0.0, round($score, 2)));
}

function zinesh_recalc_trust_score(string $uid, string $email = ''): float
{
    if ($uid === '') {
        return 50.0;
    }
    $stats = zinesh_escrow_trust_stats_for_user($uid, $email);
    $user = zinesh_find_user_by_uid($uid);
    $modifier = (float)($user['trustScoreModifier'] ?? 0);
    $score = zinesh_trust_score_from_stats($stats, $modifier);
    zinesh_update_user($uid, static function (array &$u) use ($score) {
        $u['trustScore'] = $score;
    });
    return $score;
}

/** @return list<array<string,mixed>> */
function zinesh_escrow_trust_jobs_for_user(string $uid, string $email = ''): array
{
    $jobs = [];
    foreach (zinesh_escrow_jobs_for_user($uid, $email) as $job) {
        $jobs[] = zinesh_escrow_job_public_row($job, $uid);
    }
    if (!function_exists('zinesh_escrow_room_trust_jobs_for_user')) {
        require_once __DIR__ . '/escrow_room_lib.php';
    }
    foreach (zinesh_escrow_room_trust_jobs_for_user($uid) as $roomJob) {
        $jobs[] = $roomJob;
    }
    usort($jobs, static function (array $a, array $b) {
        $aTs = (string)($a['completedAt'] ?? $a['createdAt'] ?? '');
        $bTs = (string)($b['completedAt'] ?? $b['createdAt'] ?? '');
        return strcmp($bTs, $aTs);
    });
    return $jobs;
}

function zinesh_escrow_job_user_role(array $job, string $uid, string $email = ''): ?string
{
    if ((string)($job['buyerUid'] ?? '') === $uid) {
        return 'buyer';
    }
    if ((string)($job['supplierUid'] ?? '') === $uid) {
        return 'supplier';
    }
    $jobSupplierEmail = strtolower((string)($job['supplierEmail'] ?? ''));
    if ($email !== '' && $jobSupplierEmail !== '' && $jobSupplierEmail === strtolower($email)) {
        return 'supplier';
    }
    return null;
}

function zinesh_escrow_jobs_for_user(string $uid, string $email = ''): array
{
    $email = strtolower(trim($email));
    $out = [];
    foreach (zinesh_escrow_jobs_load() as $job) {
        if (zinesh_escrow_job_user_role($job, $uid, $email) !== null) {
            $out[] = $job;
        }
    }
    usort($out, static function (array $a, array $b) {
        return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
    });
    return $out;
}

function zinesh_escrow_job_public_row(array $job, ?string $viewerUid = null): array
{
    $status = (string)($job['status'] ?? 'active');
    $row = [
        'id' => (string)($job['id'] ?? ''),
        'matchCode' => (string)($job['matchCode'] ?? ''),
        'title' => (string)($job['title'] ?? ''),
        'description' => (string)($job['description'] ?? ''),
        'category' => (string)($job['category'] ?? 'Emanet'),
        'value' => (float)($job['value'] ?? 0),
        'status' => $status,
        'fundsLocked' => !empty($job['fundsLocked']) || $status === 'active',
        'buyerName' => (string)($job['buyerName'] ?? ''),
        'supplierName' => (string)($job['supplierName'] ?? ''),
        'deliveryDate' => (string)($job['deliveryDate'] ?? ''),
        'createdAt' => (string)($job['createdAt'] ?? ''),
        'matchedAt' => (string)($job['matchedAt'] ?? ''),
        'activatedAt' => (string)($job['activatedAt'] ?? ''),
        'completedAt' => (string)($job['completedAt'] ?? ''),
        'commission' => isset($job['commission']) ? (float)$job['commission'] : null,
        'payout' => isset($job['payout']) ? (float)$job['payout'] : null,
        'buyerConfirmedComplete' => !empty($job['buyerConfirmedComplete']),
        'supplierConfirmedComplete' => !empty($job['supplierConfirmedComplete']),
        'buyerCancelRequested' => !empty($job['buyerCancelRequested']),
        'supplierCancelRequested' => !empty($job['supplierCancelRequested']),
        'lockedValue' => (!empty($job['fundsLocked']) || $status === 'active' || $status === 'completion_pending')
            ? (float)($job['value'] ?? 0)
            : 0.0,
    ];
    if ($viewerUid !== null) {
        $row['myRole'] = zinesh_escrow_job_user_role(
            $job,
            $viewerUid,
            (string)(zinesh_find_user_by_uid($viewerUid)['email'] ?? '')
        );
        if ((string)($job['buyerUid'] ?? '') === $viewerUid) {
            $row['supplierEmail'] = (string)($job['supplierEmail'] ?? '');
        }
    }
    return $row;
}

function zinesh_escrow_job_history_for_public_profile(string $uid): array
{
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return [];
    }
    $historyStatuses = ['success', 'cancelled', 'failed', 'disputed'];
    $rows = [];
    foreach (zinesh_escrow_jobs_for_user($uid, (string)($user['email'] ?? '')) as $job) {
        $status = (string)($job['status'] ?? '');
        if (!in_array($status, $historyStatuses, true)) {
            continue;
        }
        $rows[] = zinesh_escrow_job_public_row($job);
    }
    return $rows;
}

/**
 * Emanet taslağı oluşturur.
 * - match-only (supplierEmail boş): status=pending_match, para henüz kilitlenmez.
 * - email ile klasik yol: status=active (çağıran katmanda bakiye kilitlenmiş olmalı).
 *
 * @return array{ok:bool,message?:string,job?:array}
 */
function zinesh_escrow_job_create(
    string $buyerUid,
    float $amount,
    string $title,
    string $description,
    string $supplierEmail = '',
    string $supplierName = '',
    string $deliveryDate = '',
    string $category = 'Emanet',
    bool $matchOnly = false
): array {
    $title = trim($title);
    $supplierEmail = strtolower(trim($supplierEmail));
    if ($amount <= 0 || $title === '') {
        return ['ok' => false, 'message' => 'Başlık ve tutar gerekli.'];
    }

    $buyer = zinesh_find_user_by_uid($buyerUid);
    if (!$buyer) {
        return ['ok' => false, 'message' => 'Alıcı hesabı bulunamadı.'];
    }

    $supplierUid = null;
    $resolvedSupplierName = trim($supplierName);
    $status = 'pending_match';
    $fundsLocked = false;

    if (!$matchOnly) {
        if ($supplierEmail === '') {
            return ['ok' => false, 'message' => 'Başlık, tutar ve karşı taraf e-postası gerekli.'];
        }
        if (!filter_var($supplierEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Geçerli bir karşı taraf e-postası gir.'];
        }
        $supplier = zinesh_find_user_by_email($supplierEmail);
        if ($supplier) {
            $supplierUid = (string)$supplier['uid'];
            if ($resolvedSupplierName === '') {
                $resolvedSupplierName = (string)($supplier['name'] ?? '');
            }
        }
        if ($supplierUid === $buyerUid) {
            return ['ok' => false, 'message' => 'Kendi hesabına emanet oluşturamazsın.'];
        }
        if ($resolvedSupplierName === '') {
            $resolvedSupplierName = explode('@', $supplierEmail)[0];
        }
        $status = 'active';
        $fundsLocked = true;
    }

    // Benzersiz kısa kod
    $matchCode = '';
    for ($try = 0; $try < 8; $try++) {
        $candidate = zinesh_escrow_job_new_match_code();
        if (zinesh_escrow_job_find_by_match_code($candidate) === null) {
            $matchCode = $candidate;
            break;
        }
    }
    if ($matchCode === '') {
        return ['ok' => false, 'message' => 'Emanet kodu üretilemedi. Tekrar dene.'];
    }

    $job = [
        'id' => zinesh_escrow_job_new_id(),
        'matchCode' => $matchCode,
        'buyerUid' => $buyerUid,
        'supplierUid' => $supplierUid,
        'buyerName' => (string)($buyer['name'] ?? 'Alıcı'),
        'supplierName' => $resolvedSupplierName !== '' ? $resolvedSupplierName : 'Eşleşme bekleniyor',
        'supplierEmail' => $supplierEmail,
        'title' => $title,
        'description' => trim($description),
        'category' => $category !== '' ? $category : 'Emanet',
        'deliveryDate' => trim($deliveryDate),
        'value' => round($amount, zinesh_escrow_uses_tl() ? 2 : 6),
        'status' => $status,
        'fundsLocked' => $fundsLocked,
        'createdAt' => date('c'),
        'matchedAt' => null,
        'activatedAt' => $fundsLocked ? date('c') : null,
        'completedAt' => null,
        'commission' => null,
        'payout' => null,
        'source' => 'escrow',
    ];

    $saved = false;
    zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use ($job, &$saved) {
        $jobs[] = $job;
        $saved = true;
        return true;
    });
    if (!$saved) {
        return ['ok' => false, 'message' => 'İş kaydı oluşturulamadı.'];
    }

    return ['ok' => true, 'job' => $job];
}

/**
 * Karşı taraf emanet kodu ile katılır → para kilitlenir → aktif olur.
 *
 * @return array{ok:bool,message?:string,job?:array,locked?:bool}
 */
function zinesh_escrow_job_join_by_code(string $code, string $supplierUid): array
{
    $code = zinesh_escrow_normalize_match_code($code);
    if ($code === '') {
        return ['ok' => false, 'message' => 'Emanet kodu gerekli.'];
    }

    $supplier = zinesh_find_user_by_uid($supplierUid);
    if (!$supplier) {
        return ['ok' => false, 'message' => 'Hesap bulunamadı.'];
    }

    $job = zinesh_escrow_job_find_by_match_code($code);
    if (!$job) {
        return ['ok' => false, 'message' => 'Bu koda ait emanet bulunamadı.'];
    }
    if ((string)($job['status'] ?? '') !== 'pending_match') {
        return ['ok' => false, 'message' => 'Bu emanet artık eşleşmeye açık değil.'];
    }
    if ((string)($job['buyerUid'] ?? '') === $supplierUid) {
        return ['ok' => false, 'message' => 'Kendi emanetine katılamazsın.'];
    }
    if (!empty($job['supplierUid'])) {
        return ['ok' => false, 'message' => 'Bu emanete zaten birileri katılmış.'];
    }

    $amount = (float)($job['value'] ?? 0);
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'Geçersiz emanet tutarı.'];
    }

    $buyerUid = (string)($job['buyerUid'] ?? '');
    $useTl = zinesh_escrow_uses_tl();
    $locked = false;

    // Önce bakiyeyi kilitle; sonra kaydı güncelle
    try {
        zinesh_update_user($buyerUid, static function (array &$u) use ($amount, $useTl) {
            zinesh_ensure_wallet_fields($u);
            if ($useTl) {
                $available = round((float)$u['usdtBalance'] - (float)$u['escrowBalance'], 2);
                if ($available + 1e-9 < $amount) {
                    zinesh_json_response(['message' => 'Alıcının bakiyesi yetersiz. Emanet henüz aktifleştirilemedi.'], 400);
                }
            } else {
                $available = round((float)$u['fiziBalance'] - (float)$u['escrowBalance'], 6);
                if ($available + 1e-9 < $amount) {
                    zinesh_json_response(['message' => 'Alıcının bakiyesi yetersiz. Emanet henüz aktifleştirilemedi.'], 400);
                }
            }
            $u['escrowBalance'] = round((float)$u['escrowBalance'] + $amount, $useTl ? 2 : 6);
        });
        $locked = true;
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Para kilitlenemedi. Alıcı bakiyesini kontrol etsin.'];
    }

    $updated = null;
    $supplierName = (string)($supplier['name'] ?? 'Hizmet veren');
    $supplierEmail = strtolower((string)($supplier['email'] ?? ''));
    $jobId = (string)($job['id'] ?? '');

    zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use (
        $jobId,
        $supplierUid,
        $supplierName,
        $supplierEmail,
        &$updated
    ) {
        foreach ($jobs as $i => $row) {
            if ((string)($row['id'] ?? '') !== $jobId) {
                continue;
            }
            if ((string)($row['status'] ?? '') !== 'pending_match' || !empty($row['supplierUid'])) {
                zinesh_json_response(['message' => 'Bu emanet artık eşleşmeye açık değil.'], 409);
            }
            $jobs[$i]['supplierUid'] = $supplierUid;
            $jobs[$i]['supplierName'] = $supplierName;
            $jobs[$i]['supplierEmail'] = $supplierEmail;
            $jobs[$i]['status'] = 'active';
            $jobs[$i]['fundsLocked'] = true;
            $jobs[$i]['matchedAt'] = date('c');
            $jobs[$i]['activatedAt'] = date('c');
            $updated = $jobs[$i];
            return true;
        }
        zinesh_json_response(['message' => 'Emanet işi bulunamadı.'], 404);
        return false;
    });

    if (!$updated) {
        if ($locked) {
            zinesh_update_user($buyerUid, static function (array &$u) use ($amount, $useTl) {
                zinesh_ensure_wallet_fields($u);
                $u['escrowBalance'] = round(max(0, (float)$u['escrowBalance'] - $amount), $useTl ? 2 : 6);
            });
        }
        return ['ok' => false, 'message' => 'Eşleşme kaydedilemedi.'];
    }

    if ($useTl) {
        zinesh_log_tl_escrow_lock($buyerUid, $supplierUid, $jobId, $amount, 0.0);
    }

    return ['ok' => true, 'job' => $updated, 'locked' => true];
}

/**
 * @return array{ok:bool,message?:string,job?:array}
 */
function zinesh_escrow_job_mark_success(
    string $jobId,
    float $commission,
    float $payout
): array {
    $updated = null;
    zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use ($jobId, $commission, $payout, &$updated) {
        foreach ($jobs as $i => $job) {
            if ((string)($job['id'] ?? '') !== $jobId) {
                continue;
            }
            if ((string)($job['status'] ?? '') !== 'active' && (string)($job['status'] ?? '') !== 'completion_pending') {
                zinesh_json_response(['message' => 'Bu iş zaten kapatılmış veya henüz aktif değil.'], 400);
            }
            $jobs[$i]['status'] = 'success';
            $jobs[$i]['completedAt'] = date('c');
            $jobs[$i]['commission'] = $commission;
            $jobs[$i]['payout'] = $payout;
            $updated = $jobs[$i];
            return true;
        }
        zinesh_json_response(['message' => 'Emanet işi bulunamadı.'], 404);
        return false;
    });
    return $updated ? ['ok' => true, 'job' => $updated] : ['ok' => false, 'message' => 'İş güncellenemedi.'];
}

/**
 * @return array{ok:bool,message?:string,job?:array}
 */
function zinesh_escrow_job_mark_terminal(string $jobId, string $status): array
{
    if (!in_array($status, ['cancelled', 'failed', 'disputed'], true)) {
        return ['ok' => false, 'message' => 'Geçersiz durum.'];
    }
    $updated = null;
    zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use ($jobId, $status, &$updated) {
        foreach ($jobs as $i => $job) {
            if ((string)($job['id'] ?? '') !== $jobId) {
                continue;
            }
            $current = (string)($job['status'] ?? '');
            if (!in_array($current, ['active', 'pending_match', 'completion_pending'], true)) {
                zinesh_json_response(['message' => 'Bu iş zaten kapatılmış.'], 400);
            }
            $jobs[$i]['status'] = $status;
            $jobs[$i]['completedAt'] = date('c');
            $updated = $jobs[$i];
            return true;
        }
        zinesh_json_response(['message' => 'Emanet işi bulunamadı.'], 404);
        return false;
    });
    return $updated ? ['ok' => true, 'job' => $updated] : ['ok' => false, 'message' => 'İş güncellenemedi.'];
}

/**
 * @return array{ok:bool,message?:string,job?:array,wallet?:array}
 */
function zinesh_escrow_job_finalize_settlement(string $jobId): array
{
    $job = zinesh_escrow_job_find($jobId);
    if (!$job) {
        return ['ok' => false, 'message' => 'Emanet işi bulunamadı.'];
    }

    $status = (string)($job['status'] ?? '');
    if ($status === 'success') {
        $buyerUid = (string)($job['buyerUid'] ?? '');
        $buyer = zinesh_find_user_by_uid($buyerUid);
        return [
            'ok' => true,
            'job' => zinesh_escrow_job_public_row($job, $buyerUid),
            'wallet' => $buyer ? zinesh_wallet_state($buyer) : null,
            'message' => 'İş zaten tamamlanmış.',
        ];
    }
    if ($status === 'settling') {
        return ['ok' => false, 'message' => 'Ödeme işleniyor. Lütfen birkaç saniye sonra tekrar deneyin.'];
    }
    if (!in_array($status, ['active', 'completion_pending'], true)) {
        return ['ok' => false, 'message' => 'Bu iş zaten kapatılmış.'];
    }

    $value = (float)($job['value'] ?? 0);
    $supplierEmail = strtolower(trim((string)($job['supplierEmail'] ?? '')));
    $buyerUid = (string)($job['buyerUid'] ?? '');
    if ($value <= 0 || $supplierEmail === '' || $buyerUid === '') {
        return ['ok' => false, 'message' => 'Geçersiz iş bilgisi.'];
    }

    $commissionRate = 0.05;
    $useTl = zinesh_escrow_uses_tl();
    $commission = $useTl ? round($value * $commissionRate, 2) : floor($value * $commissionRate);
    $payout = round($value - $commission, $useTl ? 2 : 6);

    $supplierUid = (string)($job['supplierUid'] ?? '');
    if ($supplierUid === '') {
        foreach (zinesh_load_users() as $u) {
            if (strtolower((string)($u['email'] ?? '')) === $supplierEmail) {
                $supplierUid = (string)($u['uid'] ?? '');
                break;
            }
        }
    }
    if ($supplierUid === '') {
        return ['ok' => false, 'message' => 'İş alan hesabı bulunamadı.'];
    }

    $previousStatus = $status;
    $buyerBefore = zinesh_find_user_by_uid($buyerUid);
    $buyerUsdtBefore = $buyerBefore && $useTl
        ? round((float)($buyerBefore['usdtBalance'] ?? 0), 2)
        : null;

    $settlingClaimed = zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use ($jobId, $previousStatus) {
        foreach ($jobs as $i => $row) {
            if ((string)($row['id'] ?? '') !== $jobId) {
                continue;
            }
            $current = (string)($row['status'] ?? '');
            if ($current === 'success' || $current === 'settling') {
                return false;
            }
            if (!in_array($current, ['active', 'completion_pending'], true)) {
                return false;
            }
            if ($current !== $previousStatus) {
                return false;
            }
            $jobs[$i]['status'] = 'settling';
            $jobs[$i]['settlingAt'] = date('c');
            return true;
        }
        return false;
    });

    if (!$settlingClaimed) {
        $fresh = zinesh_escrow_job_find($jobId);
        if ($fresh && (string)($fresh['status'] ?? '') === 'success') {
            $buyer = zinesh_find_user_by_uid($buyerUid);
            return [
                'ok' => true,
                'job' => zinesh_escrow_job_public_row($fresh, $buyerUid),
                'wallet' => $buyer ? zinesh_wallet_state($buyer) : null,
                'message' => 'İş zaten tamamlanmış.',
            ];
        }
        return ['ok' => false, 'message' => 'Ödeme zaten işlendi veya iş durumu uygun değil.'];
    }

    $walletSettled = false;
    try {
        if ($useTl) {
            zinesh_wallet_apply_tl_escrow_settlement($buyerUid, $supplierUid, $value, $payout);

            $buyerAfter = zinesh_find_user_by_uid($buyerUid);
            if ($buyerAfter === null) {
                throw new RuntimeException('İşveren cüzdanı doğrulanamadı.');
            }
            $buyerUsdtAfter = round((float)($buyerAfter['usdtBalance'] ?? 0), 2);
            $expectedUsdt = $buyerUsdtBefore !== null
                ? round(max(0, $buyerUsdtBefore - $value), 2)
                : null;
            if ($expectedUsdt !== null && abs($buyerUsdtAfter - $expectedUsdt) > 0.01) {
                zinesh_audit('escrow_job_settlement_debit_mismatch', [
                    'jobId' => $jobId,
                    'buyerUid' => $buyerUid,
                    'expectedUsdt' => $expectedUsdt,
                    'actualUsdt' => $buyerUsdtAfter,
                    'lockAmount' => $value,
                ]);
                throw new RuntimeException('Gönderen bakiyesi düşürülemedi (muhasebe doğrulama).');
            }
        } else {
            zinesh_json_atomic('users.json', static function (array &$users) use ($buyerUid, $supplierUid, $value, $payout) {
                $buyerIdx = $supplierIdx = null;
                foreach ($users as $i => $u) {
                    if (($u['uid'] ?? '') === $buyerUid) {
                        $buyerIdx = $i;
                    }
                    if (($u['uid'] ?? '') === $supplierUid) {
                        $supplierIdx = $i;
                    }
                }
                if ($buyerIdx === null || $supplierIdx === null) {
                    zinesh_wallet_abort('Taraflar bulunamadı.');
                }
                zinesh_ensure_wallet_fields($users[$buyerIdx]);
                zinesh_ensure_wallet_fields($users[$supplierIdx]);
                if ((float)$users[$buyerIdx]['escrowBalance'] + 1e-9 < $value) {
                    zinesh_wallet_abort('Kilitli escrow tutarı yetersiz.');
                }
                if ((float)$users[$buyerIdx]['fiziBalance'] + 1e-9 < $value) {
                    zinesh_wallet_abort('FİZİ bakiyesi yetersiz.');
                }
                $users[$buyerIdx]['escrowBalance'] = round((float)$users[$buyerIdx]['escrowBalance'] - $value, 6);
                $users[$buyerIdx]['fiziBalance'] = round((float)$users[$buyerIdx]['fiziBalance'] - $value, 6);
                $users[$supplierIdx]['fiziBalance'] = round((float)$users[$supplierIdx]['fiziBalance'] + $payout, 6);
                return true;
            });
        }
        $walletSettled = true;

        zinesh_distribute_commission((float)$commission, 'job', $useTl ? 'TL' : 'FIZI');

        if ($useTl) {
            zinesh_log_tl_escrow_settlement($buyerUid, $supplierUid, $jobId, $value, $payout);
        }

        $marked = zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use ($jobId, $commission, $payout) {
            foreach ($jobs as $i => $job) {
                if ((string)($job['id'] ?? '') !== $jobId) {
                    continue;
                }
                if ((string)($job['status'] ?? '') !== 'settling') {
                    return false;
                }
                $jobs[$i]['status'] = 'success';
                $jobs[$i]['completedAt'] = date('c');
                $jobs[$i]['commission'] = $commission;
                $jobs[$i]['payout'] = $payout;
                unset($jobs[$i]['settlingAt']);
                return true;
            }
            return false;
        });

        if (!$marked) {
            zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use ($jobId, $commission, $payout) {
                foreach ($jobs as $i => $job) {
                    if ((string)($job['id'] ?? '') !== $jobId) {
                        continue;
                    }
                    $jobs[$i]['status'] = 'success';
                    $jobs[$i]['completedAt'] = date('c');
                    $jobs[$i]['commission'] = $commission;
                    $jobs[$i]['payout'] = $payout;
                    unset($jobs[$i]['settlingAt']);
                    return true;
                }
                return false;
            });
        }
    } catch (Throwable $e) {
        if (!$walletSettled) {
            zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use ($jobId, $previousStatus) {
                foreach ($jobs as $i => $row) {
                    if ((string)($row['id'] ?? '') !== $jobId) {
                        continue;
                    }
                    if ((string)($row['status'] ?? '') === 'settling') {
                        $jobs[$i]['status'] = $previousStatus;
                        unset($jobs[$i]['settlingAt']);
                    }
                    return true;
                }
                return false;
            });
        }
        return ['ok' => false, 'message' => 'Ödeme işlenemedi: ' . $e->getMessage()];
    }

    zinesh_recalc_trust_score($buyerUid);
    if ($supplierUid) {
        zinesh_recalc_trust_score($supplierUid);
    }

    require_once __DIR__ . '/campaign_lib.php';
    zinesh_campaign_record_job_complete($buyerUid);
    if ($supplierUid) {
        zinesh_campaign_record_job_complete($supplierUid);
    }

    $buyer = zinesh_find_user_by_uid($buyerUid);
    $updated = zinesh_escrow_job_find($jobId);
    return [
        'ok' => true,
        'job' => $updated ? zinesh_escrow_job_public_row($updated, $buyerUid) : null,
        'wallet' => $buyer ? zinesh_wallet_state($buyer) : null,
        'message' => 'Ödeme serbest bırakıldı.',
    ];
}

/**
 * @return array{ok:bool,message?:string,job?:array,wallet?:array}
 */
function zinesh_escrow_job_confirm_complete(array $user, string $jobId): array
{
    $job = zinesh_escrow_job_find($jobId);
    if (!$job) {
        return ['ok' => false, 'message' => 'Emanet işi bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    $email = (string)($user['email'] ?? '');
    $role = zinesh_escrow_job_user_role($job, $uid, $email);
    if ($role === null) {
        return ['ok' => false, 'message' => 'Bu işe erişimin yok.'];
    }
    if (!in_array((string)($job['status'] ?? ''), ['active', 'completion_pending'], true)) {
        return ['ok' => false, 'message' => 'Bu aşamada onay verilemez.'];
    }

    $buyerConfirmed = !empty($job['buyerConfirmedComplete']);
    $supplierConfirmed = !empty($job['supplierConfirmedComplete']);
    if ($role === 'buyer') {
        $buyerConfirmed = true;
    } else {
        $supplierConfirmed = true;
    }

    if ($buyerConfirmed && $supplierConfirmed) {
        return zinesh_escrow_job_finalize_settlement($jobId);
    }

    $updated = null;
    zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use (
        $jobId,
        $buyerConfirmed,
        $supplierConfirmed,
        &$updated
    ) {
        foreach ($jobs as $i => $row) {
            if ((string)($row['id'] ?? '') !== $jobId) {
                continue;
            }
            $jobs[$i]['buyerConfirmedComplete'] = $buyerConfirmed;
            $jobs[$i]['supplierConfirmedComplete'] = $supplierConfirmed;
            $jobs[$i]['status'] = 'completion_pending';
            $updated = $jobs[$i];
            return true;
        }
        return false;
    });

    return [
        'ok' => true,
        'job' => $updated ? zinesh_escrow_job_public_row($updated, $uid) : null,
        'message' => 'Onayın alındı. Karşı tarafın onayı bekleniyor.',
    ];
}

/**
 * @return array{ok:bool,message?:string,job?:array,wallet?:array}
 */
function zinesh_escrow_job_request_cancel(array $user, string $jobId): array
{
    $job = zinesh_escrow_job_find($jobId);
    if (!$job) {
        return ['ok' => false, 'message' => 'Emanet işi bulunamadı.'];
    }
    $uid = (string)($user['uid'] ?? '');
    $email = (string)($user['email'] ?? '');
    $role = zinesh_escrow_job_user_role($job, $uid, $email);
    if ($role === null) {
        return ['ok' => false, 'message' => 'Bu işe erişimin yok.'];
    }

    $status = (string)($job['status'] ?? '');
    if ($status === 'pending_match') {
        if ($role !== 'buyer') {
            return ['ok' => false, 'message' => 'Eşleşme beklerken yalnızca işveren iptal edebilir.'];
        }
        zinesh_escrow_job_mark_terminal($jobId, 'cancelled');
        zinesh_recalc_trust_score($uid, $email);
        $buyer = zinesh_find_user_by_uid($uid);
        return [
            'ok' => true,
            'wallet' => $buyer ? zinesh_wallet_state($buyer) : null,
            'message' => 'Emanet iptal edildi.',
        ];
    }

    if (!in_array($status, ['active', 'completion_pending'], true)) {
        return ['ok' => false, 'message' => 'Bu iş iptal edilemez.'];
    }

    $buyerCancel = !empty($job['buyerCancelRequested']);
    $supplierCancel = !empty($job['supplierCancelRequested']);
    if ($role === 'buyer') {
        $buyerCancel = true;
    } else {
        $supplierCancel = true;
    }

    if (!$buyerCancel || !$supplierCancel) {
        $updated = null;
        zinesh_json_atomic('escrow_jobs.json', static function (array &$jobs) use (
            $jobId,
            $buyerCancel,
            $supplierCancel,
            &$updated
        ) {
            foreach ($jobs as $i => $row) {
                if ((string)($row['id'] ?? '') !== $jobId) {
                    continue;
                }
                $jobs[$i]['buyerCancelRequested'] = $buyerCancel;
                $jobs[$i]['supplierCancelRequested'] = $supplierCancel;
                $updated = $jobs[$i];
                return true;
            }
            return false;
        });
        return [
            'ok' => true,
            'job' => $updated ? zinesh_escrow_job_public_row($updated, $uid) : null,
            'message' => 'İptal talebin alındı. Karşı tarafın iptal onayı bekleniyor.',
        ];
    }

    $amount = (float)($job['value'] ?? 0);
    $buyerUid = (string)($job['buyerUid'] ?? '');
    $useTl = zinesh_escrow_uses_tl();
    if ($buyerUid !== '' && $amount > 0) {
        zinesh_update_user($buyerUid, static function (array &$u) use ($amount, $useTl) {
            zinesh_ensure_wallet_fields($u);
            if ((float)$u['escrowBalance'] + 1e-9 >= $amount) {
                $u['escrowBalance'] = round((float)$u['escrowBalance'] - $amount, $useTl ? 2 : 6);
            }
        });
    }
    zinesh_escrow_job_mark_terminal($jobId, 'cancelled');
    $supplierUid = (string)($job['supplierUid'] ?? '');
    if ($buyerUid !== '') {
        zinesh_recalc_trust_score($buyerUid);
    }
    if ($supplierUid !== '') {
        zinesh_recalc_trust_score($supplierUid);
    }
    $user = zinesh_find_user_by_uid($uid) ?? $user;
    return [
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'message' => 'Her iki taraf onayladı — emanet iptal edildi, kilit kaldırıldı.',
    ];
}

function zinesh_escrow_job_stats_for_user(string $uid, string $email = ''): array
{
    $active = 0;
    $success = 0;
    $failed = 0;
    foreach (zinesh_escrow_jobs_for_user($uid, $email) as $job) {
        $status = (string)($job['status'] ?? '');
        if ($status === 'active' || $status === 'pending_match' || $status === 'completion_pending') {
            $active++;
        } elseif ($status === 'success') {
            $success++;
        } elseif (in_array($status, ['cancelled', 'failed', 'disputed'], true)) {
            $failed++;
        }
    }
    return [
        'active' => $active,
        'completed' => $success,
        'unsuccessful' => $failed,
    ];
}

function zinesh_public_trust_profile(string $uid, ?string $viewerUid = null): ?array
{
    $user = zinesh_find_user_by_uid($uid);
    if (!$user || !empty($user['isSystemWallet'])) {
        return null;
    }
    zinesh_ensure_profile_fields($user);
    $email = (string)($user['email'] ?? '');
    $trustScore = zinesh_recalc_trust_score($uid, $email);
    $user = zinesh_find_user_by_uid($uid) ?? $user;
    $stats = zinesh_escrow_trust_stats_for_user($uid, $email);
    $historyPublic = !array_key_exists('jobHistoryPublic', $user) || !empty($user['jobHistoryPublic']);
    $profile = [
        'uid' => $uid,
        'name' => (string)($user['name'] ?? ''),
        'trustScore' => $trustScore,
        'jobHistoryPublic' => $historyPublic,
        'referralCode' => (string)($user['referralCode'] ?? ''),
        'kycApproved' => ((string)($user['kycStatus'] ?? '') === 'approved'),
        'emailVerified' => !empty($user['emailVerified']),
        'stats' => $stats,
        'expertise' => array_values(array_filter((array)($user['expertise'] ?? []))),
    ];
    $isOwner = $viewerUid !== null && $viewerUid === $uid;
    if ($isOwner || $historyPublic) {
        $historyStatuses = ['success', 'cancelled', 'failed', 'disputed', 'completion_pending'];
        $jobs = [];
        foreach (zinesh_escrow_trust_jobs_for_user($uid, $email) as $job) {
            $status = (string)($job['status'] ?? '');
            if ($isOwner || in_array($status, $historyStatuses, true)) {
                $jobs[] = $job;
            }
        }
        $profile['jobs'] = $jobs;
    } else {
        $profile['jobsHidden'] = true;
        $profile['jobs'] = [];
    }
    return $profile;
}
