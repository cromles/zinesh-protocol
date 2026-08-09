<?php
declare(strict_types=1);

/**
 * Backup snapshot + restore verification (isolated sim data).
 * php api/scripts/e2e-backup-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_backup_test_' . getmypid();
if (is_dir($simDir)) {
    require_once $apiDir . '/backup_lib.php';
    zinesh_backup_remove_tree($simDir);
}
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/backup_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('users.json', [['uid' => 'u1', 'email' => 'backup@test.local']]);
zinesh_json_write('escrow_rooms.json', [['id' => 'room-1']]);

$marketingDir = $simDir . '/marketing';
mkdir($marketingDir, 0750, true);
file_put_contents($marketingDir . '/radar_state.json', '{"ok":true}', LOCK_EX);

$notificationsDir = $simDir . '/notifications';
mkdir($notificationsDir, 0750, true);
file_put_contents($notificationsDir . '/inbox_u1.json', '[]', LOCK_EX);

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

echo "=== backup run ===\n";
$srcManifestBefore = zinesh_backup_manifest($simDir);
$result = zinesh_backup_run(30);
assert_test('backup ok', !empty($result['ok']));
assert_test('resolved sim dir', ($result['source'] ?? '') === $simDir);
assert_test('backup path exists', is_dir((string)($result['path'] ?? '')));

$verification = is_array($result['verification'] ?? null) ? $result['verification'] : [];
assert_test('verification ok', !empty($verification['ok']));
assert_test('marketing subdir', in_array('marketing', $verification['backup_subdirs'] ?? [], true));
assert_test('notifications subdir', in_array('notifications', $verification['backup_subdirs'] ?? [], true));
assert_test('root json present', zinesh_backup_count_root_json($srcManifestBefore) >= 2);

echo "=== restore test (isolated) ===\n";
$restoreDir = sys_get_temp_dir() . '/zinesh_restore_test_' . getmypid();
if (is_dir($restoreDir)) {
    zinesh_backup_remove_tree($restoreDir);
}
mkdir($restoreDir, 0750, true);

$copy = zinesh_backup_copy_tree((string)$result['path'], $restoreDir, []);
assert_test('restore copy ok', !empty($copy['ok']));

$restoreManifest = zinesh_backup_manifest($restoreDir, []);
$backupManifest = zinesh_backup_manifest((string)$result['path'], []);
$restoreVerify = zinesh_backup_verify_manifests($backupManifest, $restoreManifest);
assert_test('restore matches backup', !empty($restoreVerify['ok']));

$srcVsRestore = zinesh_backup_verify_manifests($srcManifestBefore, $restoreManifest);
assert_test('restore matches pre-backup source', !empty($srcVsRestore['ok']));

zinesh_backup_remove_tree($restoreDir);
zinesh_backup_remove_tree($simDir);

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
