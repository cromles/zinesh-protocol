<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';

function zinesh_kyc_national_id_hash(string $nationalId): string {
    return hash('sha256', preg_replace('/\D/', '', $nationalId));
}

/**
 * Bot / sahte numara kalıpları: 11111111111, 5555555555, 12345678901 vb.
 */
function zinesh_kyc_reject_obvious_fake_number(string $digits): ?string {
    $digits = preg_replace('/\D/', '', $digits);
    $len = strlen($digits);
    if ($len < 6) {
        return null;
    }

    if (preg_match('/^(\d)\1+$/', $digits)) {
        return 'Aynı rakamın tekrar ettiği numaralar kabul edilmez.';
    }

    $asc = true;
    $desc = true;
    for ($i = 1; $i < $len; $i++) {
        $prev = (int)$digits[$i - 1];
        $cur = (int)$digits[$i];
        if ($cur !== $prev + 1) {
            $asc = false;
        }
        if ($cur !== $prev - 1) {
            $desc = false;
        }
    }
    if ($asc || $desc) {
        return 'Ardışık rakamlardan oluşan numaralar (123456… / 987654…) kabul edilmez.';
    }

    if ($len >= 8 && count(array_unique(str_split($digits))) <= 2) {
        return 'Geçersiz numara formatı — çok az farklı rakam içeriyor.';
    }

    $blocked = [
        '12345678901',
        '12345678910',
        '12345678902',
        '98765432109',
        '98765432101',
        '11111111110',
        '00000000000',
    ];
    if (in_array($digits, $blocked, true)) {
        return 'Bu numara kabul edilmez.';
    }

    return null;
}

function zinesh_kyc_validate_tckn(string $nationalId): bool {
    $tckn = preg_replace('/\D/', '', $nationalId);
    if (!preg_match('/^[1-9][0-9]{10}$/', $tckn)) {
        return false;
    }
    if (zinesh_kyc_reject_obvious_fake_number($tckn) !== null) {
        return false;
    }
    $d = array_map('intval', str_split($tckn));
    $d10 = (($d[0] + $d[2] + $d[4] + $d[6] + $d[8]) * 7 - ($d[1] + $d[3] + $d[5] + $d[7])) % 10;
    if ($d10 < 0) {
        $d10 += 10;
    }
    if ($d[9] !== $d10) {
        return false;
    }
    $d11 = array_sum(array_slice($d, 0, 10)) % 10;
    return $d[10] === $d11;
}

function zinesh_kyc_find_uid_by_national_hash(string $hash): ?string {
    foreach (zinesh_load_users() as $u) {
        if (($u['nationalIdHash'] ?? '') === $hash) {
            return (string)($u['uid'] ?? '');
        }
    }
    return null;
}

function zinesh_kyc_strip_private_fields(array $user): array {
    unset($user['nationalIdHash'], $user['kycProfile']);
    return $user;
}

/**
 * @return array{ok:bool, message:string, user:?array, grant:?array, reason:?string}
 */
function zinesh_kyc_submit(string $uid, array $input): array {
    $user = zinesh_find_user_by_uid($uid);
    if (!$user) {
        return ['ok' => false, 'message' => 'Hesap bulunamadı.', 'user' => null, 'grant' => null, 'reason' => 'user_not_found'];
    }

    $status = (string)($user['kycStatus'] ?? 'none');
    if ($status === 'approved') {
        $grant = zinesh_campaign_claim_reward($uid, 'founding_kyc');
        $user = zinesh_find_user_by_uid($uid);
        return [
            'ok' => true,
            'message' => !empty($grant['claimed'])
                ? 'Kimlik doğrulaman tamamlandı.'
                : 'KYC zaten onaylı.',
            'user' => $user,
            'grant' => $grant,
            'reason' => null,
        ];
    }

    if (empty($user['emailVerified'])) {
        return ['ok' => false, 'message' => 'Önce e-postanı doğrula.', 'user' => null, 'grant' => null, 'reason' => 'email_not_verified'];
    }

    if (empty($user['foundingMember'])) {
        return ['ok' => false, 'message' => 'Kimlik doğrulama için önce hesap kurulum adımlarını tamamla.', 'user' => null, 'grant' => null, 'reason' => 'not_founding_member'];
    }

    $fullName = trim((string)($input['fullName'] ?? ''));
    $nationalId = preg_replace('/\D/', '', (string)($input['nationalId'] ?? ''));
    $birthDate = trim((string)($input['birthDate'] ?? ''));
    $phone = preg_replace('/\D/', '', (string)($input['phone'] ?? ''));

    if ($fullName === '' || strlen(trim($fullName)) < 3) {
        return ['ok' => false, 'message' => 'Ad soyad en az 3 karakter olmalı.', 'user' => null, 'grant' => null, 'reason' => 'invalid_name'];
    }
    if (!zinesh_kyc_validate_tckn($nationalId)) {
        $fakeMsg = zinesh_kyc_reject_obvious_fake_number($nationalId);
        return [
            'ok' => false,
            'message' => $fakeMsg ?? 'Geçerli 11 haneli T.C. kimlik numarası gir.',
            'user' => null,
            'grant' => null,
            'reason' => $fakeMsg ? 'fake_national_id' : 'invalid_national_id',
        ];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate)) {
        return ['ok' => false, 'message' => 'Doğum tarihini YYYY-AA-GG formatında gir.', 'user' => null, 'grant' => null, 'reason' => 'invalid_birth_date'];
    }
    if (strlen($phone) < 10 || strlen($phone) > 11) {
        return ['ok' => false, 'message' => 'Geçerli bir telefon numarası gir.', 'user' => null, 'grant' => null, 'reason' => 'invalid_phone'];
    }
    $fakePhone = zinesh_kyc_reject_obvious_fake_number($phone);
    if ($fakePhone !== null) {
        return ['ok' => false, 'message' => $fakePhone, 'user' => null, 'grant' => null, 'reason' => 'fake_phone'];
    }

    $idHash = zinesh_kyc_national_id_hash($nationalId);
    $existingUid = zinesh_kyc_find_uid_by_national_hash($idHash);
    if ($existingUid !== null && $existingUid !== $uid) {
        return ['ok' => false, 'message' => 'Bu kimlik numarası başka bir hesapta kayıtlı.', 'user' => null, 'grant' => null, 'reason' => 'national_id_taken'];
    }

    zinesh_update_user($uid, static function (array &$u) use ($fullName, $birthDate, $phone, $idHash) {
        $u['kycStatus'] = 'pending';
        $u['kycSubmittedAt'] = date('c');
        $u['nationalIdHash'] = $idHash;
        $u['kycProfile'] = [
            'fullName' => $fullName,
            'birthDate' => $birthDate,
            'phone' => $phone,
            'country' => 'TR',
        ];
    });

    $claim = zinesh_campaign_claim_kyc($uid);
    $user = zinesh_find_user_by_uid($uid);
    zinesh_audit('kyc_submitted', ['uid' => $uid, 'grant' => $claim['claimed'] ?? false]);

    if (!($claim['claimed'] ?? false)) {
        $reason = (string)($claim['reason'] ?? 'claim_failed');
        $messages = [
            'already_claimed' => 'Kimlik doğrulama adımı zaten tamamlanmış.',
            'pool_depleted' => 'Kimlik doğrulama şu an kullanılamıyor. Daha sonra tekrar dene.',
            'not_founding_member' => 'Kimlik doğrulama için önce hesap kurulum adımlarını tamamla.',
            'kyc_not_approved' => 'KYC onayı tamamlanamadı.',
        ];
        return [
            'ok' => false,
            'message' => $messages[$reason] ?? 'Kimlik doğrulama şu an tamamlanamadı. Daha sonra tekrar dene.',
            'user' => $user,
            'grant' => $claim,
            'reason' => $reason,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Kimlik doğrulama başvurun alındı.',
        'user' => $user,
        'grant' => $claim,
        'reason' => null,
    ];
}
