<?php
declare(strict_types=1);

/** Google OAuth yönlendirmesi — GET (auth.php POST odaklı kalır). */

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/oauth_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$action = (string)($_GET['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'start') {
    $referralCode = strtoupper(trim((string)($_GET['referralCode'] ?? $_GET['ref'] ?? '')));
    $start = zinesh_google_oauth_start_url($referralCode);
    if (!$start['ok'] || empty($start['url'])) {
        zinesh_json_response(['message' => $start['message'] ?? 'Google girişi başlatılamadı.'], 503);
    }
    header('Location: ' . $start['url'], true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'callback') {
    $cfg = zinesh_mail_config();
    $siteUrl = rtrim((string)$cfg['site_url'], '/');
    $error = trim((string)($_GET['error'] ?? ''));
    if ($error !== '') {
        header('Location: ' . $siteUrl . '/?google_error=' . urlencode('Google girişi iptal edildi.'), true, 302);
        exit;
    }
    $result = zinesh_google_oauth_handle_callback(
        trim((string)($_GET['code'] ?? '')),
        trim((string)($_GET['state'] ?? ''))
    );
    if (!$result['ok'] || empty($result['redirect'])) {
        header('Location: ' . $siteUrl . '/?google_error=' . urlencode($result['message'] ?? 'Google girişi başarısız.'), true, 302);
        exit;
    }
    header('Location: ' . $result['redirect'], true, 302);
    exit;
}

zinesh_json_response(['message' => 'method_not_allowed'], 405);
