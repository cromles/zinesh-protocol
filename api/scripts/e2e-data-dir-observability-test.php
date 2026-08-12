<?php
declare(strict_types=1);

/**
 * Data directory observability — izole doğrulama.
 *
 * İki şeyi kanıtlar:
 *  1) configuredDataDir / resolvedDataDir / dataDirMatchesConfig doğru üretiliyor,
 *     ve divergence log'u süreç başına en fazla bir kez yazılıyor.
 *  2) zinesh_resolve_data_dir() seçimi değişmedi: her senaryoda değişiklik öncesi
 *     _bootstrap.php ile birebir aynı dizin dönüyor.
 *
 * Baseline dosyası: ZINESH_BOOTSTRAP_BASELINE env veya
 * sys_get_temp_dir()/zinesh_bootstrap_baseline.php
 *
 * Gerçek data dizinine, config.php'ye veya production'a hiç dokunmaz.
 */

$passed = 0;
$failed = 0;
$skipped = 0;

function assert_test(string $name, bool $ok): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "  PASS: {$name}\n";
        return;
    }
    $failed++;
    echo "  FAIL: {$name}\n";
}

function skip_test(string $name, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP: {$name} ({$why})\n";
}

function norm_path(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? rm_tree($path) : @unlink($path);
    }
    @rmdir($dir);
}

$apiDir = dirname(__DIR__);
$currentBootstrap = $apiDir . '/_bootstrap.php';
$baselineBootstrap = (string)(getenv('ZINESH_BOOTSTRAP_BASELINE') ?: (sys_get_temp_dir() . '/zinesh_bootstrap_baseline.php'));

$root = sys_get_temp_dir() . '/zinesh_datadir_obs_' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);

/**
 * Senaryo fixture'ı kurar ve probe'u çalıştırır.
 *
 * @param array{cfgDir:?string,cfgExists:bool,cfgHasUsers:bool,dataDir:bool,dataHasUsers:bool} $spec
 * @return array{resolved:string,relative:string,diag:?array<string,mixed>,dataPath:string,logLines:int,raw:string}
 */
function run_probe(string $variantDir, string $bootstrapSrc, string $apiSrcDir, array $spec): array
{
    mkdir($variantDir, 0777, true);
    copy($bootstrapSrc, $variantDir . '/_bootstrap.php');
    copy($apiSrcDir . '/config.php', $variantDir . '/config.php');
    copy($apiSrcDir . '/protocol_constants.php', $variantDir . '/protocol_constants.php');

    $configuredValue = $spec['cfgDir'] === null ? '' : $variantDir . '/' . $spec['cfgDir'];
    file_put_contents(
        $variantDir . '/config.local.php',
        "<?php return ['data_dir' => " . var_export($configuredValue, true) . "];\n"
    );

    if ($spec['cfgDir'] !== null && $spec['cfgExists']) {
        mkdir($configuredValue, 0777, true);
        if ($spec['cfgHasUsers']) {
            file_put_contents($configuredValue . '/users.json', '[{"uid":"cfg"}]');
        }
    }
    if ($spec['dataDir']) {
        mkdir($variantDir . '/data', 0777, true);
        if ($spec['dataHasUsers']) {
            file_put_contents($variantDir . '/data/users.json', '[{"uid":"data"}]');
        }
    }

    $logFile = $variantDir . '/php_error.log';
    $probe = $variantDir . '/__probe.php';
    file_put_contents($probe, <<<'PHP'
<?php
putenv('ZINESH_SIM_DATA_DIR=');
require __DIR__ . '/_bootstrap.php';

$out = ['resolved' => zinesh_resolve_data_dir()];
// Aynı süreçte tekrar tekrar çağır: log spam olmamalı.
for ($i = 0; $i < 4; $i++) {
    zinesh_resolve_data_dir();
}
$out['dataPath'] = zinesh_data_path('users.json');
if (function_exists('zinesh_data_dir_diagnostics')) {
    $out['diag'] = zinesh_data_dir_diagnostics();
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
PHP);

    // Kabuk tırnaklamasını tamamen atlamak için dizi biçimi (Windows/Linux fark etmez).
    $cmd = [
        PHP_BINARY,
        '-d', 'log_errors=1',
        '-d', 'error_log=' . $logFile,
        '-d', 'display_errors=0',
        $probe,
    ];
    $raw = '';
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($proc)) {
        $raw = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }

    $decoded = json_decode(trim($raw), true);
    $resolved = is_array($decoded) ? (string)($decoded['resolved'] ?? '') : '';

    $logLines = 0;
    $logRaw = is_file($logFile) ? (string)file_get_contents($logFile) : '';
    if ($logRaw !== '') {
        foreach (preg_split('/\R/', $logRaw) ?: [] as $line) {
            if (str_contains($line, 'data_dir divergence')) {
                $logLines++;
            }
        }
    }

    $prefix = norm_path($variantDir);
    $relative = norm_path($resolved);
    if ($relative !== '' && str_starts_with($relative, $prefix)) {
        $relative = ltrim(substr($relative, strlen($prefix)), '/');
        $relative = $relative === '' ? '.' : $relative;
    }

    return [
        'resolved' => $resolved,
        'relative' => $relative,
        'diag' => is_array($decoded) && is_array($decoded['diag'] ?? null) ? $decoded['diag'] : null,
        'dataPath' => is_array($decoded) ? (string)($decoded['dataPath'] ?? '') : '',
        'logLines' => $logLines,
        'raw' => $raw,
        'logRaw' => $logRaw,
    ];
}

$scenarios = [
    'S1 configured geçerli (users.json + yazılabilir)' => [
        'spec' => ['cfgDir' => 'cfgdata', 'cfgExists' => true, 'cfgHasUsers' => true, 'dataDir' => true, 'dataHasUsers' => true],
        'expectRelative' => 'cfgdata',
        'expectMatch' => true,
        'expectLog' => 0,
    ],
    'S2 configured dizin hiç yok' => [
        'spec' => ['cfgDir' => 'cfgdata', 'cfgExists' => false, 'cfgHasUsers' => false, 'dataDir' => true, 'dataHasUsers' => true],
        'expectRelative' => 'data',
        'expectMatch' => false,
        'expectLog' => 1,
    ],
    'S3 configured var ama BOŞ (production replikası)' => [
        'spec' => ['cfgDir' => 'cfgdata', 'cfgExists' => true, 'cfgHasUsers' => false, 'dataDir' => true, 'dataHasUsers' => true],
        'expectRelative' => 'data',
        'expectMatch' => false,
        'expectLog' => 1,
    ],
    'S4 configured geçerli, api/data yok' => [
        'spec' => ['cfgDir' => 'cfgdata', 'cfgExists' => true, 'cfgHasUsers' => true, 'dataDir' => false, 'dataHasUsers' => false],
        'expectRelative' => 'cfgdata',
        'expectMatch' => true,
        'expectLog' => 0,
    ],
    'S5 configured boş string' => [
        'spec' => ['cfgDir' => null, 'cfgExists' => false, 'cfgHasUsers' => false, 'dataDir' => true, 'dataHasUsers' => true],
        'expectRelative' => 'data',
        'expectMatch' => false,
        'expectLog' => 0,
    ],
    'S6 hiçbirinde users.json yok' => [
        'spec' => ['cfgDir' => 'cfgdata', 'cfgExists' => true, 'cfgHasUsers' => false, 'dataDir' => true, 'dataHasUsers' => false],
        'expectRelative' => 'cfgdata',
        'expectMatch' => true,
        'expectLog' => 0,
    ],
];

$baselineAvailable = is_file($baselineBootstrap);

$i = 0;
foreach ($scenarios as $name => $case) {
    $i++;
    echo "\n=== {$name} ===\n";

    $newResult = run_probe($root . '/new_' . $i, $currentBootstrap, $apiDir, $case['spec']);

    if ($newResult['resolved'] === '') {
        assert_test('probe çalıştı', false);
        echo "    stdout/stderr: " . trim($newResult['raw']) . "\n";
        echo "    error_log: " . trim($newResult['logRaw']) . "\n";
        continue;
    }

    assert_test('çözülen dizin beklenen', $newResult['relative'] === $case['expectRelative']);

    $diag = $newResult['diag'];
    assert_test('diagnostics üretildi', is_array($diag));
    if (is_array($diag)) {
        assert_test(
            'resolvedDataDir gerçek çözümle aynı',
            norm_path((string)$diag['resolvedDataDir']) === norm_path($newResult['resolved'])
        );
        $expectedConfigured = $case['spec']['cfgDir'] === null
            ? ''
            : norm_path($root . '/new_' . $i . '/' . $case['spec']['cfgDir']);
        assert_test(
            'configuredDataDir config ile aynı',
            norm_path((string)$diag['configuredDataDir']) === $expectedConfigured
        );
        assert_test('dataDirMatchesConfig doğru', $diag['dataDirMatchesConfig'] === $case['expectMatch']);
    }

    assert_test(
        'divergence log sayısı beklenen (' . $case['expectLog'] . ')',
        $newResult['logLines'] === $case['expectLog']
    );

    assert_test(
        'zinesh_data_path çözülen dizini kullanıyor',
        norm_path($newResult['dataPath']) === norm_path($newResult['resolved']) . '/users.json'
    );

    if (!$baselineAvailable) {
        skip_test('baseline ile aynı dizin', 'baseline snapshot yok: ' . $baselineBootstrap);
        continue;
    }

    $baseResult = run_probe($root . '/base_' . $i, $baselineBootstrap, $apiDir, $case['spec']);
    assert_test(
        'baseline ile AYNI dizin (' . $baseResult['relative'] . ')',
        $baseResult['relative'] !== '' && $baseResult['relative'] === $newResult['relative']
    );
    assert_test('baseline hiç divergence log yazmadı', $baseResult['logLines'] === 0);
}

echo "\n=== LOG SPAM KONTROLÜ ===\n";
// S3 senaryosunda resolver 5 kez çağrıldı; tek satır bekleniyor.
$spamCase = run_probe(
    $root . '/spam',
    $currentBootstrap,
    $apiDir,
    ['cfgDir' => 'cfgdata', 'cfgExists' => true, 'cfgHasUsers' => false, 'dataDir' => true, 'dataHasUsers' => true]
);
assert_test('5 resolver çağrısına karşılık tek log satırı', $spamCase['logLines'] === 1);

echo "\n=== ENTEGRASYON: gerçek health ve backup çıktısı ===\n";
// İzole sim dizini: gerçek data dizinine dokunulmaz.
$simDir = $root . '/sim_data';
mkdir($simDir, 0777, true);

$integrationProbe = $root . '/__integration.php';
file_put_contents($integrationProbe, <<<PHP
<?php
require '{$apiDir}/_bootstrap.php';
require '{$apiDir}/wallet_lib.php';
require '{$apiDir}/founder_health_lib.php';
require '{$apiDir}/backup_lib.php';

\$health = zinesh_founder_system_health(true);
\$backup = zinesh_backup_run(3);
\$backupHealth = zinesh_json_read('backup_health.json');

echo json_encode([
    'health' => [
        'configuredDataDir' => \$health['configuredDataDir'] ?? null,
        'resolvedDataDir' => \$health['resolvedDataDir'] ?? null,
        'dataDirMatchesConfig' => \$health['dataDirMatchesConfig'] ?? null,
        'hasKeys' => array_key_exists('configuredDataDir', \$health)
            && array_key_exists('resolvedDataDir', \$health)
            && array_key_exists('dataDirMatchesConfig', \$health),
    ],
    'backupOk' => (bool)(\$backup['ok'] ?? false),
    'backupSource' => \$backup['source'] ?? null,
    'backupHealth' => [
        'configuredDataDir' => \$backupHealth['configuredDataDir'] ?? null,
        'resolvedDataDir' => \$backupHealth['resolvedDataDir'] ?? null,
        'dataDirMatchesConfig' => \$backupHealth['dataDirMatchesConfig'] ?? null,
        'lastPath' => \$backupHealth['lastPath'] ?? null,
    ],
], JSON_UNESCAPED_SLASHES);
PHP);

$env = getenv();
$env['ZINESH_SIM_DATA_DIR'] = $simDir;
$intOut = '';
$intErr = '';
$intProc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=1', $integrationProbe],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $intPipes,
    null,
    $env
);
if (is_resource($intProc)) {
    $intOut = (string)stream_get_contents($intPipes[1]);
    $intErr = (string)stream_get_contents($intPipes[2]);
    fclose($intPipes[1]);
    fclose($intPipes[2]);
    proc_close($intProc);
}

// founder_health harici komutlar çağırdığı için çıktıya gürültü karışabilir; JSON gövdesini ayıkla.
$jsonStart = strpos($intOut, '{');
$jsonEnd = strrpos($intOut, '}');
$integration = ($jsonStart === false || $jsonEnd === false || $jsonEnd < $jsonStart)
    ? null
    : json_decode(substr($intOut, $jsonStart, $jsonEnd - $jsonStart + 1), true);

if (!is_array($integration)) {
    assert_test('entegrasyon probe çalıştı', false);
    echo "    stdout: " . trim($intOut) . "\n";
    echo "    stderr: " . trim($intErr) . "\n";
} else {
    $expectedConfigured = norm_path((string)(require $apiDir . '/config.php')['data_dir']);

    assert_test('health üç alanı da içeriyor', ($integration['health']['hasKeys'] ?? false) === true);
    assert_test(
        'health.resolvedDataDir sim dizinini gösteriyor',
        norm_path((string)($integration['health']['resolvedDataDir'] ?? '')) === norm_path($simDir)
    );
    assert_test(
        'health.configuredDataDir config.php değerini gösteriyor',
        norm_path((string)($integration['health']['configuredDataDir'] ?? '')) === $expectedConfigured
    );
    assert_test(
        'health.dataDirMatchesConfig ayrışmayı false raporluyor',
        ($integration['health']['dataDirMatchesConfig'] ?? null) === false
    );

    assert_test('backup çalıştı', ($integration['backupOk'] ?? false) === true);
    assert_test(
        'backup kaynağı sim dizini (gerçek data dizinine dokunulmadı)',
        norm_path((string)($integration['backupSource'] ?? '')) === norm_path($simDir)
    );
    assert_test(
        'backup_health.resolvedDataDir sim dizinini gösteriyor',
        norm_path((string)($integration['backupHealth']['resolvedDataDir'] ?? '')) === norm_path($simDir)
    );
    assert_test(
        'backup_health.configuredDataDir config.php değerini gösteriyor',
        norm_path((string)($integration['backupHealth']['configuredDataDir'] ?? '')) === $expectedConfigured
    );
    assert_test(
        'backup_health.dataDirMatchesConfig ayrışmayı false raporluyor',
        ($integration['backupHealth']['dataDirMatchesConfig'] ?? null) === false
    );
}

echo "\n=== ÖZET ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Skipped: {$skipped}\n";
if (!$baselineAvailable) {
    echo "NOT: baseline karşılaştırması atlandı ({$baselineBootstrap} yok).\n";
}

rm_tree($root);

exit($failed === 0 ? 0 : 1);
