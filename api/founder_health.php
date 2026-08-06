<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/founder_lib.php';
require_once __DIR__ . '/founder_health_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

$input = zinesh_founder_health_auth_input();

$user = null;
try {
    $user = zinesh_founder_health_resolve_user($input);
} catch (Throwable) {
    $user = null;
}
if (!$user) {
    zinesh_json_response(['success' => false, 'message' => 'Oturum gerekli.'], 401);
}
if (!zinesh_is_founder($user)) {
    zinesh_json_response(['success' => false, 'message' => 'Yalnızca kurucu hesabı.'], 403);
}

try {
    $health = zinesh_founder_system_health(true);
    zinesh_json_response([
        'success' => true,
        'health' => $health,
    ]);
} catch (Throwable $e) {
    zinesh_json_response([
        'success' => false,
        'message' => 'Sistem sağlığı okunamadı.',
        'health' => [
            'updatedAt' => date('c'),
            'files' => [],
            'backup' => [
                'display' => 'Görüntülenemiyor',
                'source' => 'Google Drive backups',
                'unavailable' => true,
            ],
            'disasterRecovery' => [
                'provider' => 'Google Drive',
                'connected' => false,
                'label' => 'Bağlı değil',
            ],
            'server' => [
                'status' => 'green',
                'label' => 'Çalışıyor',
            ],
            'error' => $e->getMessage(),
        ],
    ], 200);
}
