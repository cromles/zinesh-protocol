<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
$path = zinesh_server_secrets_path();
$data = file_exists($path) ? json_decode((string)file_get_contents($path), true) : [];
if (!is_array($data)) $data = [];
echo 'secrets_path=' . $path . PHP_EOL;
echo 'has_brevo_api=' . (!empty($data['brevo_api_key']) ? 'yes' : 'no') . PHP_EOL;
echo 'smtp_user=' . ($data['smtp']['username'] ?? '') . PHP_EOL;
echo 'smtp_host=' . ($data['smtp']['host'] ?? '') . PHP_EOL;
echo 'smtp_enabled=' . (!empty($data['smtp']['enabled']) ? 'yes' : 'no') . PHP_EOL;
echo 'mail_from=' . ($data['mail_from'] ?? '') . PHP_EOL;
