#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/wallet_lib.php';
require_once dirname(__DIR__) . '/founder_health_lib.php';

zinesh_ensure_core_data_files();

$files = zinesh_founder_health_data_files();
$created = [];
foreach ($files as $file) {
    $path = zinesh_data_path($file);
    if (file_exists($path)) {
        echo "OK {$file}\n";
    } else {
        $created[] = $file;
    }
}

$backupPath = zinesh_data_path('backup_health.json');
echo file_exists($backupPath) ? "OK backup_health.json\n" : "MISSING backup_health.json\n";

$health = zinesh_founder_system_health(true);
$overall = $health['overall'] ?? [];
echo 'overall=' . ($overall['statusLabel'] ?? '?') . ' '
    . ($overall['passedChecks'] ?? '?') . '/' . ($overall['totalChecks'] ?? '?') . "\n";

exit(empty($created) ? 0 : 1);
