<?php
declare(strict_types=1);
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';
require '/www/wwwroot/zinesh.com/api/password_reset_lib.php';
require '/www/wwwroot/zinesh.com/api/smtp_lib.php';

$secretsPath = zinesh_server_secrets_path();
$cfg = zinesh_mail_config();
echo 'user=' . get_current_user() . ' uid=' . getmyuid() . ' gid=' . getmygid() . PHP_EOL;
echo 'secrets_path=' . $secretsPath . PHP_EOL;
echo 'secrets_readable=' . (is_readable($secretsPath) ? 'yes' : 'no') . PHP_EOL;
echo 'smtp_configured=' . (zinesh_smtp_configured() ? 'yes' : 'no') . PHP_EOL;
echo 'smtp_host=' . ($cfg['smtp']['host'] ?? '') . PHP_EOL;
echo 'smtp_user=' . ($cfg['smtp']['username'] ?? '') . PHP_EOL;
echo 'smtp_pass_len=' . strlen((string)($cfg['smtp']['password'] ?? '')) . PHP_EOL;

$email = getenv('PROBE_EMAIL') ?: 'yasinkarademir147@gmail.com';
$sent = zinesh_send_mail($email, 'Zinesh www-data test', '<p>test</p>', 'test');
echo 'simple_send=' . ($sent ? 'ok' : 'fail') . PHP_EOL;

$r = zinesh_password_reset_send_code($email);
echo 'reset=' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
