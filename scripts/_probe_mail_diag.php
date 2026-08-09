<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';
$cfg = zinesh_mail_config();
echo 'from=' . ($cfg['from_email'] ?? '') . PHP_EOL;
echo 'reply=' . ($cfg['reply_to'] ?? '') . PHP_EOL;
echo 'brevo=' . (zinesh_brevo_api_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp=' . (zinesh_smtp_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp_host=' . ($cfg['smtp']['host'] ?? '') . PHP_EOL;
$audit = json_decode((string)file_get_contents(zinesh_data_path('audit_log.json')), true);
$n = 0;
foreach (array_reverse(is_array($audit) ? $audit : []) as $row) {
    $a = (string)($row['action'] ?? '');
    if (!preg_match('/mail_/i', $a)) {
        continue;
    }
    echo ($row['at'] ?? '') . ' ' . $a . ' ' . substr(json_encode($row['meta'] ?? []), 0, 180) . PHP_EOL;
    if (++$n >= 8) {
        break;
    }
}
