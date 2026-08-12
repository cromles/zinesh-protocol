<?php
declare(strict_types=1);

/**
 * Eşzamanlı çekim yarışı için tek işçi süreç (yalnız test).
 *
 * Üretimdeki claim ve outboundTx guard fonksiyonlarının aynısını çağırır;
 * yalnızca zincire çıkan gönderici sahtedir. Gerçek RPC/anahtar/USDT yoktur.
 *
 * argv: simDir withdrawalId mode startAtMicro counterFile hash preDelayMs postDelayMs
 * mode: claim_only | send
 *
 * stdout'a tek satır sonuç yazar:
 *   CLAIM_FAIL | CLAIMED | GUARD_BLOCKED | SENT:<hash> | ERROR:<mesaj>
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$simDir       = (string)($argv[1] ?? '');
$id           = (string)($argv[2] ?? '');
$mode         = (string)($argv[3] ?? 'claim_only');
$startAt      = (float)($argv[4] ?? 0);
$counterFile  = (string)($argv[5] ?? '');
$hash         = (string)($argv[6] ?? '');
$preDelayMs   = (int)($argv[7] ?? 0);
$postDelayMs  = (int)($argv[8] ?? 0);

if ($simDir === '' || $id === '') {
    fwrite(STDOUT, "ERROR:eksik argüman\n");
    exit(1);
}

putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

$apiDir = dirname(__DIR__, 2);
require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/withdraw_executor.php';

// Tüm işçilerin aynı anda claim denemesi için ortak başlangıç bariyeri.
while ($startAt > 0 && microtime(true) < $startAt) {
    usleep(200);
}

$claimed = zinesh_claim_withdrawal_for_processing($id);
if ($claimed === null) {
    fwrite(STDOUT, "CLAIM_FAIL\n");
    exit(0);
}

if ($mode === 'claim_only') {
    fwrite(STDOUT, "CLAIMED\n");
    exit(0);
}

// Üretimdeki taze outboundTx kontrolünün aynısı: claim anındaki kopya bayat olabilir.
$existing = trim((string)($claimed['outboundTx'] ?? ''));
if ($existing === '') {
    $existing = zinesh_withdrawal_outbound_tx($id);
}
if ($existing !== '') {
    fwrite(STDOUT, "GUARD_BLOCKED\n");
    exit(0);
}

$result = zinesh_run_node_script(
    '__tests__/mock-evm-sender.mjs',
    ['probe' => true],
    15,
    static function (array $event) use ($id): void {
        $stage = (string)($event['stage'] ?? '');
        if ($stage !== 'broadcast' && $stage !== 'broadcast_uncertain') {
            return;
        }
        $h = trim((string)($event['txHash'] ?? ''));
        if ($h !== '') {
            zinesh_record_withdrawal_broadcast($id, $h);
        }
    },
    (require __DIR__ . '/node-env.php') + [
        'ZINESH_SEND_PROTOCOL' => 'v2',
        'MOCK_CASE' => 'broadcast_then_confirm',
        'MOCK_HASH' => $hash,
        'MOCK_COUNTER_FILE' => $counterFile,
        'MOCK_PRE_DELAY_MS' => (string)$preDelayMs,
        'MOCK_DELAY_MS' => (string)$postDelayMs,
    ]
);

fwrite(STDOUT, 'SENT:' . (string)($result['txHash'] ?? '') . "\n");
exit(0);
