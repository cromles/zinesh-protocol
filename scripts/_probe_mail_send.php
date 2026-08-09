<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
require '/www/wwwroot/zinesh.com/api/email_lib.php';
require '/www/wwwroot/zinesh.com/api/password_reset_lib.php';

$email = 'yasinkarademir147@gmail.com';
$simple = zinesh_send_mail($email, 'Zinesh test', '<p>test</p>', 'test');
echo 'simple=' . ($simple ? 'ok' : 'fail') . PHP_EOL;

$reset = zinesh_send_password_reset_email($email, 'Test', '123456');
echo 'reset_template=' . ($reset ? 'ok' : 'fail') . PHP_EOL;

$r = zinesh_password_reset_send_code($email);
echo 'send_code=' . json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL;
