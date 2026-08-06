<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';

/** @return array<string,mixed> */
function zinesh_payment_config(): array
{
    $cfg = zinesh_config();
    $p = $cfg['payment'] ?? [];
    return is_array($p) ? $p : [];
}

function zinesh_payment_enabled(): bool
{
    if (!zinesh_tl_mode_enabled()) {
        return false;
    }
    $p = zinesh_payment_config();
    return !empty($p['iyzico']['api_key']) && !empty($p['iyzico']['secret_key']);
}

function zinesh_payment_sandbox(): bool
{
    $p = zinesh_payment_config();
    return (bool)($p['iyzico']['sandbox'] ?? true);
}

function zinesh_payment_base_url(): string
{
    return zinesh_payment_sandbox()
        ? 'https://sandbox-api.iyzipay.com'
        : 'https://api.iyzipay.com';
}

function zinesh_payment_callback_url(): string
{
    $site = (string)(zinesh_config()['mail']['site_url'] ?? 'https://www.zinesh.com');
    return rtrim($site, '/') . '/api/payment.php?action=callback';
}

function zinesh_payment_min_try(): float
{
    $p = zinesh_payment_config();
    return max(10.0, (float)($p['min_deposit_try'] ?? 50.0));
}

function zinesh_payment_max_try(): float
{
    $p = zinesh_payment_config();
    return max(zinesh_payment_min_try(), (float)($p['max_deposit_try'] ?? 50000.0));
}

/** @return array<int,array<string,mixed>> */
function zinesh_payment_load_pending(): array
{
    return zinesh_json_read('payments_pending.json', []);
}

/** @param array<int,array<string,mixed>> $rows */
function zinesh_payment_save_pending(array $rows): void
{
    zinesh_json_write('payments_pending.json', $rows);
}

/**
 * Iyzico Checkout Form — HMAC-SHA256 authorization (IYZWSv2).
 *
 * @param array<string,mixed> $body
 */
function zinesh_iyzico_request(string $path, array $body): array
{
    $p = zinesh_payment_config()['iyzico'] ?? [];
    $apiKey = (string)($p['api_key'] ?? '');
    $secret = (string)($p['secret_key'] ?? '');
    if ($apiKey === '' || $secret === '') {
        return ['ok' => false, 'message' => 'iyzico yapılandırılmadı'];
    }

    $random = bin2hex(random_bytes(8));
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'message' => 'İstek gövdesi oluşturulamadı'];
    }

    $payload = $random . $path . $json;
    $signature = hash_hmac('sha256', $payload, $secret);
    $auth = 'IYZWSv2 ' . base64_encode($apiKey . ':' . $random . ':' . $signature);

    $url = zinesh_payment_base_url() . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: ' . $auth,
            'x-iyzi-rnd: ' . $random,
        ],
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return ['ok' => false, 'message' => 'iyzico bağlantı hatası', 'http' => $code];
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'iyzico yanıtı okunamadı'];
    }

    return ['ok' => true, 'data' => $data];
}

/**
 * @param array<string,mixed> $user
 * @return array<string,mixed>
 */
function zinesh_payment_create_deposit(array $user, float $amountTry): array
{
    if (!zinesh_tl_mode_enabled()) {
        return ['ok' => false, 'message' => 'TL ödeme modu kapalı'];
    }
    if (!zinesh_payment_enabled()) {
        return [
            'ok' => false,
            'message' => 'Kart ile yatırma henüz aktif değil. iyzico anahtarları config.local.php içinde tanımlanmalı.',
            'configured' => false,
        ];
    }

    $amountTry = round($amountTry, 2);
    $min = zinesh_payment_min_try();
    $max = zinesh_payment_max_try();
    if ($amountTry < $min) {
        return ['ok' => false, 'message' => sprintf('Minimum yatırma: %.0f TL', $min)];
    }
    if ($amountTry > $max) {
        return ['ok' => false, 'message' => sprintf('Maksimum yatırma: %.0f TL', $max)];
    }

    $uid = (string)($user['uid'] ?? '');
    $email = (string)($user['email'] ?? '');
    $name = trim((string)($user['name'] ?? 'Zinesh Kullanıcı'));
    $conversationId = 'zns-' . $uid . '-' . time();
    $price = number_format($amountTry, 2, '.', '');

    $buyer = [
        'id' => $uid,
        'name' => $name,
        'surname' => 'Üye',
        'gsmNumber' => '+905350000000',
        'email' => $email,
        'identityNumber' => '11111111111',
        'registrationAddress' => 'Türkiye',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'city' => 'Istanbul',
        'country' => 'Turkey',
    ];

    $address = [
        'contactName' => $name,
        'city' => 'Istanbul',
        'country' => 'Turkey',
        'address' => 'Türkiye',
    ];

    $body = [
        'locale' => 'tr',
        'conversationId' => $conversationId,
        'price' => $price,
        'paidPrice' => $price,
        'currency' => 'TRY',
        'basketId' => $conversationId,
        'paymentGroup' => 'PRODUCT',
        'callbackUrl' => zinesh_payment_callback_url(),
        'enabledInstallments' => [1],
        'buyer' => $buyer,
        'shippingAddress' => $address,
        'billingAddress' => $address,
        'basketItems' => [
            [
                'id' => 'wallet-deposit',
                'name' => 'Zinesh site cüzdanı yatırma',
                'category1' => 'Dijital',
                'itemType' => 'VIRTUAL',
                'price' => $price,
            ],
        ],
    ];

    $res = zinesh_iyzico_request('/payment/iyzipos/checkoutform/initialize/auth/ecom', $body);
    if (empty($res['ok'])) {
        return $res;
    }

    $data = $res['data'];
    if (($data['status'] ?? '') !== 'success' || empty($data['token'])) {
        return [
            'ok' => false,
            'message' => (string)($data['errorMessage'] ?? 'Ödeme formu oluşturulamadı'),
        ];
    }

    $pending = zinesh_payment_load_pending();
    $pending[] = [
        'conversationId' => $conversationId,
        'uid' => $uid,
        'amountTry' => $amountTry,
        'token' => (string)$data['token'],
        'status' => 'pending',
        'createdAt' => date('c'),
    ];
    zinesh_payment_save_pending($pending);

    $checkoutBase = zinesh_payment_sandbox()
        ? 'https://sandbox-cpp.iyzipay.com'
        : 'https://cpp.iyzipay.com';

    return [
        'ok' => true,
        'conversationId' => $conversationId,
        'checkoutFormContent' => $data['checkoutFormContent'] ?? null,
        'paymentPageUrl' => $checkoutBase . '?token=' . urlencode((string)$data['token']),
        'token' => (string)$data['token'],
    ];
}

/**
 * iyzico callback — token ile sonucu doğrula, bakiyeyi artır.
 *
 * @return array<string,mixed>
 */
function zinesh_payment_complete_callback(string $token): array
{
    $res = zinesh_iyzico_request('/payment/iyzipos/checkoutform/auth/ecom/detail', ['token' => $token]);
    if (empty($res['ok'])) {
        return $res;
    }

    $data = $res['data'];
    if (($data['paymentStatus'] ?? '') !== 'SUCCESS') {
        return ['ok' => false, 'message' => (string)($data['errorMessage'] ?? 'Ödeme başarısız')];
    }

    $conversationId = (string)($data['conversationId'] ?? '');
    $paid = round((float)($data['paidPrice'] ?? 0), 2);
    if ($conversationId === '' || $paid <= 0) {
        return ['ok' => false, 'message' => 'Ödeme verisi eksik'];
    }

    $creditInfo = null;
    $matched = zinesh_json_atomic('payments_pending.json', static function (array &$pending) use ($conversationId, $paid, &$creditInfo) {
        foreach ($pending as $i => $row) {
            if ((string)($row['conversationId'] ?? '') !== $conversationId) {
                continue;
            }
            $status = (string)($row['status'] ?? '');
            if ($status === 'completed') {
                $creditInfo = [
                    'uid' => (string)($row['uid'] ?? ''),
                    'amount' => round((float)($row['amountTry'] ?? $paid), 2),
                    'alreadyCredited' => true,
                ];
                return true;
            }
            if ($status !== 'pending') {
                return false;
            }
            $expected = round((float)($row['amountTry'] ?? 0), 2);
            if ($expected > 0 && abs($expected - $paid) > 0.02) {
                return false;
            }
            $amount = $expected > 0 ? $expected : $paid;
            $pending[$i]['status'] = 'completed';
            $pending[$i]['completedAt'] = date('c');
            $pending[$i]['paidTry'] = $paid;
            $creditInfo = [
                'uid' => (string)($row['uid'] ?? ''),
                'amount' => $amount,
                'alreadyCredited' => false,
            ];
            return true;
        }
        return false;
    });

    if (!$matched || $creditInfo === null) {
        return ['ok' => false, 'message' => 'Bekleyen ödeme bulunamadı veya tutar uyuşmuyor'];
    }

    $uid = (string)$creditInfo['uid'];
    $amount = (float)$creditInfo['amount'];
    if ($uid === '' || $amount <= 0) {
        return ['ok' => false, 'message' => 'Ödeme kaydı geçersiz'];
    }

    if (!empty($creditInfo['alreadyCredited'])) {
        $user = zinesh_find_user_by_uid($uid);
        if (!$user) {
            return ['ok' => false, 'message' => 'Kullanıcı bulunamadı'];
        }
        $logs = zinesh_json_read('wallet_tx_logs.json');
        $uidLogs = $logs[$uid] ?? [];
        $hasDepositLog = false;
        if (is_array($uidLogs)) {
            foreach ($uidLogs as $entry) {
                if (is_array($entry)
                    && ($entry['type'] ?? '') === 'deposit'
                    && ($entry['txHash'] ?? '') === $conversationId
                    && ($entry['status'] ?? '') === 'completed') {
                    $hasDepositLog = true;
                    break;
                }
            }
        }
        if (!$hasDepositLog) {
            $user = zinesh_update_user($uid, static function (array &$u) use ($amount) {
                zinesh_ensure_wallet_fields($u);
                $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 2);
            });
            zinesh_append_tx_log($uid, [
                'id' => 'dep-' . bin2hex(random_bytes(6)),
                'type' => 'deposit',
                'amount' => number_format($amount, 2, '.', ''),
                'asset' => 'TL',
                'txHash' => $conversationId,
                'date' => date('d.m.Y H:i'),
                'status' => 'completed',
            ]);
            require_once __DIR__ . '/campaign_lib.php';
            zinesh_campaign_record_deposit($uid, $amount);
        }
        return [
            'ok' => true,
            'wallet' => zinesh_wallet_state($user),
            'user' => zinesh_public_user($user),
            'amountTry' => $amount,
        ];
    }

    $user = zinesh_update_user($uid, static function (array &$u) use ($amount) {
        zinesh_ensure_wallet_fields($u);
        $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 2);
    });

    zinesh_append_tx_log($uid, [
        'id' => 'dep-' . bin2hex(random_bytes(6)),
        'type' => 'deposit',
        'amount' => number_format($amount, 2, '.', ''),
        'asset' => 'TL',
        'txHash' => $conversationId,
        'date' => date('d.m.Y H:i'),
        'status' => 'completed',
    ]);

    require_once __DIR__ . '/campaign_lib.php';
    zinesh_campaign_record_deposit($uid, $amount);

    return [
        'ok' => true,
        'wallet' => zinesh_wallet_state($user),
        'user' => zinesh_public_user($user),
        'amountTry' => $amount,
    ];
}
