<?php
require '/www/wwwroot/zinesh.com/api/wallet_lib.php';
$path = zinesh_server_secrets_path();
$data = json_decode((string)file_get_contents($path), true);
if (!is_array($data)) {
    $data = [];
}
unset($data['brevo_api_key']);
file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
zinesh_secure_secrets_file($path);
echo "brevo_api_key_removed\n";
