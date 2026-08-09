<?php
declare(strict_types=1);

/**
 * AI Context endpoint smoke test (izole sim data).
 * php api/scripts/e2e-ai-context-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_ai_context_test_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/ai_context_lib.php';

zinesh_ensure_core_data_files();
zinesh_json_write('zinesh_events.json', []);
zinesh_json_write('contract_versions.json', []);
zinesh_json_write('escrow_rooms.json', []);

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

$roomId = 'room-ai-' . substr(bin2hex(random_bytes(3)), 0, 6);
$employerUid = 'emp-ai-1';
$workerUid = 'wrk-ai-1';
$outsiderUid = 'out-ai-1';

zinesh_json_write('escrow_rooms.json', [[
    'id' => $roomId,
    'status' => 'negotiating',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Test',
    'description' => str_repeat('Test sözleşme metni. ', 10),
    'agreedAmountTry' => 1000.0,
]]);

$room = [
    'id' => $roomId,
    'title' => 'Test',
    'description' => str_repeat('Test sözleşme metni. ', 10),
    'agreedAmountTry' => 1000.0,
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
];
zinesh_escrow_memory_on_terms_version($room, ['uid' => $employerUid], 'employer', 'propose');

echo "=== Response shape ===\n";
$raw = zinesh_escrow_room_ai_context($roomId);
$public = zinesh_ai_context_public_response($roomId, $raw);

assert_test('room_id present', ($public['room_id'] ?? '') === $roomId);
assert_test('contract_versions array', is_array($public['contract_versions'] ?? null));
assert_test('timeline_summary array', is_array($public['timeline_summary'] ?? null));
assert_test('behavior_stats present', is_array($public['behavior_stats'] ?? null));
assert_test('generated_at present', (string)($public['generated_at'] ?? '') !== '');
assert_test('no payload in timeline', empty($public['timeline_summary']) || !array_key_exists('payload', $public['timeline_summary'][0] ?? []));

echo "=== Sanitize ===\n";
$version = zinesh_ai_context_sanitize_contract_version([
    'id' => 'cv-1',
    'version_number' => 1,
    'created_by' => 'secret-uid',
    'created_by_role' => 'employer',
    'meta_json' => ['internal' => true],
    'title' => 'T',
    'terms_content' => 'C',
    'amount_try' => 10,
    'structured_terms_json' => ['category' => ''],
    'created_at' => date('c'),
    'accepted_at' => null,
]);
assert_test('created_by uid stripped', !isset($version['created_by']));
assert_test('meta_json stripped', !isset($version['meta_json']));
assert_test('role kept', ($version['created_by_role'] ?? '') === 'employer');

echo "=== Access control ===\n";
$roomSnap = zinesh_ai_context_room_snapshot($roomId);
assert_test('participant employer', zinesh_escrow_room_is_participant($roomSnap ?? [], $employerUid));
assert_test('outsider blocked', !zinesh_escrow_room_is_participant($roomSnap ?? [], $outsiderUid));

echo "=== Old room (no events) ===\n";
$emptyId = 'room-empty-1';
zinesh_json_write('escrow_rooms.json', array_merge(
    zinesh_json_read('escrow_rooms.json'),
    [[
        'id' => $emptyId,
        'status' => 'completed',
        'employerUid' => $employerUid,
        'workerUid' => $workerUid,
    ]]
));
$emptyCtx = zinesh_ai_context_public_response($emptyId, zinesh_escrow_room_ai_context($emptyId));
assert_test('old room empty versions ok', is_array($emptyCtx['contract_versions']) && $emptyCtx['contract_versions'] === []);
assert_test('old room null accepted ok', $emptyCtx['accepted_contract_version'] === null);

echo "\n=== Sonuç: {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
