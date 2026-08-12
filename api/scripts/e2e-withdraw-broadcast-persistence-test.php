<?php
declare(strict_types=1);

/**
 * Broadcast hash kalıcılaştırma + NDJSON okuyucu testleri.
 * php api/scripts/e2e-withdraw-broadcast-persistence-test.php
 *
 * Tamamen izole: sahte gönderici kullanılır, gerçek RPC/anahtar/USDT yoktur,
 * veri dizini geçici bir klasöre yönlendirilir.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_wd_broadcast_' . getmypid();
if (!is_dir($simDir)) {
    mkdir($simDir, 0750, true);
}
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/withdraw_executor.php';

zinesh_ensure_core_data_files();
zinesh_json_write('withdrawals.json', []);

$counterFile = $simDir . '/send_counter.log';
$hostNodeEnv = require __DIR__ . '/__tests__/node-env.php';
$passed = 0;
$failed = 0;

function assert_test(string $label, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}\n";
    }
}

function seed_withdrawal(string $id, array $extra = []): void
{
    $all = zinesh_json_read('withdrawals.json');
    $all[] = array_merge([
        'id' => $id,
        'uid' => '',
        'network' => 'arbitrum',
        'address' => '0x000000000000000000000000000000000000dEaD',
        'amount' => 10.0,
        'status' => 'processing',
        'createdAt' => date('c'),
    ], $extra);
    zinesh_json_write('withdrawals.json', $all);
}

function get_withdrawal(string $id): ?array
{
    foreach (zinesh_json_read('withdrawals.json') as $w) {
        if (($w['id'] ?? '') === $id) {
            return $w;
        }
    }
    return null;
}

function send_count(): int
{
    global $counterFile;
    if (!is_file($counterFile)) {
        return 0;
    }
    return count(file($counterFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

/** Üretimdeki broadcast callback'inin aynısı. */
function broadcast_callback(string $id): callable
{
    return static function (array $event) use ($id): void {
        $stage = (string)($event['stage'] ?? '');
        if ($stage !== 'broadcast' && $stage !== 'broadcast_uncertain') {
            return;
        }
        $hash = trim((string)($event['txHash'] ?? ''));
        if ($hash === '') {
            return;
        }
        zinesh_record_withdrawal_broadcast($id, $hash);
    };
}

function run_mock(string $case, ?string $id, string $hash, int $timeout = 5, int $delayMs = 0, bool $v2 = true): array
{
    global $counterFile, $hostNodeEnv;
    $env = $hostNodeEnv + [
        'MOCK_CASE' => $case,
        'MOCK_HASH' => $hash,
        'MOCK_DELAY_MS' => (string)$delayMs,
        'MOCK_COUNTER_FILE' => $counterFile,
    ];
    if ($v2) {
        $env['ZINESH_SEND_PROTOCOL'] = 'v2';
    }
    return zinesh_run_node_script(
        '__tests__/mock-evm-sender.mjs',
        ['probe' => true],
        $timeout,
        $id === null ? null : broadcast_callback($id),
        $env
    );
}

$HASH_A = '0x' . str_repeat('a1', 32);
$HASH_B = '0x' . str_repeat('b2', 32);

echo "\n=== ÖN KONTROL ===\n";
$probe = run_mock('legacy_ok', null, $HASH_A, 5, 0, false);
if (empty($probe['ok'])) {
    echo "  ATLANDI: node çalıştırılamadı — " . (string)($probe['error'] ?? '?') . "\n";
    echo "  Bu test node gerektirir.\n";
    exit(2);
}
assert_test('sahte gönderici çalışıyor', true);

echo "\n=== TEST A — broadcast → persistence → confirmation → completed ===\n";
seed_withdrawal('wd-a');
$res = run_mock('broadcast_then_confirm', 'wd-a', $HASH_A);
assert_test('A1 sonuç ok', !empty($res['ok']));
assert_test('A2 receiptStatus = 1', ($res['receiptStatus'] ?? null) === 1);
$w = get_withdrawal('wd-a');
assert_test('A3 outboundTx kalıcılaştı', ($w['outboundTx'] ?? '') === $HASH_A);
assert_test('A4 submittedAt yazıldı', !empty($w['submittedAt']));
zinesh_finalize_withdrawal_completed('wd-a', (string)$res['txHash'], true);
$w = get_withdrawal('wd-a');
assert_test('A5 completed', ($w['status'] ?? '') === 'completed');
assert_test('A6 hash korunuyor', ($w['outboundTx'] ?? '') === $HASH_A);

echo "\n=== TEST B/C + FAILURE INJECTION — broadcast → kill/timeout → retry ===\n";
seed_withdrawal('wd-b');
$before = send_count();
$res = run_mock('broadcast_then_hang', 'wd-b', $HASH_B, 3);
assert_test('B1 timeout raporlandı', !empty($res['timedOut']));
assert_test('B2 timeout dalında event korundu', !empty($res['events']));
$w = get_withdrawal('wd-b');
assert_test('B3 hash timeout\'a rağmen kalıcı', ($w['outboundTx'] ?? '') === $HASH_B);
assert_test('B4 pending\'e düşmedi', ($w['status'] ?? '') === 'processing');
assert_test('B5 gönderici bir kez çalıştı', send_count() - $before === 1);

// Kesintiden sonra ikinci executor aynı withdrawal'ı görür.
$afterKill = send_count();
$retry = zinesh_send_claimed_withdrawal(get_withdrawal('wd-b'));
assert_test('C1 retry recovered döndü', !empty($retry['recovered']));
assert_test('C2 retry aynı hash', ($retry['txHash'] ?? '') === $HASH_B);
assert_test('C3 İKİNCİ BROADCAST YOK', send_count() === $afterKill);
$w = get_withdrawal('wd-b');
assert_test('C4 completed, hash korunuyor', ($w['status'] ?? '') === 'completed' && ($w['outboundTx'] ?? '') === $HASH_B);

echo "\n=== TEST D — broadcast başarısız ===\n";
seed_withdrawal('wd-d');
$res = run_mock('broadcast_fail', 'wd-d', $HASH_A);
assert_test('D1 sonuç başarısız', empty($res['ok']));
$w = get_withdrawal('wd-d');
assert_test('D2 outboundTx yok', trim((string)($w['outboundTx'] ?? '')) === '');
zinesh_release_withdrawal_to_pending('wd-d', 'mock hata');
$w = get_withdrawal('wd-d');
assert_test('D3 güvenli retry: pending', ($w['status'] ?? '') === 'pending');

echo "\n=== TEST E — confirmation revert ===\n";
seed_withdrawal('wd-e');
$res = run_mock('broadcast_then_revert', 'wd-e', $HASH_A);
assert_test('E1 sonuç başarısız', empty($res['ok']));
$w = get_withdrawal('wd-e');
assert_test('E2 hash korunuyor', ($w['outboundTx'] ?? '') === $HASH_A);
zinesh_release_withdrawal_to_pending('wd-e', 'revert');
$w = get_withdrawal('wd-e');
assert_test('E3 pending\'e düşmedi', ($w['status'] ?? '') === 'processing');
assert_test('E4 completed üretilmedi', ($w['status'] ?? '') !== 'completed');

echo "\n=== TEST E2 — receipt status bilinmiyor ===\n";
seed_withdrawal('wd-e2');
$res = run_mock('broadcast_then_unknown_status', 'wd-e2', $HASH_A);
assert_test('E2-1 receiptStatus null bildirildi', array_key_exists('receiptStatus', $res) && $res['receiptStatus'] === null);
$w = get_withdrawal('wd-e2');
assert_test('E2-2 hash korunuyor', ($w['outboundTx'] ?? '') === $HASH_A);

echo "\n=== TEST F — chunked stdout ===\n";
seed_withdrawal('wd-f');
$res = run_mock('chunked', 'wd-f', $HASH_B);
assert_test('F1 sonuç ok', !empty($res['ok']));
$w = get_withdrawal('wd-f');
assert_test('F2 parçalı satırdan hash doğru kuruldu', ($w['outboundTx'] ?? '') === $HASH_B);

echo "\n=== TEST G — legacy EVM modu ===\n";
$res = run_mock('legacy_ok', null, $HASH_A, 5, 0, false);
assert_test('G1 legacy ok', !empty($res['ok']));
assert_test('G2 legacy txHash', ($res['txHash'] ?? '') === $HASH_A);
assert_test('G3 legacy stage alanı yok', !isset($res['stage']));

echo "\n=== TEST H — TRON legacy şekli ===\n";
$res = run_mock('legacy_tron', null, $HASH_A, 5, 0, false);
assert_test('H1 tron ok', !empty($res['ok']));
assert_test('H2 tron txId korunuyor', ($res['txId'] ?? '') === $HASH_A);

echo "\n=== TEST I — eşzamanlı claim ===\n";
seed_withdrawal('wd-i', ['status' => 'pending']);
$first = zinesh_claim_withdrawal_for_processing('wd-i', ['pending']);
$second = zinesh_claim_withdrawal_for_processing('wd-i', ['pending']);
assert_test('I1 ilk claim başarılı', $first !== null);
assert_test('I2 ikinci claim reddedildi', $second === null);
$w = get_withdrawal('wd-i');
assert_test('I3 status processing', ($w['status'] ?? '') === 'processing');

// Varsayılan claim artık yalnızca pending kabul eder; processing kaydı yeniden
// almak açık kurtarma izin listesi gerektirir.
assert_test('I4 varsayılan claim processing kaydını reddediyor', zinesh_claim_withdrawal_for_processing('wd-i') === null);
$reclaim = zinesh_claim_withdrawal_for_processing('wd-i', ['pending', 'processing']);
assert_test('I4b kurtarma izin listesiyle claim mümkün', $reclaim !== null);

zinesh_record_withdrawal_broadcast('wd-i', $HASH_A);
$beforeGuard = send_count();
$guard = zinesh_send_claimed_withdrawal(get_withdrawal('wd-i'));
assert_test('I5 outboundTx varsa gönderim yapılmaz', send_count() === $beforeGuard);
assert_test('I6 recovered döndü', !empty($guard['recovered']));

echo "\n=== TEST J — broadcast kaydı idempotent ===\n";
seed_withdrawal('wd-j');
zinesh_record_withdrawal_broadcast('wd-j', $HASH_A);
zinesh_record_withdrawal_broadcast('wd-j', $HASH_B);
$w = get_withdrawal('wd-j');
assert_test('J1 ilk hash üzerine yazılmadı', ($w['outboundTx'] ?? '') === $HASH_A);
assert_test('J2 bilinmeyen id false döner', zinesh_record_withdrawal_broadcast('wd-yok', $HASH_A) === false);

echo "\n==============================\n";
echo "PASS: {$passed}  FAIL: {$failed}\n";

// Geçici veri dizinini temizle.
foreach (glob($simDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($simDir);

exit($failed === 0 ? 0 : 1);
