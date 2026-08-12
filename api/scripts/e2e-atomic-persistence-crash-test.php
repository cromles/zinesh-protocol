<?php
declare(strict_types=1);

/**
 * Atomik kalıcılık — crash safety regression
 * php api/scripts/e2e-atomic-persistence-crash-test.php
 *
 * Doğrulanan değişmez: süreç hangi noktada öldürülürse öldürülsün hedef JSON dosyası
 * ya eski geçerli state'i ya da tamamen yazılmış yeni state'i gösterir; asla boş,
 * asla yarım kalmaz.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_atomic_crash_' . getmypid();
if (!is_dir($simDir)) {
    mkdir($simDir, 0750, true);
}
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';

$passed = 0;
$failed = 0;
$skipped = 0;

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

function skip_test(string $label, string $reason): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP: {$label} — {$reason}\n";
}

/** Toplamı sabit, dağılımı farklı iki cüzdan state'i üretir. */
function build_state(int $count, float $shift): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'uid' => 'u' . $i,
            'email' => 'u' . $i . '@test.local',
            'name' => 'Kullanıcı ' . $i,
            'ticketNumber' => (string)(60000 + $i),
            'usdtBalance' => 1000.0,
            'escrowBalance' => 0.0,
        ];
    }
    $rows[0]['usdtBalance'] = 1000.0 - $shift;
    $rows[1]['usdtBalance'] = 1000.0 + $shift;
    return $rows;
}

function total_money(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row['usdtBalance'] ?? 0) + (float)($row['escrowBalance'] ?? 0);
    }
    return round($total, 2);
}

function encode_state(array $rows): string
{
    return (string)json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/** @return list<string> */
function temp_leftovers(string $path): array
{
    return glob($path . '.tmp*') ?: [];
}

$usersPath = zinesh_data_path('users.json');
$stateA = build_state(600, 0.0);
$stateB = build_state(600, 250.0);
$sigA = encode_state($stateA);
$sigB = encode_state($stateB);
$expectedTotal = total_money($stateA);

echo "=== TEST 1: commit doğruluğu ===\n";
zinesh_json_write('users.json', $stateA);
assert_test('hedef dosya oluştu', is_file($usersPath));
assert_test('içerik birebir kodlanmış state', file_get_contents($usersPath) === $sigA);
assert_test('geçerli JSON', is_array(json_decode((string)file_get_contents($usersPath), true)));
assert_test('commit sonrası temp kalıntısı yok', temp_leftovers($usersPath) === []);
assert_test('kilit dosyası ayrı tutuluyor', is_file($usersPath . '.lock'));
assert_test('toplam para korunuyor', total_money(zinesh_json_read('users.json')) === $expectedTotal);

if (DIRECTORY_SEPARATOR === '/') {
    chmod($usersPath, 0640);
    zinesh_json_atomic('users.json', static function (array &$rows) use ($stateB) {
        $rows = $stateB;
        return true;
    });
    assert_test('dosya izinleri korunuyor', (fileperms($usersPath) & 0777) === 0640);
    chmod($usersPath, 0664);
} else {
    skip_test('dosya izinleri korunuyor', 'POSIX izinleri yalnızca Linux/macOS üzerinde anlamlı');
}

echo "=== TEST 2: eşzamanlılık — kayıp güncelleme yok ===\n";
if (!function_exists('pcntl_fork')) {
    skip_test('paralel artırma kaybı yok', 'pcntl eklentisi yok');
} else {
    $childCount = 6;
    $perChild = 25;
    zinesh_json_write('counter.json', ['n' => 0]);
    $pids = [];
    for ($c = 0; $c < $childCount; $c++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            break;
        }
        if ($pid === 0) {
            for ($k = 0; $k < $perChild; $k++) {
                zinesh_json_atomic('counter.json', static function (array &$row) {
                    $row['n'] = (int)($row['n'] ?? 0) + 1;
                    return true;
                });
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $finalCounter = (int)(zinesh_json_read('counter.json')['n'] ?? -1);
    assert_test(
        'paralel artırma kaybı yok (' . $finalCounter . '/' . ($childCount * $perChild) . ')',
        $finalCounter === $childCount * $perChild
    );
}

echo "=== TEST 3a: rename öncesi ölüm (deterministik) ===\n";
if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    skip_test('rename öncesi ölümde eski state korunuyor', 'pcntl/posix eklentisi yok');
} else {
    // Çocuk kendini mutator içinde, commit hiç çalışmadan öldürür: D senaryosu.
    zinesh_json_write('users.json', $stateA);
    $pid = pcntl_fork();
    if ($pid === 0) {
        zinesh_json_atomic('users.json', static function (array &$rows) use ($stateB) {
            $rows = $stateB;
            posix_kill(getmypid(), SIGKILL);
            return true;
        });
        exit(0);
    }
    $status = 0;
    pcntl_waitpid($pid, $status);
    assert_test('çocuk gerçekten SIGKILL ile öldü', pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL);
    assert_test('rename öncesi ölümde eski state korunuyor', file_get_contents($usersPath) === $sigA);

    // Ölen süreç kilidi bıraktı mı: sonraki yazım bloklanmadan tamamlanmalı.
    zinesh_json_write('users.json', $stateA);
    assert_test('ölen süreç kilidi bırakıyor (deadlock yok)', file_get_contents($usersPath) === $sigA);
}

echo "=== TEST 3b: rename sonrası ölüm (deterministik) ===\n";
if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    skip_test('rename sonrası ölümde yeni state tam görünüyor', 'pcntl/posix eklentisi yok');
} else {
    // Çocuk commit döndükten hemen sonra, normal çıkışa fırsat bulmadan ölür: E senaryosu.
    zinesh_json_write('users.json', $stateA);
    $pid = pcntl_fork();
    if ($pid === 0) {
        zinesh_json_atomic('users.json', static function (array &$rows) use ($stateB) {
            $rows = $stateB;
            return true;
        });
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }
    $status = 0;
    pcntl_waitpid($pid, $status);
    assert_test('çocuk commit sonrası SIGKILL ile öldü', pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL);
    assert_test('rename sonrası ölümde yeni state tam görünüyor', file_get_contents($usersPath) === $sigB);
}

echo "=== TEST 3c: rastgele kill fuzz (A–E arası tüm noktalar) ===\n";
if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
    skip_test('crash sırasında state bütünlüğü', 'pcntl/posix eklentisi yok');
} else {
    $iterations = 80;
    $rounds = 0;
    $sawOld = 0;
    $sawNew = 0;
    $corrupt = 0;
    $emptyFile = 0;
    $badTotal = 0;

    for ($i = 0; $i < $iterations; $i++) {
        zinesh_json_write('users.json', $stateA);

        $pid = pcntl_fork();
        if ($pid === -1) {
            break;
        }
        $rounds++;
        if ($pid === 0) {
            zinesh_json_atomic('users.json', static function (array &$rows) use ($stateB) {
                $rows = $stateB;
                return true;
            });
            exit(0);
        }

        // Kill anını commit penceresine yay: temp oluşturma, kısmi yazım,
        // fflush sonrası, rename öncesi ve rename sonrası noktalarına denk gelir.
        usleep(random_int(50, 4000));
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);

        $raw = (string)file_get_contents($usersPath);
        if ($raw === '') {
            $emptyFile++;
            continue;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $decoded === []) {
            $corrupt++;
            continue;
        }
        if (total_money($decoded) !== $expectedTotal) {
            $badTotal++;
            continue;
        }
        if ($raw === $sigA) {
            $sawOld++;
        } elseif ($raw === $sigB) {
            $sawNew++;
        } else {
            $corrupt++;
        }
    }

    echo "  (eski state: {$sawOld}, yeni state: {$sawNew}, boş: {$emptyFile}, bozuk: {$corrupt})\n";
    assert_test('hiçbir zaman boş dosya', $emptyFile === 0);
    assert_test('hiçbir zaman partial/bozuk JSON', $corrupt === 0);
    assert_test('toplam para her turda sabit', $badTotal === 0);
    // Kill anı rastgele; hangi tarafa düştüğü değil, her turun geçerli bir state'e düşmesi aranır.
    assert_test('her tur eski veya yeni state ile sonuçlandı', $rounds > 0 && ($sawOld + $sawNew) === $rounds);
}

echo "=== TEST 4: okuyucu yarım içerik görmez ===\n";
if (!function_exists('pcntl_fork')) {
    skip_test('eşzamanlı okuma bütünlüğü', 'pcntl eklentisi yok');
} else {
    zinesh_json_write('users.json', $stateA);
    $writer = pcntl_fork();
    if ($writer === 0) {
        for ($k = 0; $k < 120; $k++) {
            $next = $k % 2 === 0 ? $stateB : $stateA;
            zinesh_json_atomic('users.json', static function (array &$rows) use ($next) {
                $rows = $next;
                return true;
            });
        }
        exit(0);
    }

    $reads = 0;
    $dirtyReads = 0;
    $deadline = microtime(true) + 1.5;
    while (microtime(true) < $deadline) {
        $raw = (string)file_get_contents($usersPath);
        $reads++;
        if ($raw !== $sigA && $raw !== $sigB) {
            $dirtyReads++;
        }
        $status = 0;
        if (pcntl_waitpid($writer, $status, WNOHANG) === $writer) {
            $writer = -1;
            break;
        }
    }
    if ($writer > 0) {
        posix_kill($writer, SIGKILL);
        pcntl_waitpid($writer, $status);
    }
    echo "  ({$reads} okuma yapıldı)\n";
    assert_test('kilitsiz okumalar hep bütün state gördü', $dirtyReads === 0);
    assert_test('okuma sayısı anlamlı', $reads > 10);
}

echo "=== TEST 5: temp kalıntıları zararsız ===\n";
$leftovers = temp_leftovers($usersPath);
echo '  (' . count($leftovers) . " temp kalıntısı)\n";
$leftoverIsTarget = false;
foreach ($leftovers as $leftover) {
    if ($leftover === $usersPath) {
        $leftoverIsTarget = true;
    }
}
assert_test('kalıntı hiçbir zaman hedef dosya değil', $leftoverIsTarget === false);
$jsonGlobHasTemp = false;
foreach (glob($simDir . '/*.json') ?: [] as $globbed) {
    if (str_contains(basename($globbed), '.tmp')) {
        $jsonGlobHasTemp = true;
    }
}
assert_test('kalıntılar *.json globuna karışmıyor', $jsonGlobHasTemp === false);
foreach ($leftovers as $leftover) {
    @unlink($leftover);
}
assert_test('süpürme sonrası hedef dosya sağlam', is_array(json_decode((string)file_get_contents($usersPath), true)));
assert_test('süpürme sonrası kalıntı yok', temp_leftovers($usersPath) === []);

echo "=== TEST 6: finansal değişmezler ===\n";
$finalRows = zinesh_json_read('users.json');
assert_test('son state geçerli', $finalRows !== []);
assert_test('para yaratılmadı/yok olmadı', total_money($finalRows) === $expectedTotal);
assert_test('kullanıcı sayısı korunuyor', count($finalRows) === count($stateA));

// Sim dizinini temizle
foreach (glob($simDir . '/*') ?: [] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}
@rmdir($simDir);

echo "\n=== Summary: {$passed} passed, {$failed} failed, {$skipped} skipped ===\n";
exit($failed > 0 ? 1 : 0);
