<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';
require '/www/wwwroot/zinesh.com/api/password_reset_lib.php';

$cfg = zinesh_mail_config();
echo 'brevo_configured=' . (zinesh_brevo_api_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp_configured=' . (zinesh_smtp_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp_enabled=' . (!empty($cfg['smtp']['enabled']) ? 'yes' : 'no') . PHP_EOL;
echo 'from_email=' . ($cfg['from_email'] ?? '') . PHP_EOL;

$email = getenv('PROBE_EMAIL') ?: '';
if ($email !== '') {
    $user = zinesh_find_user_by_email($email);
    echo 'user_found=' . ($user ? 'yes' : 'no') . PHP_EOL;
    if ($user) {
        $r = zinesh_password_reset_send_code($email);
        echo 'reset_result=' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

$auditPath = '/www/server/zinesh-data/audit_log.json';
if (!is_file($auditPath)) {
    $auditPath = zinesh_data_path('audit_log.json');
}
if (is_file($auditPath)) {
    $audit = json_decode((string)file_get_contents($auditPath), true);
    $n = 0;
    foreach (array_reverse(is_array($audit) ? $audit : []) as $row) {
        if (!is_array($row)) continue;
        $a = (string)($row['action'] ?? '');
        if (!preg_match('/mail_|password_reset/i', $a)) continue;
        echo ($row['at'] ?? '') . ' ' . $a . ' ' . substr(json_encode($row['meta'] ?? [], JSON_UNESCAPED_UNICODE), 0, 200) . PHP_EOL;
        if (++$n >= 8) break;
    }
}
