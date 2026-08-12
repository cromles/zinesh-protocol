<?php
declare(strict_types=1);

/**
 * Backup manifest, atomik yazımın yan ürünlerini (.tmp*, .lock) dışlamalı.
 * Gerçek finansal JSON dosyaları etkilenmemeli.
 * php api/scripts/e2e-backup-atomic-artifact-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/backup_lib.php';

$simDir = sys_get_temp_dir() . '/zinesh_backup_artifact_test_' . getmypid();
if (is_dir($simDir)) {
    zinesh_backup_remove_tree($simDir);
}
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';

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

/** Fixture'ı sıfırlar; her testin kendi dosya kümesiyle başlamasını sağlar. */
function reset_fixture(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $entry) {
        if (is_dir($entry)) {
            zinesh_backup_remove_tree($entry);
        } else {
            @unlink($entry);
        }
    }
}

function write_file(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    file_put_contents($path, $content);
}

echo "=== TEST 0: pattern yardımcısı ===\n";
assert_test('.tmp<pid>.<uniqid> artefakt sayılır', zinesh_backup_is_atomic_artifact('users.json.tmp12345.abc123'));
assert_test('.lock artefakt sayılır', zinesh_backup_is_atomic_artifact('users.json.lock'));
assert_test('gerçek json artefakt değil', !zinesh_backup_is_atomic_artifact('users.json'));
assert_test('login_locks.json artefakt değil', !zinesh_backup_is_atomic_artifact('login_locks.json'));
assert_test('havale_pending.json artefakt değil', !zinesh_backup_is_atomic_artifact('havale_pending.json'));

echo "=== TEST 1: .tmp dışlanır ===\n";
reset_fixture($simDir);
write_file($simDir . '/users.json', '[{"uid":"u1"}]');
write_file($simDir . '/users.json.tmp12345.abc', '{"partial":true}');
$m = zinesh_backup_manifest($simDir);
assert_test('users.json manifest\'te', isset($m['users.json']));
assert_test('users.json.tmp12345.abc manifest\'te değil', !isset($m['users.json.tmp12345.abc']));
assert_test('manifest tam olarak 1 dosya', array_keys($m) === ['users.json']);

echo "=== TEST 2: .lock dışlanır ===\n";
reset_fixture($simDir);
write_file($simDir . '/users.json', '[{"uid":"u1"}]');
write_file($simDir . '/users.json.lock', '');
$m = zinesh_backup_manifest($simDir);
assert_test('users.json manifest\'te', isset($m['users.json']));
assert_test('users.json.lock manifest\'te değil', !isset($m['users.json.lock']));
assert_test('manifest tam olarak 1 dosya', array_keys($m) === ['users.json']);
assert_test('lock dosyası diskte duruyor (silinmedi)', is_file($simDir . '/users.json.lock'));

echo "=== TEST 3: karışık fixture ===\n";
reset_fixture($simDir);
write_file($simDir . '/users.json', '[{"uid":"u1"}]');
write_file($simDir . '/users.json.lock', '');
write_file($simDir . '/users.json.tmp123', 'x');
write_file($simDir . '/escrow_rooms.json', '[{"id":"r1"}]');
write_file($simDir . '/escrow_rooms.json.lock', '');
write_file($simDir . '/escrow_rooms.json.tmp456', 'y');
write_file($simDir . '/login_locks.json', '{}');
$m = zinesh_backup_manifest($simDir);
$keys = array_keys($m);
sort($keys);
assert_test('yalnızca gerçek state dosyaları', $keys === ['escrow_rooms.json', 'login_locks.json', 'users.json']);
assert_test('hiçbir artefakt sızmadı', !array_filter($keys, static fn($k) => zinesh_backup_is_atomic_artifact($k)));
assert_test('tüm artefaktlar diskte korunuyor', is_file($simDir . '/users.json.lock')
    && is_file($simDir . '/escrow_rooms.json.tmp456'));

echo "=== TEST 4: iç içe dizinler ===\n";
reset_fixture($simDir);
write_file($simDir . '/users.json', '[]');
write_file($simDir . '/notifications/inbox_u1.json', '[]');
write_file($simDir . '/notifications/inbox_u1.json.lock', '');
write_file($simDir . '/notifications/inbox_u1.json.tmp123.def', 'x');
write_file($simDir . '/marketing/radar_state.json', '{"ok":true}');
write_file($simDir . '/marketing/radar_state.json.lock', '');
$m = zinesh_backup_manifest($simDir);
$keys = array_keys($m);
sort($keys);
assert_test('nested gerçek dosyalar korunuyor', $keys === [
    'marketing/radar_state.json',
    'notifications/inbox_u1.json',
    'users.json',
]);
assert_test('nested .lock dışlandı', !isset($m['notifications/inbox_u1.json.lock']));
assert_test('nested .tmp dışlandı', !isset($m['notifications/inbox_u1.json.tmp123.def']));
assert_test('alt dizin tespiti bozulmadı', zinesh_backup_top_level_subdirs_from_manifest($m) === ['marketing', 'notifications']);

echo "=== TEST 5: gerçek backup kopyası ===\n";
reset_fixture($simDir);
zinesh_ensure_core_data_files();
zinesh_json_write('users.json', [['uid' => 'u1', 'balance' => 100]]);
zinesh_json_write('escrow_rooms.json', [['id' => 'room-1']]);
write_file($simDir . '/notifications/inbox_u1.json', '[]');
// Atomik yazımın gerçekte bıraktığı kilitler + yetim bir temp kalıntısı.
write_file($simDir . '/users.json.tmp99999.orphan', '{"half":');
assert_test('atomik yazım gerçekten .lock bıraktı', is_file($simDir . '/users.json.lock'));

$result = zinesh_backup_run(30);
assert_test('artefaktlara rağmen backup ok', !empty($result['ok']));
assert_test('copy_failed yok', ($result['message'] ?? '') === '');
$verification = is_array($result['verification'] ?? null) ? $result['verification'] : [];
assert_test('doğrulama ok', !empty($verification['ok']));

$snapshot = (string)($result['path'] ?? '');
assert_test('snapshot dizini oluştu', is_dir($snapshot));
$snapshotFiles = [];
if (is_dir($snapshot)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($snapshot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $snapshotFiles[] = $f->getFilename();
        }
    }
}
assert_test('snapshot\'ta hiç artefakt yok', !array_filter($snapshotFiles, 'zinesh_backup_is_atomic_artifact'));
assert_test('snapshot gerçek state içeriyor', in_array('users.json', $snapshotFiles, true)
    && in_array('escrow_rooms.json', $snapshotFiles, true));
$restored = json_decode((string)file_get_contents($snapshot . '/users.json'), true);
assert_test('kopyalanan users.json geçerli ve tam', is_array($restored) && ($restored[0]['balance'] ?? null) === 100);
assert_test('kaynak artefaktları silinmedi', is_file($simDir . '/users.json.tmp99999.orphan')
    && is_file($simDir . '/users.json.lock'));

echo "=== TEST 6: asıl yarış koşulu (manifest sonrası .tmp kayboluyor) ===\n";
reset_fixture($simDir);
write_file($simDir . '/users.json', '[{"uid":"u1"}]');
write_file($simDir . '/escrow_rooms.json', '[]');
$tmpPath = $simDir . '/users.json.tmp' . getmypid() . '.race';
write_file($tmpPath, '{"in":"flight"}');

// Manifest, .tmp diskteyken alınır.
$srcManifest = zinesh_backup_manifest($simDir);
assert_test('uçuştaki .tmp manifest\'e girmedi', !isset($srcManifest['users.json.tmp' . getmypid() . '.race']));

// Atomik rename tamamlanır: .tmp artık yok.
@unlink($tmpPath);
assert_test('.tmp rename ile kayboldu', !is_file($tmpPath));

$staging = sys_get_temp_dir() . '/zinesh_backup_artifact_stage_' . getmypid();
if (is_dir($staging)) {
    zinesh_backup_remove_tree($staging);
}
mkdir($staging, 0750, true);
$copy = zinesh_backup_copy_tree($simDir, $staging);
assert_test('kopyalama copy_failed vermedi', !empty($copy['ok']));
$dstManifest = zinesh_backup_manifest($staging, []);
$verify = zinesh_backup_verify_manifests($srcManifest, $dstManifest);
assert_test('doğrulama missing: hatası vermedi', !empty($verify['ok']));
assert_test('gerçek state kopyalandı', is_file($staging . '/users.json') && is_file($staging . '/escrow_rooms.json'));
zinesh_backup_remove_tree($staging);

zinesh_backup_remove_tree($simDir);

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
