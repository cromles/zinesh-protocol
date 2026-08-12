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

/**
 * Node scriptini çalıştırır ve stdout'u satır bazlı (NDJSON) okur.
 *
 * Her tam satır JSON olarak çözülüp $onEvent'e verilir; böylece script sonlanmadan
 * önce yayımlanan event'ler (ör. broadcast hash'i) süreç öldürülse bile işlenebilir.
 * Tek satırlık legacy çıktı da aynı yoldan geçtiği için davranış değişmez.
 *
 * @param callable|null $onEvent fn(array $event): void — satır tamamlandığı anda çağrılır
 * @param array<string,string> $extraEnv alt sürece eklenecek ortam değişkenleri
 */
function zinesh_run_node_script(
    string $script,
    array $payload,
    int $timeoutSec = 12,
    ?callable $onEvent = null,
    array $extraEnv = []
): array {
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
    foreach ($extraEnv as $envKey => $envVal) {
        $env[(string)$envKey] = (string)$envVal;
    }

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
    $lineBuffer = '';
    $events = [];

    // Satırlar tek read'de gelmeyebilir; newline görülene kadar tamponlanır.
    $consume = static function (string $chunk) use (&$lineBuffer, &$events, $onEvent): void {
        if ($chunk === '') {
            return;
        }
        $lineBuffer .= $chunk;
        while (($pos = strpos($lineBuffer, "\n")) !== false) {
            $line = trim(substr($lineBuffer, 0, $pos));
            $lineBuffer = substr($lineBuffer, $pos + 1);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            $events[] = $decoded;
            if ($onEvent !== null) {
                $onEvent($decoded);
            }
        }
    };

    $deadline = microtime(true) + max(3, $timeoutSec);
    while (microtime(true) < $deadline) {
        $chunk = (string)stream_get_contents($pipes[1]);
        $stdout .= $chunk;
        $consume($chunk);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        usleep(100_000);
    }
    $chunk = (string)stream_get_contents($pipes[1]);
    $stdout .= $chunk;
    $consume($chunk);
    $stderr .= (string)stream_get_contents($pipes[2]);
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        // Timeout'a kadar okunan event'ler korunur: broadcast hash'i burada kaybolursa
        // aynı transfer ikinci kez gönderilebilir.
        return [
            'ok' => false,
            'error' => 'Node zaman aşımı (' . $timeoutSec . 's)',
            'timedOut' => true,
            'events' => $events,
        ];
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    // NDJSON'da sonuç son 'ok' taşıyan event'tir; legacy tek satır da aynı yoldan geçer.
    $json = null;
    for ($i = count($events) - 1; $i >= 0; $i--) {
        if (array_key_exists('ok', $events[$i])) {
            $json = $events[$i];
            break;
        }
    }
    if ($json === null) {
        $decoded = json_decode($stdout ?: '{}', true);
        $json = is_array($decoded) ? $decoded : null;
    }

    if (is_array($json) && !empty($json['ok'])) {
        return $json;
    }

    return [
        'ok' => false,
        'error' => is_array($json) ? ($json['error'] ?? $stderr ?: 'Bilinmeyen hata') : ($stderr ?: 'Script çıktısı okunamadı'),
        'events' => $events,
        'exitCode' => $exitCode,
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

/**
 * @param callable|null $onEvent EVM v2 protokolünde stdout event'leri geldikçe çağrılır.
 *                               TRON legacy akışında kullanılmaz.
 */
function zinesh_execute_withdraw(array $withdrawal, ?callable $onEvent = null): array {
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
        $result = zinesh_run_node_script(
            'send-evm-usdt.mjs',
            [
                'privateKey' => $key,
                'toAddress' => $to,
                'amount' => $amount,
                'network' => $network,
                'rpcUrl' => $cfg['rpc'][$network] ?? '',
                'usdtContract' => $cfg['usdt_contracts'][$network] ?? '',
            ],
            12,
            $onEvent,
            ['ZINESH_SEND_PROTOCOL' => 'v2']
        );
        if (!empty($result['ok'])) {
            // Zincir onayı doğrulanamadıysa başarı üretme; hash korunur.
            if (array_key_exists('receiptStatus', $result) && $result['receiptStatus'] !== 1) {
                return [
                    'ok' => false,
                    'error' => 'Zincir onayı doğrulanamadı (receipt status bilinmiyor)',
                    'txHash' => (string)($result['txHash'] ?? ''),
                ];
            }
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

/**
 * Çekimi işleme alır. Varsayılan yalnızca 'pending' kabul eder; bu sayede
 * pending → processing geçişi gerçek bir compare-and-set olur ve aynı kaydı
 * iki executor birden alamaz.
 *
 * 'processing' kaydı yeniden almak yalnızca açık izin listesiyle mümkündür
 * (bkz. zinesh_recover_withdrawal_by_id).
 */
function zinesh_claim_withdrawal_for_processing(string $id, ?array $allowedStatuses = null): ?array {
    $allowed = $allowedStatuses ?? ['pending'];
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

/**
 * Broadcast anında transaction hash'ini kalıcılaştırır.
 *
 * zinesh_json_atomic() kilit alamazsa 503 ile isteği sonlandırdığı için burada
 * kullanılamaz: broadcast sonrası süreç ölürse hash kaybolur ve aynı transfer
 * ikinci kez gönderilebilir. Bu yüzden atomik yazım exit etmeyen kendi retry
 * dalıyla sürülür ve hash her koşulda önce log'a düşürülür.
 */
function zinesh_record_withdrawal_broadcast(string $id, string $txHash): bool {
    error_log(sprintf('zinesh_withdraw_broadcast id=%s tx=%s', $id, $txHash));

    $cfg = zinesh_config()['file_lock'] ?? [];
    $retries = max(1, (int)($cfg['retries'] ?? 6));
    $retryMs = max(1, (int)($cfg['retry_ms'] ?? 30));

    for ($attempt = 0; $attempt < $retries; $attempt++) {
        try {
            return (bool)zinesh_json_atomic_once('withdrawals.json', static function (array &$withdrawals) use ($id, $txHash): bool {
                foreach ($withdrawals as &$w) {
                    if (($w['id'] ?? '') !== $id) {
                        continue;
                    }
                    if (trim((string)($w['outboundTx'] ?? '')) === '') {
                        $w['outboundTx'] = $txHash;
                        $w['submittedAt'] = date('c');
                    }
                    return true;
                }
                return false;
            });
        } catch (RuntimeException $e) {
            if ($attempt < $retries - 1) {
                usleep($retryMs * 1000);
            }
        }
    }

    error_log(sprintf('zinesh_withdraw_broadcast_persist_failed id=%s tx=%s', $id, $txHash));
    return false;
}

/** Kaydın diskteki güncel outboundTx değeri. */
function zinesh_withdrawal_outbound_tx(string $id): string {
    foreach (zinesh_json_read('withdrawals.json') as $w) {
        if (($w['id'] ?? '') === $id) {
            return trim((string)($w['outboundTx'] ?? ''));
        }
    }
    return '';
}

function zinesh_send_claimed_withdrawal(array $withdrawal): array {
    $id = (string)($withdrawal['id'] ?? '');
    $existingTx = trim((string)($withdrawal['outboundTx'] ?? ''));
    if ($existingTx === '') {
        // Claim anındaki kopya bayat olabilir: bu arada başka bir executor
        // broadcast edip hash yazmış olabilir. Gönderimden önce taze okunur.
        $existingTx = zinesh_withdrawal_outbound_tx($id);
    }
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

    // Broadcast event'i geldiği anda hash kalıcılaşır; onay beklenmez.
    $broadcastHash = '';
    $broadcastPersisted = false;
    $onEvent = static function (array $event) use ($id, &$broadcastHash, &$broadcastPersisted): void {
        $stage = (string)($event['stage'] ?? '');
        if ($stage !== 'broadcast' && $stage !== 'broadcast_uncertain') {
            return;
        }
        $hash = trim((string)($event['txHash'] ?? ''));
        if ($hash === '' || $broadcastHash !== '') {
            return;
        }
        $broadcastHash = $hash;
        $broadcastPersisted = zinesh_record_withdrawal_broadcast($id, $hash);
    };

    $send = zinesh_execute_withdraw($withdrawal, $onEvent);
    if (!empty($send['ok'])) {
        $txHash = (string)($send['txHash'] ?? '') ?: $broadcastHash;
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

    if ($broadcastHash !== '' && !$broadcastPersisted) {
        // Hash elimizde ama diske yazılamadı. pending'e düşürmek otomatik retry
        // demektir ve aynı transferi ikinci kez gönderir.
        error_log(sprintf('zinesh_withdraw_broadcast_unpersisted id=%s tx=%s', $id, $broadcastHash));
        return [
            'ok' => false,
            'error' => 'Gönderim yayınlandı ancak kaydedilemedi; manuel kontrol gerekiyor.',
            'txHash' => $broadcastHash,
            'manual' => true,
        ];
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

/**
 * Kurucu paneli kurtarma yolu: takılı kalmış 'processing' kayıtları da ele alır.
 * outboundTx doluysa gönderim yapılmaz, kayıt yalnızca tamamlanmış olarak kapanır.
 * Otomatik akışlar (cron, kullanıcı isteği) bu yolu kullanmaz.
 */
function zinesh_recover_withdrawal_by_id(string $id): array {
    return zinesh_process_withdrawal_by_id($id, ['pending', 'processing']);
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
