<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';

$cfg = zinesh_mail_config();
echo 'user=' . get_current_user() . ' uid=' . getmyuid() . PHP_EOL;
echo 'brevo=' . (zinesh_brevo_api_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp=' . (zinesh_smtp_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp_host=' . ($cfg['smtp']['host'] ?? '') . PHP_EOL;
echo 'smtp_user=' . ($cfg['smtp']['username'] ?? '') . PHP_EOL;
echo 'smtp_pass_len=' . strlen((string)($cfg['smtp']['password'] ?? '')) . PHP_EOL;
echo 'from=' . ($cfg['from_email'] ?? '') . PHP_EOL;
echo 'reply=' . ($cfg['reply_to'] ?? '') . PHP_EOL;

$email = getenv('PROBE_EMAIL') ?: 'yasinkarademir147@gmail.com';
$smtpTest = zinesh_smtp_test_send($email);
echo 'smtp_test=' . json_encode($smtpTest, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$sent = zinesh_send_mail($email, 'Zinesh diag ' . date('H:i:s'), '<p>diag</p>', 'diag');
echo 'send_mail=' . ($sent ? 'ok' : 'fail') . PHP_EOL;

$audit = json_decode((string)file_get_contents(zinesh_data_path('audit_log.json')), true);
$n = 0;
foreach (array_reverse(is_array($audit) ? $audit : []) as $row) {
    if (!is_array($row)) {
        continue;
    }
    $a = (string)($row['action'] ?? '');
    if (!preg_match('/mail_|password_reset|smtp_/i', $a)) {
        continue;
    }
    echo ($row['at'] ?? '') . ' ' . $a . ' ' . substr(json_encode($row['meta'] ?? [], JSON_UNESCAPED_UNICODE), 0, 220) . PHP_EOL;
    if (++$n >= 8) {
        break;
    }
}
