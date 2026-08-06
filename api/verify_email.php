<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/campaign_lib.php';
require_once __DIR__ . '/email_lib.php';

$token = trim((string)($_GET['token'] ?? ''));
$cfg = zinesh_mail_config();
$siteUrl = rtrim((string)$cfg['site_url'], '/');

$result = zinesh_email_verify_token($token);

if ($result['ok']) {
    $grant = $result['grant'] ?? [];
    $amount = (int)($grant['amount'] ?? 0);
    $claimed = !empty($grant['claimed']);
    $query = $claimed
        ? 'verified=1&reward=' . $amount
        : 'verified=1';
    header('Location: ' . $siteUrl . '/?' . $query, true, 302);
    exit;
}

$reason = (string)($result['reason'] ?? 'error');
header('Location: ' . $siteUrl . '/?verify_error=' . urlencode($reason), true, 302);
exit;
