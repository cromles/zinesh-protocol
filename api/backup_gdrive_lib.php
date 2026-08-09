<?php
declare(strict_types=1);

/**
 * Google Drive off-site backup via rclone (optional layer — never blocks local backup).
 */

require_once __DIR__ . '/backup_lib.php';

function zinesh_backup_gdrive_defaults(): array
{
    return [
        'enabled' => true,
        'remote' => 'zinesh-drive',
        'root' => 'Zinesh Backups',
        'rclone_bin' => '/usr/bin/rclone',
        'config_path' => '/root/.config/rclone/rclone.conf',
        'test_prefix' => 'test',
        'hourly_prefix' => 'hourly',
    ];
}

function zinesh_backup_gdrive_config(): array
{
    $defaults = zinesh_backup_gdrive_defaults();
    $cfg = zinesh_config()['backup']['gdrive'] ?? [];
    if (!is_array($cfg)) {
        return $defaults;
    }
    return array_merge($defaults, $cfg);
}

function zinesh_backup_gdrive_config_path(): string
{
    return (string)zinesh_backup_gdrive_config()['config_path'];
}

function zinesh_backup_gdrive_remote(): string
{
    return (string)zinesh_backup_gdrive_config()['remote'];
}

function zinesh_backup_gdrive_root(): string
{
    return trim((string)zinesh_backup_gdrive_config()['root'], '/');
}

/** @return array{ok:bool, stdout:string, stderr:string, exit_code:int} */
function zinesh_backup_gdrive_rclone(array $args): array
{
    $cfg = zinesh_backup_gdrive_config();
    $bin = (string)$cfg['rclone_bin'];
    if (!is_executable($bin)) {
        $which = trim((string)@shell_exec('command -v rclone 2>/dev/null') ?: '');
        if ($which !== '' && is_executable($which)) {
            $bin = $which;
        }
    }
    $config = (string)$cfg['config_path'];
    $cmd = array_merge([$bin, '--config', $config], $args);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptor, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'proc_open_failed', 'exit_code' => 1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exit = proc_close($proc);
    return [
        'ok' => $exit === 0,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
        'exit_code' => $exit,
    ];
}

/** @return array{generated_at:string, file_count:int, total_bytes:int, files:list<array{relative_path:string,size:int,sha256:string}>} */
function zinesh_backup_build_sha256_manifest(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files = [];
    $total = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        $full = str_replace('\\', '/', $item->getPathname());
        $rel = ltrim(substr($full, strlen($root)), '/');
        if ($rel === 'manifest.json') {
            continue;
        }
        $size = (int)$item->getSize();
        $total += $size;
        $files[] = [
            'relative_path' => $rel,
            'size' => $size,
            'sha256' => hash_file('sha256', $full),
        ];
    }
    usort($files, static fn(array $a, array $b): int => strcmp($a['relative_path'], $b['relative_path']));
    return [
        'generated_at' => date('c'),
        'file_count' => count($files),
        'total_bytes' => $total,
        'files' => $files,
    ];
}

function zinesh_backup_write_manifest_json(string $root): string
{
    $manifest = zinesh_backup_build_sha256_manifest($root);
    $path = rtrim($root, '/') . '/manifest.json';
    file_put_contents(
        $path,
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $path;
}

function zinesh_backup_gdrive_hourly_prefix(): string
{
    return trim((string)zinesh_backup_gdrive_config()['hourly_prefix'], '/');
}

/** @return array{ok:bool, message?:string} */
function zinesh_backup_gdrive_remote_snapshot_exists(string $remoteRelativePath): bool
{
    $remoteRelativePath = trim(str_replace('\\', '/', $remoteRelativePath), '/');
    $remote = zinesh_backup_gdrive_remote();
    $root = zinesh_backup_gdrive_root();
    $remoteDest = $remote . ':' . $root . '/' . $remoteRelativePath;

    $ls = zinesh_backup_gdrive_rclone(['lsjson', $remoteDest, '--recursive']);
    if (empty($ls['ok'])) {
        return false;
    }
    $remoteFiles = json_decode($ls['stdout'], true);
    if (!is_array($remoteFiles)) {
        return false;
    }
    foreach ($remoteFiles as $row) {
        if (!is_array($row) || !empty($row['IsDir'])) {
            continue;
        }
        $path = (string)($row['Path'] ?? '');
        if ($path === 'manifest.json' || str_ends_with($path, '/manifest.json')) {
            return true;
        }
    }
    return false;
}

function zinesh_backup_gdrive_should_skip_upload(string $snapshotBasename, string $remoteRelativePath): bool
{
    $existing = [];
    $path = zinesh_data_path('backup_health.json');
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    if (
        ($existing['lastGoogleDriveSnapshot'] ?? '') === $snapshotBasename
        && ($existing['googleDriveStatus'] ?? '') === 'ok'
    ) {
        return true;
    }
    return zinesh_backup_gdrive_remote_snapshot_exists($remoteRelativePath);
}

/** @return array{ok:bool, message?:string} */
function zinesh_backup_gdrive_remote_ready(): array
{
    $cfg = zinesh_backup_gdrive_config();
    $config = (string)$cfg['config_path'];
    if (!is_readable($config)) {
        return ['ok' => false, 'message' => 'rclone_config_missing'];
    }
    $remote = (string)$cfg['remote'];
    $result = zinesh_backup_gdrive_rclone(['listremotes']);
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => 'rclone_listremotes_failed'];
    }
    if (!str_contains($result['stdout'], $remote . ':')) {
        return ['ok' => false, 'message' => 'remote_not_configured'];
    }
    return ['ok' => true];
}

/**
 * @return array<string,mixed>
 */
function zinesh_backup_gdrive_upload_snapshot(string $localSnapshotPath, string $remoteRelativePath, bool $updateHealth = true): array
{
    $localSnapshotPath = rtrim($localSnapshotPath, '/');
    $remoteRelativePath = trim(str_replace('\\', '/', $remoteRelativePath), '/');
    if (!is_dir($localSnapshotPath)) {
        return ['ok' => false, 'message' => 'local_snapshot_missing', 'path' => $localSnapshotPath];
    }

    $ready = zinesh_backup_gdrive_remote_ready();
    if (empty($ready['ok'])) {
        return ['ok' => false, 'message' => (string)($ready['message'] ?? 'remote_not_ready')];
    }

    $staging = sys_get_temp_dir() . '/zinesh-gdrive-upload-' . bin2hex(random_bytes(4));
    $copyLocal = zinesh_backup_copy_tree($localSnapshotPath, $staging);
    if (empty($copyLocal['ok'])) {
        return ['ok' => false, 'message' => 'staging_copy_failed'];
    }
    $manifestPath = zinesh_backup_write_manifest_json($staging);
    $manifestSize = (int)@filesize($manifestPath);
    $localManifest = zinesh_backup_build_sha256_manifest($staging);

    $remote = zinesh_backup_gdrive_remote();
    $root = zinesh_backup_gdrive_root();
    $remoteDest = $remote . ':' . $root . '/' . $remoteRelativePath;

    $copy = zinesh_backup_gdrive_rclone([
        'copy',
        $staging,
        $remoteDest,
        '--create-empty-src-dirs',
        '-v',
    ]);
    zinesh_backup_remove_tree($staging);

    if (empty($copy['ok'])) {
        return [
            'ok' => false,
            'message' => 'rclone_copy_failed',
            'stderr' => $copy['stderr'],
            'remote_dest' => $remoteDest,
        ];
    }

    $ls = zinesh_backup_gdrive_rclone(['lsjson', $remoteDest, '--recursive']);
    if (empty($ls['ok'])) {
        return ['ok' => false, 'message' => 'rclone_lsjson_failed', 'remote_dest' => $remoteDest];
    }
    $remoteFiles = json_decode($ls['stdout'], true);
    if (!is_array($remoteFiles)) {
        return ['ok' => false, 'message' => 'remote_list_invalid', 'remote_dest' => $remoteDest];
    }

    $remoteCount = 0;
    $remoteBytes = 0;
    foreach ($remoteFiles as $row) {
        if (!is_array($row) || !empty($row['IsDir'])) {
            continue;
        }
        $remoteCount++;
        $remoteBytes += (int)($row['Size'] ?? 0);
    }

    $expectedCount = $localManifest['file_count'] + 1; // + manifest.json
    $expectedBytes = $localManifest['total_bytes'] + $manifestSize;

    $countOk = $remoteCount === $expectedCount;
    $bytesOk = $remoteBytes === $expectedBytes;

    $result = [
        'ok' => $countOk && $bytesOk,
        'message' => ($countOk && $bytesOk) ? 'upload_verified' : 'upload_count_or_size_mismatch',
        'remote_dest' => $remoteDest,
        'googleDrivePath' => $root . '/' . $remoteRelativePath,
        'local_snapshot' => basename($localSnapshotPath),
        'local_file_count' => $expectedCount,
        'remote_file_count' => $remoteCount,
        'local_bytes' => $expectedBytes,
        'remote_bytes' => $remoteBytes,
        'manifest' => $localManifest,
    ];

    if ($updateHealth) {
        if (!empty($result['ok'])) {
            zinesh_backup_gdrive_update_health_success($result);
        } else {
            zinesh_backup_gdrive_update_health_failure($result, (string)$result['message']);
        }
    }

    return $result;
}

/**
 * @return array<string,mixed>
 */
function zinesh_backup_gdrive_restore_verify(string $localSnapshotPath, string $remoteRelativePath, string $restoreDir): array
{
    $localSnapshotPath = rtrim($localSnapshotPath, '/');
    $restoreDir = rtrim($restoreDir, '/');
    $remoteRelativePath = trim(str_replace('\\', '/', $remoteRelativePath), '/');

    if (is_dir($restoreDir)) {
        zinesh_backup_remove_tree($restoreDir);
    }
    mkdir($restoreDir, 0700, true);

    $remote = zinesh_backup_gdrive_remote();
    $root = zinesh_backup_gdrive_root();
    $remoteSrc = $remote . ':' . $root . '/' . $remoteRelativePath;

    $copy = zinesh_backup_gdrive_rclone([
        'copy',
        $remoteSrc,
        $restoreDir,
        '--create-empty-src-dirs',
    ]);
    if (empty($copy['ok'])) {
        return ['ok' => false, 'message' => 'restore_copy_failed', 'stderr' => $copy['stderr']];
    }

    $localManifest = zinesh_backup_build_sha256_manifest($localSnapshotPath);
    $restoreManifest = zinesh_backup_build_sha256_manifest($restoreDir);

    $errors = [];
    if ($localManifest['file_count'] !== $restoreManifest['file_count']) {
        $errors[] = 'file_count_mismatch';
    }
    if ($localManifest['total_bytes'] !== $restoreManifest['total_bytes']) {
        $errors[] = 'total_bytes_mismatch';
    }
    foreach ($localManifest['files'] as $i => $entry) {
        $rel = $entry['relative_path'];
        $other = $restoreManifest['files'][$i] ?? null;
        if (!is_array($other) || $other['relative_path'] !== $rel) {
            $errors[] = 'path_mismatch:' . $rel;
            continue;
        }
        if ((int)$entry['size'] !== (int)$other['size']) {
            $errors[] = 'size_mismatch:' . $rel;
        }
        if ((string)$entry['sha256'] !== (string)$other['sha256']) {
            $errors[] = 'sha256_mismatch:' . $rel;
        }
    }

    $ok = $errors === [];
    if ($ok) {
        zinesh_backup_gdrive_update_health_restore_ok();
    }

    return [
        'ok' => $ok,
        'message' => $ok ? 'restore_verified' : 'restore_verification_failed',
        'errors' => $errors,
        'local_file_count' => $localManifest['file_count'],
        'restore_file_count' => $restoreManifest['file_count'],
        'local_bytes' => $localManifest['total_bytes'],
        'restore_bytes' => $restoreManifest['total_bytes'],
    ];
}

/** @param array<string,mixed> $uploadResult */
function zinesh_backup_gdrive_update_health_success(array $uploadResult): void
{
    $existing = [];
    $path = zinesh_data_path('backup_health.json');
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }

    zinesh_json_write('backup_health.json', array_merge($existing, [
        'localBackupStatus' => (string)($existing['localBackupStatus'] ?? $existing['status'] ?? 'ok'),
        'lastGoogleDriveBackup' => date('Y-m-d H:i'),
        'lastGoogleDriveSnapshot' => basename((string)($uploadResult['local_snapshot'] ?? '')),
        'googleDriveStatus' => 'ok',
        'googleDrivePath' => (string)($uploadResult['googleDrivePath'] ?? ''),
        'googleDriveFileCount' => (int)($uploadResult['remote_file_count'] ?? 0),
        'googleDriveSizeBytes' => (int)($uploadResult['remote_bytes'] ?? 0),
        'googleDriveVerification' => 'ok',
        'googleDriveLastError' => '',
        'gdrive' => true,
        'gdriveConnected' => true,
        'localBackupOnly' => false,
    ]));
}

/** @param array<string,mixed> $context */
function zinesh_backup_gdrive_update_health_failure(array $context, string $error): void
{
    $existing = [];
    $path = zinesh_data_path('backup_health.json');
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }

    zinesh_json_write('backup_health.json', array_merge($existing, [
        'localBackupStatus' => (string)($existing['localBackupStatus'] ?? $existing['status'] ?? 'ok'),
        'googleDriveStatus' => 'failed',
        'googleDriveVerification' => 'failed',
        'googleDriveLastError' => $error,
        'googleDrivePath' => (string)($context['googleDrivePath'] ?? $context['remote_dest'] ?? ''),
        'gdriveConnected' => false,
    ]));
}

function zinesh_backup_gdrive_update_health_restore_ok(): void
{
    $existing = [];
    $path = zinesh_data_path('backup_health.json');
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    zinesh_json_write('backup_health.json', array_merge($existing, [
        'restoreTestStatus' => 'ok',
        'googleDriveVerification' => 'restore_ok',
    ]));
}

/**
 * @return array{ok:bool, fp?:resource, path?:string, message?:string}
 */
function zinesh_backup_acquire_lock()
{
    $src = zinesh_resolve_data_dir();
    $backupRoot = rtrim($src, '/') . '/backups';
    if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true) && !is_dir($backupRoot)) {
        return ['ok' => false, 'message' => 'backup_root_mkdir_failed'];
    }
    $lockPath = $backupRoot . '/.backup.lock';
    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return ['ok' => false, 'message' => 'lock_open_failed'];
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return ['ok' => false, 'message' => 'backup_locked'];
    }
    ftruncate($fp, 0);
    fwrite($fp, (string)getmypid() . ' ' . date('c') . PHP_EOL);
    fflush($fp);
    return ['ok' => true, 'fp' => $fp, 'path' => $lockPath];
}

/** @param resource $fp */
function zinesh_backup_release_lock($fp): void
{
    if (!is_resource($fp)) {
        return;
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}

/** @param array<string,mixed> $context */
function zinesh_backup_update_health_local_failure(string $message, array $context = []): void
{
    $existing = [];
    $path = zinesh_data_path('backup_health.json');
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }

    zinesh_json_write('backup_health.json', array_merge($existing, [
        'status' => 'failed',
        'localBackupStatus' => 'failed',
        'lastBackupError' => $message,
        'lastBackup' => date('Y-m-d H:i'),
        'lastPath' => (string)($context['path'] ?? $existing['lastPath'] ?? ''),
    ]));
}

/**
 * Local snapshot → verify → optional Google Drive hourly upload.
 *
 * @return array<string,mixed>
 */
function zinesh_backup_run_pipeline(int $retentionKeep = 30): array
{
    $lock = zinesh_backup_acquire_lock();
    if (empty($lock['ok'])) {
        return [
            'ok' => false,
            'message' => (string)($lock['message'] ?? 'backup_locked'),
            'locked' => true,
        ];
    }

    /** @var resource $lockFp */
    $lockFp = $lock['fp'];

    try {
        $local = zinesh_backup_run($retentionKeep);
        if (empty($local['ok'])) {
            zinesh_backup_update_health_local_failure(
                (string)($local['message'] ?? 'local_backup_failed'),
                $local
            );
            return [
                'ok' => false,
                'message' => (string)($local['message'] ?? 'local_backup_failed'),
                'local' => $local,
                'gdrive' => ['ok' => false, 'skipped' => true, 'message' => 'local_backup_failed'],
            ];
        }

        $snapshotBasename = basename((string)($local['path'] ?? ''));
        $remoteRel = zinesh_backup_gdrive_hourly_prefix() . '/' . $snapshotBasename;

        if (zinesh_backup_gdrive_should_skip_upload($snapshotBasename, $remoteRel)) {
            return [
                'ok' => true,
                'message' => 'pipeline_ok_gdrive_skipped_duplicate',
                'local' => $local,
                'gdrive' => [
                    'ok' => true,
                    'skipped' => true,
                    'message' => 'already_uploaded',
                    'googleDrivePath' => zinesh_backup_gdrive_root() . '/' . $remoteRel,
                ],
            ];
        }

        $gdrive = zinesh_backup_gdrive_upload_snapshot((string)$local['path'], $remoteRel);
        return [
            'ok' => true,
            'message' => !empty($gdrive['ok']) ? 'pipeline_ok' : 'pipeline_local_ok_gdrive_failed',
            'local' => $local,
            'gdrive' => $gdrive,
        ];
    } finally {
        zinesh_backup_release_lock($lockFp);
    }
}
