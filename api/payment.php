<?php
declare(strict_types=1);

require_once __DIR__ . '/tl_payment_lib.php';
require_once __DIR__ . '/tl_havale_lib.php';

zinesh_cors();
zinesh_security_headers();

$input = zinesh_input();
$action = (string)($input['action'] ?? $_GET['action'] ?? '');
$callbackToken = (string)($input['token'] ?? $_POST['token'] ?? $_GET['token'] ?? '');
if ($action === '' && $callbackToken !== '') {
    // iyzico callback'inde action gelmeyebilir; token varsa callback kabul et.
    $action = 'callback';
}

if ($action === 'status') {
    $havaleCfg = zinesh_havale_config();
    zinesh_json_response([
        'ok' => true,
        'tlMode' => zinesh_tl_mode_enabled(),
        'paymentEnabled' => zinesh_payment_enabled(),
        'havaleEnabled' => zinesh_havale_enabled(),
        'minDepositTry' => zinesh_payment_min_try(),
        'maxDepositTry' => zinesh_payment_max_try(),
        'sandbox' => zinesh_payment_sandbox(),
        'havale' => [
            'enabled' => zinesh_havale_enabled(),
            'iban' => trim((string)($havaleCfg['iban'] ?? '')),
            'accountHolder' => trim((string)($havaleCfg['account_holder'] ?? '')),
            'bankName' => trim((string)($havaleCfg['bank_name'] ?? '')),
            'minDepositTry' => zinesh_havale_min_try(),
        ],
    ]);
}

if ($action === 'havale_info') {
    $user = zinesh_require_auth($input);
    zinesh_json_response([
        'ok' => true,
        'havale' => zinesh_havale_public_info($user),
    ]);
}

if ($action === 'create_deposit') {
    $user = zinesh_require_auth($input);
    $amount = (float)($input['amount'] ?? 0);
    $result = zinesh_payment_create_deposit($user, $amount);
    if (empty($result['ok'])) {
        zinesh_json_response($result, 400);
    }
    zinesh_json_response($result);
}

if ($action === 'callback') {
    $token = (string)($input['token'] ?? $_POST['token'] ?? $_GET['token'] ?? '');
    if ($token === '') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<p>Ödeme token eksik.</p>';
        exit;
    }
    $result = zinesh_payment_complete_callback($token);
    $site = rtrim((string)(zinesh_config()['mail']['site_url'] ?? 'https://www.zinesh.com'), '/');
    if (!empty($result['ok'])) {
        header('Location: ' . $site . '/?payment=success', true, 302);
        exit;
    }
    header('Location: ' . $site . '/?payment=failed', true, 302);
    exit;
}

zinesh_json_response(['message' => 'unknown_action'], 400);
