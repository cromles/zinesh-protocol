<?php
declare(strict_types=1);

/**
 * Architecture Validation Suite v1.0 e2e (izole sim data).
 * php api/scripts/e2e-architecture-validation-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$repoRoot = dirname($apiDir);
$simDir = sys_get_temp_dir() . '/zinesh_arch_val_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/architecture_validation_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('zinesh_events.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('escrow_rooms.json', []);

$employerUid = 'emp-arch-1';
$workerUid = 'wrk-arch-1';
$roomId = 'room-arch-val';

zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Kurumsal yazılım architecture validation',
    'description' => str_repeat('Sözleşme metni yazılım teslimi. ', 10),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    ['id' => 'evt-av-1', 'room_id' => $roomId, 'event_type' => 'terms_proposed', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-01T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-av-2', 'room_id' => $roomId, 'event_type' => 'counter_offer_created', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-05T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-av-3', 'room_id' => $roomId, 'event_type' => 'counter_offer_created', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-10T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-av-4', 'room_id' => $roomId, 'event_type' => 'terms_accepted', 'actor_id' => $workerUid, 'actor_role' => 'worker', 'created_at' => '2026-01-15T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
    ['id' => 'evt-av-5', 'room_id' => $roomId, 'event_type' => 'escrow_locked', 'actor_id' => $employerUid, 'actor_role' => 'employer', 'created_at' => '2026-01-20T10:00:00+03:00', 'payload_json' => [], 'metadata_json' => []],
]);

echo "=== Architecture Validation Suite v1.0 ===\n";

$fullReport = zinesh_architecture_validation_run([
    'api_dir' => $apiDir,
    'repo_root' => $repoRoot,
    'room_id' => $roomId,
    'actor_id' => $employerUid,
]);

foreach ($fullReport['checks'] ?? [] as $check) {
    $status = ($check['passed'] ?? false) ? 'PASS' : 'FAIL';
    $id = (string)($check['id'] ?? '');
    $msg = (string)($check['message'] ?? '');
    echo "  [{$status}] {$id}: {$msg}\n";
}

if (($fullReport['findings'] ?? []) !== []) {
    echo "\n=== Known Findings (documented) ===\n";
    foreach ($fullReport['findings'] as $finding) {
        echo '  - ' . ($finding['class'] ?? '') . ': ' . ($finding['message'] ?? '') . "\n";
    }
}

$failed = (int)($fullReport['summary']['failed_count'] ?? 0);
$total = (int)($fullReport['summary']['total_checks'] ?? 0);
$passed = (int)($fullReport['summary']['passed_count'] ?? 0);

echo "\n=== SUMMARY ===\n";
echo "Validation suite version: " . ($fullReport['validation_suite_version'] ?? '') . "\n";
echo "Total checks: {$total}\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Overall: " . (($fullReport['passed'] ?? false) ? 'PASS' : 'FAIL') . "\n";

exit($failed > 0 ? 1 : 0);
