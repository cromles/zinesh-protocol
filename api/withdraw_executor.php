<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/notifications_lib.php';

function zinesh_secrets_path(): string {
    return zinesh_data_path('secrets.json');
}

function zinesh_load_secrets(): array {
    $path = zinesh_secrets_path();
    if (!file_exists($path)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function zinesh_scripts_dir(): string {
    return __DIR__ . '/scripts';
}

function zinesh_node_binary(): string {
    static $resolved = null;
    if (is_string($resolved)) {
        return $resolved;
    }

    $configured = trim((string)(zinesh_config()['node_binary'] ?? 'node'));
    // Only probe paths inside open_basedir. Outside probes emit PHP Warnings that
    // flood FastCGI stderr and nginx returns "upstream sent too big header" (502).
    $candidates = [];
    if ($configured !== '' && $configured !== 'node') {
        $candidates[] = $configured;
    }
    $candidates[] = zinesh_scripts_dir() . '/bin/node';

    foreach ($candidates as $candidate) {
        $ok = @is_file($candidate) && @is_executable($candidate);
        if ($ok) {
            $resolved = $candidate;
            return $resolved;
        }
    }

    $resolved = $configured !== '' ? $configured : 'node';
    return $resolved;
}

function zinesh_run_node_script(string $script, array $payload, int $timeoutSec = 12): array {
    $node = zinesh_node_binary();
    $scriptPath = zinesh_scripts_dir() . '/' . $script;
    if (!file_exists($scriptPath)) {
        return ['ok' => false, 'error' => 'Gönderim scripti bulunamadı.'];
    }

    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($scriptPath);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = [];
    foreach (['PATH', 'HOME', 'LANG', 'TMPDIR'] as $key) {
        $val = getenv($key);
        if ($val !== false) {
            $env[$key] = $val;
        }
    }
    $env['NODE_PATH'] = zinesh_scripts_dir() . '/node_modules';
    $env['PATH'] = ($env['PATH'] ?? '/usr/local/bin:/usr/bin:/bin') . ':/www/wwwroot/zinesh.com/api/scripts/bin:/www/server/node/bin';

    $proc = proc_open($cmd, $descriptors, $pipes, zinesh_scripts_dir(), $env);
    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Node başlatılamadı.'];
    }

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(3, $timeoutSec);
    while (microtime(true) < $deadline) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        usleep(100_000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc, 9);
        proc_close($proc);
        return ['ok' => false, 'error' => 'Node zaman aşımı (' . $timeoutSec . 's)'];
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $json = json_decode($stdout ?: '{}', true);
    if (is_array($json) && !empty($json['ok'])) {
        return $json;
    }

    return [
        'ok' => false,
        'error' => is_array($json) ? ($json['error'] ?? $stderr ?: 'Bilinmeyen hata') : ($stderr ?: 'Script çıktısı okunamadı'),
    ];
}

function zinesh_auto_withdraw_enabled(string $network): bool {
    $secrets = zinesh_load_secrets();
    $network = strtolower($network);
    if ($network === 'tron') {
        return !empty($secrets['tron_private_key']);
    }
    if (in_array($network, ['arbitrum', 'ethereum'], true)) {
        return !empty($secrets['evm_private_key']);
    }
    return false;
}

function zinesh_execute_withdraw(array $withdrawal): array {
    $network = strtolower((string)($withdrawal['network'] ?? ''));
    $to = trim((string)($withdrawal['address'] ?? ''));
    $amount = (float)($withdrawal['amount'] ?? 0);
    $cfg = zinesh_config();
    $secrets = zinesh_load_secrets();

    if ($amount <= 0 || $to === '') {
        return ['ok' => false, 'error' => 'Geçersiz çekim kaydı'];
    }

    if (zinesh_hot_wallet_blocks_auto_send($network)) {
        return [
            'ok' => false,
            'error' => sprintf(
                'Hot wallet USDT limiti aşıldı (>$%s) — manuel onay gerekir',
                number_format(zinesh_hot_wallet_max_usdt(), 0)
            ),
            'manual' => true,
        ];
    }

    $onChainCheck = zinesh_hot_usdt_check($network);
    if ($onChainCheck['ok']) {
        $onChain = (float)$onChainCheck['available'];
        if ($onChain < $amount) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'Kasa USDT yetersiz (zincirde $%s, gerekli $%s)',
                    number_format($onChain, 2),
                    number_format($amount, 2)
                ),
                'manual' => true,
            ];
        }
    }

    if ($network === 'tron') {
        $key = trim((string)($secrets['tron_private_key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'error' => 'TRON otomatik çekim anahtarı yapılandırılmamış', 'manual' => true];
        }
        $result = zinesh_run_node_script('send-tron-usdt.mjs', [
            'privateKey' => $key,
            'toAddress' => $to,
            'amount' => $amount,
            'apiKey' => $cfg['trongrid_api_key'] ?? '',
        ]);
        if (!empty($result['ok'])) {
            return ['ok' => true, 'txHash' => (string)($result['txId'] ?? '')];
        }
        return $result;
    }

    if (in_array($network, ['arbitrum', 'ethereum'], true)) {
        $key = trim((string)($secrets['evm_private_key'] ?? ''));
        if ($key === '') {
            return ['ok' => false, 'error' => 'EVM otomatik çekim anahtarı yapılandırılmamış', 'manual' => true];
        }
        $result = zinesh_run_node_script('send-evm-usdt.mjs', [
            'privateKey' => $key,
            'toAddress' => $to,
            'amount' => $amount,
            'network' => $network,
            'rpcUrl' => $cfg['rpc'][$network] ?? '',
            'usdtContract' => $cfg['usdt_contracts'][$network] ?? '',
        ]);
        if (!empty($result['ok'])) {
            return ['ok' => true, 'txHash' => (string)($result['txHash'] ?? '')];
        }
        return $result;
    }

    if ($network === 'solana') {
        return ['ok' => false, 'error' => 'Solana otomatik çekim henüz desteklenmiyor', 'manual' => true];
    }

    return ['ok' => false, 'error' => 'Desteklenmeyen ağ'];
}

function zinesh_update_withdrawal(string $id, callable $mutator): void {
    $withdrawals = zinesh_json_read('withdrawals.json');
    foreach ($withdrawals as $i => $w) {
        if (($w['id'] ?? '') === $id) {
            $mutator($withdrawals[$i]);
            zinesh_json_write('withdrawals.json', $withdrawals);
            return;
        }
    }
}

function zinesh_claim_withdrawal_for_processing(string $id, ?array $allowedStatuses = null): ?array {
    $allowed = $allowedStatuses ?? ['pending', 'processing'];
    $found = null;
    $claimed = zinesh_json_atomic('withdrawals.json', function (array &$withdrawals) use ($id, &$found, $allowed) {
        foreach ($withdrawals as &$w) {
            $status = (string)($w['status'] ?? '');
            if (($w['id'] ?? '') !== $id || !in_array($status, $allowed, true)) {
                continue;
            }
            if ($status !== 'processing') {
                $w['status'] = 'processing';
                $w['processingAt'] = date('c');
            }
            $found = $w;
            return true;
        }
        return false;
    });
    return $claimed ? $found : null;
}

function zinesh_finalize_withdrawal_completed(string $id, string $txHash, bool $auto = true): void {
    zinesh_update_withdrawal($id, static function (array &$w) use ($txHash, $auto): void {
        $w['status'] = 'completed';
        $w['paidAt'] = date('c');
        $w['outboundTx'] = $txHash;
        $w['auto'] = $auto;
        unset($w['processingAt'], $w['lastError'], $w['submittedAt']);
    });
}

function zinesh_release_withdrawal_to_pending(string $id, string $error): void {
    zinesh_update_withdrawal($id, function (&$w) use ($error) {
        if (($w['status'] ?? '') !== 'processing') {
            return;
        }
        if (!empty($w['outboundTx'])) {
            $w['lastError'] = $error;
            $w['lastAttemptAt'] = date('c');
            return;
        }
        $w['status'] = 'pending';
        $w['lastError'] = $error;
        $w['lastAttemptAt'] = date('c');
        unset($w['processingAt'], $w['submittedAt']);
    });
}

function zinesh_send_claimed_withdrawal(array $withdrawal): array {
    $id = (string)($withdrawal['id'] ?? '');
    $existingTx = trim((string)($withdrawal['outboundTx'] ?? ''));
    if ($existingTx !== '') {
        zinesh_finalize_withdrawal_completed($id, $existingTx, !empty($withdrawal['auto']));
        $uid = (string)($withdrawal['uid'] ?? '');
        if ($uid !== '') {
            $amount = (float)($withdrawal['amount'] ?? 0);
            zinesh_notify_user(
                $uid,
                'WITHDRAW_APPROVED',
                null,
                sprintf('%.2f USDT çekim talebin işlendi ve gönderildi.', $amount),
                ['withdrawalId' => $id, 'amount' => $amount]
            );
        }
        return ['ok' => true, 'txHash' => $existingTx, 'recovered' => true];
    }

    $send = zinesh_execute_withdraw($withdrawal);
    if (!empty($send['ok'])) {
        $txHash = (string)($send['txHash'] ?? '');
        if ($txHash !== '') {
            zinesh_update_withdrawal($id, static function (array &$w) use ($txHash): void {
                $w['outboundTx'] = $txHash;
                $w['submittedAt'] = date('c');
            });
        }
        zinesh_finalize_withdrawal_completed($id, $txHash, true);
        $uid = (string)($withdrawal['uid'] ?? '');
        if ($uid !== '') {
            $amount = (float)($withdrawal['amount'] ?? 0);
            zinesh_notify_user(
                $uid,
                'WITHDRAW_APPROVED',
                null,
                sprintf('%.2f USDT çekim talebin işlendi ve gönderildi.', $amount),
                ['withdrawalId' => $id, 'amount' => $amount]
            );
        }
        return $send;
    }

    zinesh_release_withdrawal_to_pending($id, (string)($send['error'] ?? 'Hata'));
    return $send;
}

function zinesh_process_withdrawal_by_id(string $id, ?array $allowedStatuses = null): array {
    $claimed = zinesh_claim_withdrawal_for_processing($id, $allowedStatuses);
    if (!$claimed) {
        return ['ok' => false, 'error' => 'Bekleyen çekim bulunamadı'];
    }
    return zinesh_send_claimed_withdrawal($claimed);
}

function zinesh_approve_manual_review_withdrawal(string $id): array {
    return zinesh_process_withdrawal_by_id($id, ['pending_manual_review']);
}

function zinesh_process_pending_withdrawals(int $limit = 10): array {
    $withdrawals = zinesh_json_read('withdrawals.json');
    $processed = [];
    $count = 0;
    foreach ($withdrawals as $w) {
        if (($w['status'] ?? '') !== 'pending') {
            continue;
        }
        if ($count >= $limit) {
            break;
        }
        $id = (string)($w['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $processed[] = ['id' => $id, 'result' => zinesh_process_withdrawal_by_id($id)];
        $count++;
    }
    return $processed;
}

function zinesh_refund_withdrawal(string $id): bool
{
    $refunded = false;
    zinesh_json_atomic('withdrawals.json', static function (array &$withdrawals) use ($id, &$refunded) {
        foreach ($withdrawals as $i => $w) {
            $status = (string)($w['status'] ?? '');
            if (($w['id'] ?? '') !== $id || !in_array($status, ['pending', 'pending_manual_review'], true)) {
                continue;
            }
            $withdrawals[$i]['status'] = 'refunded';
            $withdrawals[$i]['refundedAt'] = date('c');
            $refunded = true;
            return true;
        }
        return false;
    });

    if (!$refunded) {
        return false;
    }

    $withdrawals = zinesh_json_read('withdrawals.json');
    foreach ($withdrawals as $w) {
        if (($w['id'] ?? '') !== $id) {
            continue;
        }
        $uid = (string)($w['uid'] ?? '');
        $amount = (float)($w['amount'] ?? 0);
        if ($uid && $amount > 0) {
            zinesh_update_user($uid, function (&$u) use ($amount) {
                zinesh_ensure_wallet_fields($u);
                $u['usdtBalance'] = round((float)$u['usdtBalance'] + $amount, 6);
            });
        }
        zinesh_notify_user(
            $uid,
            'WITHDRAW_REJECTED',
            null,
            sprintf('%.2f USDT çekim talebin iptal edildi; bakiye hesabına iade edildi.', $amount),
            ['withdrawalId' => $id, 'amount' => $amount]
        );
        return true;
    }
    return false;
}
