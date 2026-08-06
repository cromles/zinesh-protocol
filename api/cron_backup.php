<?php
declare(strict_types=1);

require_once __DIR__ . '/security_lib.php';

$key = (string)($_GET['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
$expected = zinesh_cron_secret();
if ($expected === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    exit('forbidden');
}

$src = zinesh_config()['data_dir'];
$dst = $src . '/backups/' . date('Y-m-d_H-i');
if (!is_dir($src . '/backups')) {
    mkdir($src . '/backups', 0700, true);
}
mkdir($dst, 0700, true);

$copied = 0;
foreach (glob($src . '/*.json') as $file) {
    $base = basename($file);
    if (copy($file, $dst . '/' . $base)) {
        $copied++;
    }
}

$backups = glob($src . '/backups/*', GLOB_ONLYDIR);
rsort($backups);
foreach (array_slice($backups, 30) as $old) {
    foreach (glob($old . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($old);
}

zinesh_audit('backup', ['files' => $copied, 'path' => $dst]);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'copied' => $copied, 'path' => $dst]);
