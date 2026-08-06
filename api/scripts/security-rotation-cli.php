<?php
declare(strict_types=1);

/**
 * Kritik güvenlik rotasyonu — yalnızca CLI (sunucuda).
 * php security-rotation-cli.php <step> [backupDir]
 *
 * step: rotate-admin | rotate-cron | clear-sessions | rotate-hot-wallet | read-secrets
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/security_lib.php';
require_once dirname(__DIR__) . '/withdraw_executor.php';

$step = (string)($argv[1] ?? '');
$backupDir = rtrim((string)($argv[2] ?? ''), '/');

function rot_backup_file(string $file, string $backupDir): void {
    if ($backupDir === '') {
        return;
    }
    $src = zinesh_data_path($file);
    if (!file_exists($src)) {
        return;
    }
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0700, true);
    }
    copy($src, $backupDir . '/' . $file);
}

function rot_merge_server_secrets(array $patch): array {
    $path = zinesh_server_secrets_path();
    $data = zinesh_load_server_secrets();
    $data = array_merge($data, $patch);
    $data['rotatedAt'] = date('c');
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    chmod($path, 0640);
    return $data;
}

function rot_write_json_data(string $file, array $data, int $mode = 0640): void {
    file_put_contents(
        zinesh_data_path($file),
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
    chmod(zinesh_data_path($file), $mode);
}

switch ($step) {
    case 'read-secrets':
        echo json_encode([
            'admin_secret' => zinesh_admin_secret(),
            'cron_key' => zinesh_cron_secret(),
            'secrets_path' => zinesh_secrets_path(),
            'sessions_count' => count(zinesh_json_read('sessions.json')),
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'rotate-admin':
        rot_backup_file('server_secrets.json', $backupDir);
        $old = zinesh_admin_secret();
        $new = bin2hex(random_bytes(32));
        rot_merge_server_secrets(['admin_secret' => $new]);
        $adminUrl = 'https://www.zinesh.com/api/admin.php?key=' . $new;
        file_put_contents(zinesh_data_path('admin_access.txt'), $adminUrl . "\n");
        chmod(zinesh_data_path('admin_access.txt'), 0600);
        echo json_encode(['ok' => true, 'old_admin_secret' => $old, 'new_admin_secret' => $new], JSON_UNESCAPED_UNICODE);
        break;

    case 'rotate-cron':
        rot_backup_file('server_secrets.json', $backupDir);
        $old = zinesh_cron_secret();
        $new = bin2hex(random_bytes(32));
        rot_merge_server_secrets(['cron_key' => $new]);
        echo json_encode(['ok' => true, 'old_cron_key' => $old, 'new_cron_key' => $new], JSON_UNESCAPED_UNICODE);
        break;

    case 'clear-sessions':
        rot_backup_file('sessions.json', $backupDir);
        $old = zinesh_json_read('sessions.json');
        $sampleToken = '';
        $sampleUid = '';
        foreach ($old as $tok => $meta) {
            if (is_array($meta) && ($meta['expires'] ?? 0) > time()) {
                $sampleToken = (string)$tok;
                $sampleUid = (string)($meta['uid'] ?? '');
                break;
            }
        }
        rot_write_json_data('sessions.json', []);
        echo json_encode([
            'ok' => true,
            'cleared' => count($old),
            'sample_token' => $sampleToken,
            'sample_uid' => $sampleUid,
        ], JSON_UNESCAPED_UNICODE);
        break;

    case 'rotate-hot-wallet':
        rot_backup_file('secrets.json', $backupDir);
        rot_backup_file('hot_wallets.json', $backupDir);
        rot_backup_file('treasury_active.json', $backupDir);

        $oldSecrets = zinesh_load_secrets();
        $oldTronKey = (string)($oldSecrets['tron_private_key'] ?? '');
        $oldEvmKey = (string)($oldSecrets['evm_private_key'] ?? '');

        $node = zinesh_node_binary();
        $script = zinesh_scripts_dir() . '/generate-hot-wallets.mjs';
        if (!file_exists($script)) {
            fwrite(STDERR, "generate-hot-wallets.mjs missing\n");
            exit(1);
        }
        $cmd = escapeshellarg($node) . ' ' . escapeshellarg($script);
        $json = shell_exec($cmd);
        $wallets = json_decode($json ?: '', true);
        if (!is_array($wallets) || empty($wallets['ok'])) {
            fwrite(STDERR, "wallet generation failed: {$json}\n");
            exit(1);
        }

        $newSecrets = [
            'tron_private_key' => $wallets['tron']['privateKey'],
            'evm_private_key' => $wallets['evm']['privateKey'],
            'hot_wallet_tron' => $wallets['tron']['address'],
            'hot_wallet_evm' => $wallets['evm']['address'],
            'rotatedAt' => date('c'),
            'previous_tron_address' => (string)($oldSecrets['hot_wallet_tron'] ?? ''),
            'previous_evm_address' => (string)($oldSecrets['hot_wallet_evm'] ?? ''),
            'note' => 'Hot wallet rotated — fund new addresses; old keys retired',
        ];
        rot_write_json_data('secrets.json', $newSecrets, 0640);

        rot_write_json_data('hot_wallets.json', [
            'tron' => ['address' => $wallets['tron']['address']],
            'evm' => ['address' => $wallets['evm']['address']],
            'funded' => false,
            'rotatedAt' => date('c'),
        ], 0640);

        $active = [
            'tron' => $wallets['tron']['address'],
            'arbitrum' => $wallets['evm']['address'],
            'ethereum' => $wallets['evm']['address'],
            'solana' => trim((string)(zinesh_active_treasury()['solana'] ?? '')),
        ];
        rot_write_json_data('treasury_active.json', [
            'active' => $active,
            'syncedAt' => date('c'),
            'rotatedAt' => date('c'),
        ], 0640);

        echo json_encode([
            'ok' => true,
            'old_tron_key' => $oldTronKey,
            'old_evm_key' => $oldEvmKey,
            'new_tron_address' => $wallets['tron']['address'],
            'new_evm_address' => $wallets['evm']['address'],
            'new_tron_key' => $wallets['tron']['privateKey'],
            'new_evm_key' => $wallets['evm']['privateKey'],
            'keys_changed' => $oldTronKey !== $wallets['tron']['privateKey']
                && $oldEvmKey !== $wallets['evm']['privateKey'],
            'old_keys_retired' => true,
        ], JSON_UNESCAPED_UNICODE);
        break;

    default:
        fwrite(STDERR, "Unknown step: {$step}\n");
        exit(1);
}
