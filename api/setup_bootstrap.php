<?php
declare(strict_types=1);

/**
 * Sunucu ilk kurulum — CLI: php setup_bootstrap.php
 * Hot wallet + güçlü admin/cron anahtarları üretir.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/withdraw_executor.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$dataDir = zinesh_config()['data_dir'];
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0700, true);
}

$serverSecretsPath = zinesh_data_path('server_secrets.json');
if (!file_exists($serverSecretsPath)) {
    $admin = bin2hex(random_bytes(24));
    $cron = bin2hex(random_bytes(24));
    file_put_contents($serverSecretsPath, json_encode([
        'admin_secret' => $admin,
        'cron_key' => $cron,
        'createdAt' => date('c'),
    ], JSON_PRETTY_PRINT));
    chmod($serverSecretsPath, 0640);
    echo "server_secrets.json oluşturuldu\n";
    $adminUrl = "https://www.zinesh.com/api/admin.php?key={$admin}";
    file_put_contents(zinesh_data_path('admin_access.txt'), $adminUrl . "\n");
    chmod(zinesh_data_path('admin_access.txt'), 0600);
} else {
    echo "server_secrets.json zaten var\n";
}

$secretsPath = zinesh_secrets_path();
if (!file_exists($secretsPath)) {
    $cfg = zinesh_config();
    $node = $cfg['node_binary'] ?? 'node';
    $script = zinesh_scripts_dir() . '/generate-hot-wallets.mjs';
    if (!file_exists($script)) {
        fwrite(STDERR, "generate-hot-wallets.mjs bulunamadı\n");
        exit(1);
    }
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($script);
    $json = shell_exec($cmd);
    $wallets = json_decode($json ?: '', true);
    if (!is_array($wallets) || empty($wallets['ok'])) {
        fwrite(STDERR, "Hot wallet üretilemedi: {$json}\n");
        exit(1);
    }

    $secrets = [
        'tron_private_key' => $wallets['tron']['privateKey'],
        'evm_private_key' => $wallets['evm']['privateKey'],
        'hot_wallet_tron' => $wallets['tron']['address'],
        'hot_wallet_evm' => $wallets['evm']['address'],
        'createdAt' => date('c'),
        'note' => 'Otomatik çekim hot wallet — USDT + gas ile fonlayın',
    ];
    file_put_contents($secretsPath, json_encode($secrets, JSON_PRETTY_PRINT));
    chmod($secretsPath, 0640);

    $hotInfo = zinesh_data_path('hot_wallets.json');
    file_put_contents($hotInfo, json_encode([
        'tron' => ['address' => $wallets['tron']['address']],
        'evm' => ['address' => $wallets['evm']['address']],
        'funded' => false,
        'createdAt' => date('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    chmod($hotInfo, 0640);

    echo "secrets.json oluşturuldu (hot wallet)\n";
    echo "TRON hot: {$wallets['tron']['address']}\n";
    echo "EVM hot:  {$wallets['evm']['address']}\n";
    echo "Bu adreslere USDT + gas (TRX/ETH) yükleyin.\n";
} else {
    echo "secrets.json zaten var\n";
}

$active = zinesh_active_treasury();
file_put_contents(
    zinesh_data_path('treasury_active.json'),
    json_encode(['active' => $active, 'syncedAt' => date('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);
chmod(zinesh_data_path('treasury_active.json'), 0640);
echo "treasury_active.json güncellendi\n";
echo "Aktif TRON: {$active['tron']}\n";
echo "Aktif EVM:  {$active['arbitrum']}\n";

require_once __DIR__ . '/campaign_lib.php';
$cs = zinesh_campaign_ensure_state();
echo "founding_campaign.json hazır — havuz: {$cs['poolRemaining']} / {$cs['poolTotal']}\n";

echo "Kurulum tamam.\n";
