<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/demo_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$input = zinesh_input();
$action = strtolower(trim((string)($_GET['action'] ?? $input['action'] ?? '')));

if ($action === 'status') {
    if (!zinesh_demo_mode_enabled()) {
        zinesh_json_response(['ok' => true, 'demoEnabled' => false]);
    }
    zinesh_json_response(zinesh_demo_public_status());
}

zinesh_demo_deny_if_disabled();

if ($action === 'health') {
    zinesh_json_response(zinesh_demo_health_report());
}

if ($action === 'login') {
    $role = strtolower(trim((string)($input['role'] ?? $_GET['role'] ?? '')));
    $result = zinesh_demo_login($role);
    if (empty($result['ok'])) {
        zinesh_json_response($result, 400);
    }
    zinesh_json_response($result);
}

if ($action === 'reset') {
    try {
        zinesh_demo_reset_escrow_state();
        zinesh_demo_clear_error();
        zinesh_json_response(['ok' => true, 'message' => 'Demo escrow sıfırlandı.']);
    } catch (Throwable $e) {
        zinesh_demo_record_error($e->getMessage());
        zinesh_json_response(['ok' => false, 'message' => $e->getMessage()], 500);
    }
}

zinesh_json_response(['ok' => false, 'message' => 'Geçersiz demo işlemi.'], 400);
