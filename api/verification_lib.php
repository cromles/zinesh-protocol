<?php
declare(strict_types=1);

require_once __DIR__ . '/founder_lib.php';

function zinesh_user_email_verified(array $user): bool
{
    return !empty($user['emailVerified']);
}

function zinesh_user_phone_verified(array $user): bool
{
    // Telefon doğrulaması geçici olarak devre dışı — sözleşme kapısında zorunlu değil.
    return true;
}

function zinesh_user_kyc_verified(array $user): bool
{
    if (function_exists('zinesh_is_founder') && zinesh_is_founder($user)) {
        return true;
    }
    return ((string)($user['kycStatus'] ?? '')) === 'approved';
}

/** Geçici QA bypass — yeni iş/sözleşme kapısında KYC zorunlu değil (2026-08-14'e kadar). */
function zinesh_contract_kyc_gate_enabled(): bool
{
    return time() >= strtotime('2026-08-14 00:00:00 Europe/Istanbul');
}

/**
 * @return array{
 *   ok:bool,
 *   email_verified:bool,
 *   phone_verified:bool,
 *   kyc_verified:bool,
 *   missing:list<string>
 * }
 */
function zinesh_contract_verification_status(array $user): array
{
    $emailOk = zinesh_user_email_verified($user);
    $phoneOk = zinesh_user_phone_verified($user);
    $kycOk = zinesh_user_kyc_verified($user);
    $missing = [];
    if (!$emailOk) {
        $missing[] = 'email';
    }
    if (!$phoneOk) {
        $missing[] = 'phone';
    }
    if (zinesh_contract_kyc_gate_enabled() && !$kycOk) {
        $missing[] = 'kyc';
    }

    return [
        'ok' => $missing === [],
        'email_verified' => $emailOk,
        'phone_verified' => $phoneOk,
        'kyc_verified' => $kycOk,
        'missing' => $missing,
    ];
}

/** Yeni sözleşme / iş başlatma için zorunlu doğrulamalar. */
function zinesh_require_contract_verification(array $user): void
{
    $status = zinesh_contract_verification_status($user);
    if ($status['ok']) {
        return;
    }

    $labels = [
        'email' => 'e-posta',
        'phone' => 'telefon',
        'kyc' => 'kimlik (KYC)',
    ];
    $parts = array_map(static fn(string $key) => $labels[$key] ?? $key, $status['missing']);
    $message = 'Yeni iş veya sözleşme başlatmak için '
        . implode(', ', $parts)
        . ' doğrulamasını tamamlamanız gerekir.';

    zinesh_json_response([
        'ok' => false,
        'code' => 'verification_required',
        'message' => $message,
        'email_verified' => $status['email_verified'],
        'phone_verified' => $status['phone_verified'],
        'kyc_verified' => $status['kyc_verified'],
        'missing' => $status['missing'],
    ], 403);
}

function zinesh_verification_public_fields(array $user): array
{
    $status = zinesh_contract_verification_status($user);
    $phone = preg_replace('/\D/', '', (string)($user['phone'] ?? ''));
    $masked = '';
    if ($phone !== '' && strlen($phone) >= 4) {
        $masked = '***' . substr($phone, -4);
    }

    return [
        'emailVerified' => $status['email_verified'],
        'phoneVerified' => $status['phone_verified'],
        'kycVerified' => $status['kyc_verified'],
        'contractVerificationReady' => $status['ok'],
        'phoneMasked' => $masked,
    ];
}
