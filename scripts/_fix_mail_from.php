<?php
declare(strict_types=1);
/**
 * Brevo gönderen düzeltmesi — server_secrets.json içindeki mail_from günceller.
 * CLI: php _fix_mail_from.php
 */
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';

$path = zinesh_server_secrets_path();
$data = zinesh_load_server_secrets();
$verified = zinesh_mail_verified_sender_email();

$data['mail_from'] = $verified;
$reply = strtolower(trim((string)($data['mail_reply_to'] ?? '')));
if ($reply === '' || preg_match('/@(smtp-brevo\.com|sendinblue\.com)$/i', $reply)) {
    $data['mail_reply_to'] = $verified;
}

file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
zinesh_secure_secrets_file($path);

$cfg = zinesh_mail_config();
echo 'mail_from=' . ($cfg['from_email'] ?? '') . PHP_EOL;
echo 'reply_to=' . ($cfg['reply_to'] ?? '') . PHP_EOL;

$testTo = getenv('ZINESH_MAIL_TEST_TO') ?: $verified;
$result = zinesh_smtp_test_send($testTo);
echo 'smtp_test=' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
