<?php
declare(strict_types=1);

/**
 * Verified local snapshot backup for zinesh_resolve_data_dir().
 * Copy-only — never mutates source files.
 */

/** @return list<string> */
function zinesh_backup_skip_relative_paths(): array
{
    return ['backups'];
}

/**
 * Atomik yazımın yan ürünleri manifest dışında tutulur.
 * <dosya>.tmp<pid>.<uniqid> rename ile kaybolur; manifest'e girip kopyalamaya sıra
 * gelmeden yok olursa tüm yedek copy_failed ile düşerdi. <dosya>.lock ise kalıcı bir
 * kilit tutamağıdır, snapshot'a taşınacak bir veri taşımaz.
 */
function zinesh_backup_is_atomic_artifact(string $name): bool
{
    return str_contains($name, '.tmp') || str_ends_with($name, '.lock');
}

/**
 * @return array<string, array{size:int, hash:string}>
 */
function zinesh_backup_manifest(string $root, ?array $skipPrefixes = null): array
{
    $skipPrefixes = $skipPrefixes ?? zinesh_backup_skip_relative_paths();
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $manifest = [];
    if (!is_dir($root)) {
        return $manifest;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isFile()) {
            continue;
        }
        if (zinesh_backup_is_atomic_artifact($item->getFilename())) {
            continue;
        }
        $full = str_replace('\\', '/', $item->getPathname());
        $rel = ltrim(substr($full, strlen($root)), '/');
        $skip = false;
        foreach ($skipPrefixes as $prefix) {
            $prefix = trim((string)$prefix, '/');
            if ($prefix === '') {
                continue;
            }
            if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }
        $manifest[$rel] = [
            'size' => (int)$item->getSize(),
            'hash' => (string)@md5_file($full),
        ];
    }

    ksort($manifest);
    return $manifest;
}

/** @return list<string> */
function zinesh_backup_top_level_subdirs_from_manifest(array $manifest): array
{
    $dirs = [];
    foreach (array_keys($manifest) as $rel) {
        if (!str_contains((string)$rel, '/')) {
            continue;
        }
        $dirs[explode('/', (string)$rel, 2)[0]] = true;
    }
    $out = array_keys($dirs);
    sort($out);
    return $out;
}

function zinesh_backup_count_root_json(array $manifest): int
{
    $count = 0;
    foreach (array_keys($manifest) as $rel) {
        if (!str_contains((string)$rel, '/') && str_ends_with((string)$rel, '.json')) {
            $count++;
        }
    }
    return $count;
}

function zinesh_backup_remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * @return array{ok:bool, copied:int, message?:string}
 */
function zinesh_backup_copy_tree(string $srcRoot, string $dstRoot, ?array $skipPrefixes = null): array
{
    $manifest = zinesh_backup_manifest($srcRoot, $skipPrefixes);
    $srcRoot = rtrim(str_replace('\\', '/', $srcRoot), '/');
    $dstRoot = rtrim(str_replace('\\', '/', $dstRoot), '/');
    $copied = 0;

    foreach ($manifest as $rel => $_meta) {
        $srcFile = $srcRoot . '/' . $rel;
        $dstFile = $dstRoot . '/' . $rel;
        $dstDir = dirname($dstFile);
        if (!is_dir($dstDir) && !mkdir($dstDir, 0700, true) && !is_dir($dstDir)) {
            return ['ok' => false, 'copied' => $copied, 'message' => "mkdir_failed:{$rel}"];
        }
        if (!is_file($srcFile) || !@copy($srcFile, $dstFile)) {
            return ['ok' => false, 'copied' => $copied, 'message' => "copy_failed:{$rel}"];
        }
        $copied++;
    }

    return ['ok' => true, 'copied' => $copied];
}

/**
 * @return array{
 *   ok:bool,
 *   errors:list<string>,
 *   source_files:int,
 *   backup_files:int,
 *   source_bytes:int,
 *   backup_bytes:int,
 *   source_subdirs:list<string>,
 *   backup_subdirs:list<string>
 * }
 */
function zinesh_backup_verify_manifests(array $srcManifest, array $dstManifest): array
{
    $errors = [];
    $srcBytes = array_sum(array_map(static fn(array $m): int => (int)$m['size'], $srcManifest));
    $dstBytes = array_sum(array_map(static fn(array $m): int => (int)$m['size'], $dstManifest));

    if (count($srcManifest) !== count($dstManifest)) {
        $errors[] = 'file_count_mismatch';
    }
    if ($srcBytes !== $dstBytes) {
        $errors[] = 'total_size_mismatch';
    }

    foreach ($srcManifest as $rel => $meta) {
        if (!isset($dstManifest[$rel])) {
            $errors[] = 'missing:' . $rel;
            continue;
        }
        $dst = $dstManifest[$rel];
        if ((int)$meta['size'] !== (int)$dst['size']) {
            $errors[] = 'size:' . $rel;
        }
        if ((string)$meta['hash'] !== (string)$dst['hash']) {
            $errors[] = 'hash:' . $rel;
        }
    }

    foreach (array_keys($dstManifest) as $rel) {
        if (!isset($srcManifest[$rel])) {
            $errors[] = 'extra:' . $rel;
        }
    }

    $srcSubdirs = zinesh_backup_top_level_subdirs_from_manifest($srcManifest);
    $dstSubdirs = zinesh_backup_top_level_subdirs_from_manifest($dstManifest);
    foreach ($srcSubdirs as $subdir) {
        if (!in_array($subdir, $dstSubdirs, true)) {
            $errors[] = 'missing_subdir:' . $subdir;
        }
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'source_files' => count($srcManifest),
        'backup_files' => count($dstManifest),
        'source_bytes' => $srcBytes,
        'backup_bytes' => $dstBytes,
        'source_subdirs' => $srcSubdirs,
        'backup_subdirs' => $dstSubdirs,
    ];
}

function zinesh_backup_apply_retention(string $backupRoot, int $keep = 30): int
{
    if (!is_dir($backupRoot)) {
        return 0;
    }
    $dirs = [];
    foreach (glob(rtrim($backupRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $base = basename($dir);
        if ($base === '' || str_starts_with($base, '.')) {
            continue;
        }
        $dirs[] = $dir;
    }
    rsort($dirs);
    $removed = 0;
    foreach (array_slice($dirs, $keep) as $old) {
        zinesh_backup_remove_tree($old);
        $removed++;
    }
    return $removed;
}

/** @param array<string,mixed> $result */
function zinesh_backup_update_health(array $result): void
{
    $existing = [];
    $path = zinesh_data_path('backup_health.json');
    if (is_readable($path)) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }

    $verification = is_array($result['verification'] ?? null) ? $result['verification'] : [];

    zinesh_json_write('backup_health.json', array_merge($existing, [
        'source' => 'Sunucu yerel yedek',
        'mode' => 'local',
        'localBackupOnly' => (bool)($existing['localBackupOnly'] ?? true),
        'gdrive' => (bool)($existing['gdrive'] ?? false),
        'gdriveConnected' => (bool)($existing['gdriveConnected'] ?? false),
        'lastBackup' => date('Y-m-d H:i'),
        'status' => 'ok',
        'localBackupStatus' => 'ok',
        'lastPath' => (string)($result['path'] ?? ''),
        'backupFileCount' => (int)($verification['backup_files'] ?? 0),
        'backupSizeBytes' => (int)($verification['backup_bytes'] ?? 0),
        'sourceFileCount' => (int)($verification['source_files'] ?? 0),
        'sourceSizeBytes' => (int)($verification['source_bytes'] ?? 0),
        'rootJsonCount' => (int)($result['root_json_count'] ?? 0),
        'subdirs' => $verification['backup_subdirs'] ?? [],
        'note' => 'Doğrulanmış yerel snapshot',
    ]));
}

/**
 * @return array<string,mixed>
 */
function zinesh_backup_run(int $retentionKeep = 30): array
{
    $src = zinesh_resolve_data_dir();
    $backupRoot = rtrim($src, '/') . '/backups';
    if (!is_dir($backupRoot) && !mkdir($backupRoot, 0700, true) && !is_dir($backupRoot)) {
        return ['ok' => false, 'message' => 'backup_root_mkdir_failed', 'source' => $src];
    }

    $stamp = date('Y-m-d_H-i');
    $staging = $backupRoot . '/.staging_' . $stamp . '_' . bin2hex(random_bytes(4));
    $final = $backupRoot . '/' . $stamp;

    if (!mkdir($staging, 0700, true) && !is_dir($staging)) {
        return ['ok' => false, 'message' => 'staging_mkdir_failed', 'source' => $src];
    }

    $srcManifest = zinesh_backup_manifest($src);
    $copy = zinesh_backup_copy_tree($src, $staging);
    if (empty($copy['ok'])) {
        zinesh_backup_remove_tree($staging);
        return [
            'ok' => false,
            'message' => (string)($copy['message'] ?? 'copy_failed'),
            'source' => $src,
            'copied' => (int)($copy['copied'] ?? 0),
            'expected' => count($srcManifest),
        ];
    }

    $dstManifest = zinesh_backup_manifest($staging, []);
    $verification = zinesh_backup_verify_manifests($srcManifest, $dstManifest);
    if (empty($verification['ok'])) {
        zinesh_backup_remove_tree($staging);
        return [
            'ok' => false,
            'message' => 'verification_failed',
            'source' => $src,
            'verification' => $verification,
        ];
    }

    if (is_dir($final)) {
        $final = $backupRoot . '/' . $stamp . '_' . bin2hex(random_bytes(2));
    }
    if (!@rename($staging, $final)) {
        zinesh_backup_remove_tree($staging);
        return ['ok' => false, 'message' => 'rename_failed', 'source' => $src];
    }

    $removed = zinesh_backup_apply_retention($backupRoot, $retentionKeep);
    $rootJson = zinesh_backup_count_root_json($srcManifest);

    $result = [
        'ok' => true,
        'source' => $src,
        'path' => $final,
        'copied' => (int)$copy['copied'],
        'root_json_count' => $rootJson,
        'verification' => $verification,
        'retention_removed' => $removed,
    ];

    zinesh_backup_update_health($result);
    return $result;
}
