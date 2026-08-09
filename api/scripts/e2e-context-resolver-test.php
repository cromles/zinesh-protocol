<?php
declare(strict_types=1);

/**
 * Context Resolver Runtime v0.1 e2e (izole sim data).
 * php api/scripts/e2e-context-resolver-test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli_only');
}

$apiDir = dirname(__DIR__);
$simDir = sys_get_temp_dir() . '/zinesh_context_rt_' . getmypid();
mkdir($simDir, 0750, true);
putenv('ZINESH_SIM_DATA_DIR=' . $simDir);

require_once $apiDir . '/wallet_lib.php';
require_once $apiDir . '/escrow_memory_lib.php';
require_once $apiDir . '/escrow_room_lib.php';
require_once $apiDir . '/context_resolver_lib.php';
require_once $apiDir . '/trust_signal_runtime_lib.php';

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

/** @return bool */
function context_has_id(array $pkg, string $contextId): bool
{
    foreach ($pkg['contexts'] ?? [] as $row) {
        if (is_array($row) && ($row['context_id'] ?? '') === $contextId) {
            return true;
        }
    }
    return false;
}

/** @return array<string,mixed>|null */
function context_find(array $pkg, string $contextId): ?array
{
    foreach ($pkg['contexts'] ?? [] as $row) {
        if (is_array($row) && ($row['context_id'] ?? '') === $contextId) {
            return $row;
        }
    }
    return null;
}

/** @return bool */
function context_uses_signal(array $ctx, string $signalId): bool
{
    foreach ($ctx['signals_used'] ?? [] as $row) {
        if (is_array($row) && ($row['signal_id'] ?? '') === $signalId) {
            return true;
        }
    }
    return false;
}

/** @return bool */
function signal_has_id(array $pkg, string $signalId): bool
{
    foreach ($pkg['signals'] ?? [] as $row) {
        if (is_array($row) && ($row['signal_id'] ?? '') === $signalId) {
            return true;
        }
    }
    return false;
}

$employerUid = 'emp-ctx-1';
$workerUid = 'wrk-ctx-1';

echo "=== Boş oda → context yok ===\n";
zinesh_json_write('escrow_rooms.json', []);
$emptyPkg = zinesh_context_resolver_for_room('room-ctx-missing');
assert_test('empty room contexts count 0', count($emptyPkg['contexts'] ?? []) === 0);
assert_test('taxonomy version set', ($emptyPkg['taxonomy_version'] ?? '') === '0.1');

echo "=== İlk işlem → uygun CTX ===\n";
$firstRoom = 'room-ctx-first';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $firstRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'İlk iş',
    'createdAt' => '2026-08-05T10:00:00+03:00',
    'lockedAt' => '2026-08-05T11:00:00+03:00',
]]);
zinesh_domain_event_emit($firstRoom, 'escrow_locked', $employerUid, 'employer', [], ['idempotency_key' => $firstRoom . ':lock']);
$firstPkg = zinesh_context_resolver_for_room($firstRoom, $employerUid);
$firstCtx = context_find($firstPkg, 'CTX-ONE');
assert_test('first transaction context frame', $firstCtx !== null);
assert_test('first transaction uses SIG-BEH-001', $firstCtx !== null && context_uses_signal($firstCtx, 'SIG-BEH-001'));

echo "=== Yazılım benzeri sözleşme → CTX-SW ===\n";
$swRoom = 'room-ctx-sw';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $swRoom,
    'status' => 'terms_pending',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Freelance yazılım geliştirme API',
    'description' => str_repeat('Backend ve frontend yazılım teslimi. ', 10),
    'agreedAmountTry' => 5000.0,
    'createdAt' => '2026-08-06T09:00:00+03:00',
]]);
$roomSw = [
    'id' => $swRoom,
    'title' => 'Freelance yazılım geliştirme API',
    'description' => str_repeat('Backend ve frontend yazılım teslimi. ', 10),
    'agreedAmountTry' => 5000.0,
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
];
zinesh_escrow_memory_on_terms_version($roomSw, ['uid' => $employerUid], 'employer', 'propose');
zinesh_escrow_memory_on_changes_requested($roomSw, ['uid' => $workerUid], 'Revizyon 1');
$roomSw['agreedAmountTry'] = 5200.0;
zinesh_escrow_memory_on_terms_version($roomSw, ['uid' => $workerUid], 'worker', 'counter_offer', ['reason' => 'scope']);
zinesh_escrow_memory_on_changes_requested($roomSw, ['uid' => $employerUid], 'Revizyon 2');
$roomSw['agreedAmountTry'] = 5400.0;
zinesh_escrow_memory_on_terms_version($roomSw, ['uid' => $employerUid], 'employer', 'counter_offer', ['reason' => 'price']);
$swPkg = zinesh_context_resolver_for_room($swRoom);
$swCtx = context_find($swPkg, 'CTX-SW');
assert_test('software context detected', $swCtx !== null);
assert_test('software context uses SIG-NEG-001', $swCtx !== null && context_uses_signal($swCtx, 'SIG-NEG-001'));

echo "=== Fiziksel ürün → CTX-PHY ===\n";
$phyRoom = 'room-ctx-phy';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $phyRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Fiziksel ürün satışı',
    'description' => 'Kargo ile fiziksel ürün teslimi ve shipping adresi doğrulaması.',
    'agreedAmountTry' => 2500.0,
    'createdAt' => '2026-08-04T10:00:00+03:00',
    'lockedAt' => '2026-08-04T11:00:00+03:00',
]]);
zinesh_domain_event_emit($phyRoom, 'escrow_locked', $employerUid, 'employer', [], ['idempotency_key' => $phyRoom . ':lock']);
$phyPkg = zinesh_context_resolver_for_room($phyRoom, $employerUid);
assert_test('physical product context', context_has_id($phyPkg, 'CTX-PHY'));

echo "=== Uzun müzakere → Signal değişmez, yorum çerçevesi ===\n";
$longNegRoom = 'room-ctx-longneg';
zinesh_json_write('escrow_rooms.json', [[
    'id' => $longNegRoom,
    'status' => 'locked',
    'employerUid' => $employerUid,
    'workerUid' => $workerUid,
    'title' => 'Kurumsal yazılım projesi uzun müzakere',
    'description' => str_repeat('Sözleşme metni yazılım teslimi. ', 10),
    'createdAt' => '2026-01-01T10:00:00+03:00',
    'lockedAt' => '2026-01-20T10:00:00+03:00',
]]);
zinesh_json_write('zinesh_events.json', [
    [
        'id' => 'evt-cln-1',
        'room_id' => $longNegRoom,
        'event_type' => 'terms_proposed',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-01-01T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-cln-2',
        'room_id' => $longNegRoom,
        'event_type' => 'counter_offer_created',
        'actor_id' => $workerUid,
        'actor_role' => 'worker',
        'created_at' => '2026-01-05T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-cln-3',
        'room_id' => $longNegRoom,
        'event_type' => 'counter_offer_created',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-01-10T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-cln-4',
        'room_id' => $longNegRoom,
        'event_type' => 'counter_offer_created',
        'actor_id' => $workerUid,
        'actor_role' => 'worker',
        'created_at' => '2026-01-12T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-cln-5',
        'room_id' => $longNegRoom,
        'event_type' => 'terms_accepted',
        'actor_id' => $workerUid,
        'actor_role' => 'worker',
        'created_at' => '2026-01-15T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
    [
        'id' => 'evt-cln-6',
        'room_id' => $longNegRoom,
        'event_type' => 'escrow_locked',
        'actor_id' => $employerUid,
        'actor_role' => 'employer',
        'created_at' => '2026-01-20T10:00:00+03:00',
        'payload_json' => [],
        'metadata_json' => [],
    ],
]);
$sigBefore = zinesh_trust_signal_runtime_for_room($longNegRoom);
$ctxPkg = zinesh_context_resolver_for_room($longNegRoom);
$sigAfter = zinesh_trust_signal_runtime_for_room($longNegRoom);
assert_test('signal runtime unchanged by context', json_encode($sigBefore) === json_encode($sigAfter));
$time002Frame = null;
foreach ($ctxPkg['contexts'] ?? [] as $ctxRow) {
    if (!is_array($ctxRow)) {
        continue;
    }
    foreach ($ctxRow['signals_used'] ?? [] as $row) {
        if (is_array($row) && ($row['signal_id'] ?? '') === 'SIG-TIME-002') {
            $time002Frame = $row;
            break 2;
        }
    }
}
assert_test('long negotiation interpretation frame', $time002Frame !== null);
assert_test('long negotiation signal still emitted', signal_has_id($sigAfter, 'SIG-TIME-002'));

echo "=== Explainability zinciri ===\n";
$explainCtx = $swCtx ?? context_find($swPkg, 'CTX-SW');
$explainOk = is_array($explainCtx)
    && ($explainCtx['explainability']['chain'] ?? '') === 'context → signal → metric → event'
    && ($explainCtx['evidence_chain']['chain'] ?? '') === 'context → signal → metric → event'
    && is_array($explainCtx['metrics_used'] ?? null)
    && is_array($explainCtx['signals_used'] ?? null)
    && count($explainCtx['signals_used'] ?? []) >= 1
    && ($explainCtx['confidence'] ?? '') !== '';
assert_test('explainability chain complete', $explainOk);

echo "=== Determinism ===\n";
$det1 = zinesh_context_resolver_for_room($swRoom);
$det2 = zinesh_context_resolver_for_room($swRoom);
assert_test('determinism identical package', json_encode($det1) === json_encode($det2));

echo "=== Replayability ===\n";
$rep1 = zinesh_context_resolver_for_room($longNegRoom);
$rep2 = zinesh_context_resolver_for_room($longNegRoom);
assert_test('replay same context ids', zinesh_context_resolver_context_ids($rep1) === zinesh_context_resolver_context_ids($rep2));

echo "=== Context output schema ===\n";
$schemaOk = is_array($explainCtx)
    && isset($explainCtx['context_id'], $explainCtx['title'], $explainCtx['description'])
    && isset($explainCtx['signals_used'], $explainCtx['metrics_used'], $explainCtx['evidence_chain'])
    && isset($explainCtx['explainability'], $explainCtx['confidence'], $explainCtx['context_version']);
assert_test('required context fields present', $schemaOk);

echo "=== Catalog taxonomy unchanged (12) ===\n";
assert_test('catalog has 12 context ids', count(zinesh_context_catalog_ids()) === 12);

echo "\n=== SUMMARY ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed > 0 ? 1 : 0);
