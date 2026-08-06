<?php
declare(strict_types=1);

/** @return array<string,mixed> */
function zinesh_early_access_defaults(): array {
    return [
        'enabled' => true,
        'title' => 'Erken Erişim Dönemi',
        'max_users' => 500,
        'max_fizi_per_user' => 500,
        'min_swap_usdt' => 1.0,
        'sell_requires_deposit' => true,
        'sell_requires_referrals' => 3,
        'sale_requires_current_deposit' => false,
    ];
}

/** @return array<string,mixed> */
function zinesh_early_access_cfg(): array {
    $base = zinesh_config()['early_access'] ?? [];
    $defaults = zinesh_early_access_defaults();
    $merged = array_merge($defaults, is_array($base) ? $base : []);
    $override = zinesh_json_read('early_access.json');
    if (!empty($override)) {
        $merged = array_merge($merged, $override);
    }
    return $merged;
}

/** @param array<string,mixed> $patch */
function zinesh_early_access_save(array $patch): array {
    $current = zinesh_early_access_cfg();
    $allowed = array_keys(zinesh_early_access_defaults());
    foreach ($patch as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        if (in_array($key, ['enabled', 'sell_requires_deposit', 'sale_requires_current_deposit'], true)) {
            $current[$key] = (bool)$value;
        } elseif ($key === 'title') {
            $current[$key] = trim((string)$value);
        } else {
            $current[$key] = is_numeric($value) ? (float)$value : $value;
        }
    }
    $current['updatedAt'] = date('c');
    zinesh_json_write('early_access.json', $current);
    return $current;
}

function zinesh_early_access_active(): bool {
    $cfg = zinesh_early_access_cfg();
    return !empty($cfg['enabled']);
}

function zinesh_early_access_min_swap_usdt(): float {
    return max(0.01, (float)(zinesh_early_access_cfg()['min_swap_usdt'] ?? 1.0));
}

/** @return array{ok:bool,message?:string} */
function zinesh_early_access_validate_swap_amount(string $mode, float $amount, float $fiziPrice): array {
    $minUsdt = zinesh_early_access_min_swap_usdt();
    if ($amount <= 0) {
        return ['ok' => false, 'message' => 'Geçerli bir miktar girin.'];
    }
    if ($mode === 'buy') {
        if ($amount + 1e-9 < $minUsdt) {
            return [
                'ok' => false,
                'message' => sprintf('Erken erişimde minimum alım tutarı $%s USDT.', number_format($minUsdt, 2)),
            ];
        }
        return ['ok' => true];
    }
    if ($mode === 'sell') {
        $grossUsdt = $amount * max(0.0, $fiziPrice);
        if ($grossUsdt + 1e-9 < $minUsdt) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'Erken erişimde minimum satış tutarı $%s USDT (≈ %s FİZİ).',
                    number_format($minUsdt, 2),
                    $fiziPrice > 0 ? number_format($minUsdt / $fiziPrice, 2) : '—'
                ),
            ];
        }
        return ['ok' => true];
    }
    return ['ok' => true];
}

function zinesh_user_has_verified_deposit(array $user): bool {
    if (!function_exists('zinesh_campaign_ensure_user_fields')) {
        require_once __DIR__ . '/campaign_lib.php';
    }
    zinesh_ensure_wallet_fields($user);
    zinesh_campaign_ensure_user_fields($user);
    $cfg = zinesh_early_access_cfg();
    if (!empty($cfg['sale_requires_current_deposit'])) {
        return (float)($user['usdtBalance'] ?? 0) > 0;
    }
    return (float)($user['campaignStats']['verifiedDepositUsdt'] ?? 0) > 0;
}

function zinesh_user_verified_referral_count(array $user): int {
    if (!function_exists('zinesh_campaign_verified_referral_count')) {
        require_once __DIR__ . '/campaign_lib.php';
    }
    return zinesh_campaign_verified_referral_count($user);
}

/** @return array{allowed:bool,reason:?string,requiresDeposit:bool,requiresReferrals:int,hasDeposit:bool,referralCount:int} */
function zinesh_early_access_sell_status(array $user): array {
    $cfg = zinesh_early_access_cfg();
    $requiresDeposit = !empty($cfg['sell_requires_deposit']);
    $requiresReferrals = (int)($cfg['sell_requires_referrals'] ?? 3);
    $hasDeposit = zinesh_user_has_verified_deposit($user);
    $referralCount = zinesh_user_verified_referral_count($user);

    if (!zinesh_early_access_active()) {
        return [
            'allowed' => true,
            'reason' => null,
            'requiresDeposit' => $requiresDeposit,
            'requiresReferrals' => $requiresReferrals,
            'hasDeposit' => $hasDeposit,
            'referralCount' => $referralCount,
        ];
    }

    $missing = [];
    if ($requiresDeposit && !$hasDeposit) {
        $missing[] = 'en az bir kez USDT yatırma';
    }
    if ($requiresReferrals > 0 && $referralCount < $requiresReferrals) {
        $missing[] = sprintf('%d doğrulanmış referans (%d/%d)', $requiresReferrals, $referralCount, $requiresReferrals);
    }

    if ($missing !== []) {
        return [
            'allowed' => false,
            'reason' => 'Erken erişimde FİZİ → USDT için: ' . implode(' ve ', $missing) . ' gerekli.',
            'requiresDeposit' => $requiresDeposit,
            'requiresReferrals' => $requiresReferrals,
            'hasDeposit' => $hasDeposit,
            'referralCount' => $referralCount,
        ];
    }

    return [
        'allowed' => true,
        'reason' => null,
        'requiresDeposit' => $requiresDeposit,
        'requiresReferrals' => $requiresReferrals,
        'hasDeposit' => $hasDeposit,
        'referralCount' => $referralCount,
    ];
}

/** @return array<string,mixed> */
function zinesh_early_access_public(): array {
    $cfg = zinesh_early_access_cfg();
    return [
        'enabled' => !empty($cfg['enabled']),
        'title' => (string)($cfg['title'] ?? 'Erken Erişim'),
        'maxUsers' => (int)($cfg['max_users'] ?? 500),
        'maxFiziPerUser' => (float)($cfg['max_fizi_per_user'] ?? 500),
        'minSwapUsdt' => (float)($cfg['min_swap_usdt'] ?? 1.0),
        'sellRequiresDeposit' => !empty($cfg['sell_requires_deposit']),
        'sellRequiresReferrals' => (int)($cfg['sell_requires_referrals'] ?? 3),
        'saleRequiresCurrentDeposit' => !empty($cfg['sale_requires_current_deposit']),
    ];
}
