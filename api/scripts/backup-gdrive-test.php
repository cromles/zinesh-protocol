<?php
declare(strict_types=1);

/**
 * Manual Google Drive backup test (no cron).
 * php api/scripts/backup-gdrive-test.php [snapshot_name]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
require_once $apiDir . '/_bootstrap.php';
require_once $apiDir . '/backup_lib.php';
require_once $apiDir . '/backup_gdrive_lib.php';

$snapshotName = $argv[1] ?? '2026-08-09_11-14';
$dataDir = zinesh_resolve_data_dir();
$localSnapshot = rtrim($dataDir, '/') . '/backups/' . $snapshotName;
$remoteRel = trim((string)zinesh_backup_gdrive_config()['test_prefix'], '/') . '/' . $snapshotName;
$restoreDir = '/tmp/zinesh-gdrive-restore-test';

echo "=== Google Drive backup manual test ===\n";
echo "local_snapshot={$localSnapshot}\n";
echo "remote_rel={$remoteRel}\n";

if (!is_dir($localSnapshot)) {
    fwrite(STDERR, "ERROR: local snapshot not found\n");
    exit(1);
}

$ready = zinesh_backup_gdrive_remote_ready();
if (empty($ready['ok'])) {
    fwrite(STDERR, 'ERROR: rclone remote not ready: ' . ($ready['message'] ?? 'unknown') . "\n");
    fwrite(STDERR, "Run on server as root:\n");
    fwrite(STDERR, "  rclone authorize \"drive\" --config /root/.config/rclone/rclone.conf\n");
    fwrite(STDERR, "Then: rclone config create zinesh-drive drive config_is_local=false --config /root/.config/rclone/rclone.conf\n");
    exit(2);
}

$version = zinesh_backup_gdrive_rclone(['version']);
echo 'rclone_version=' . trim(strtok($version['stdout'], "\n")) . "\n";

$upload = zinesh_backup_gdrive_upload_snapshot($localSnapshot, $remoteRel);
echo json_encode($upload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
if (empty($upload['ok'])) {
    exit(3);
}

$restore = zinesh_backup_gdrive_restore_verify($localSnapshot, $remoteRel, $restoreDir);
echo json_encode($restore, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

zinesh_backup_remove_tree($restoreDir);
exit(empty($restore['ok']) ? 4 : 0);
