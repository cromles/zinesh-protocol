<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';

$reply = getenv('ZINESH_MAIL_REPLY_TO') ?: '';
if ($reply === '') {
    fwrite(STDERR, "ZINESH_MAIL_REPLY_TO required\n");
    exit(1);
}

$path = zinesh_server_secrets_path();
$data = zinesh_load_server_secrets();
$data['mail_reply_to'] = zinesh_mail_normalize_reply_to($reply, (string)($data['mail_from'] ?? 'noreply@zinesh.com'));
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
zinesh_secure_secrets_file($path);

$cfg = zinesh_mail_config();
echo 'reply_to=' . ($cfg['reply_to'] ?? '') . PHP_EOL;
