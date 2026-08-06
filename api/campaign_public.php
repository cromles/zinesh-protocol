<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/campaign_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

zinesh_json_response([
    'ok' => true,
    'campaign' => zinesh_campaign_public_status(),
    'earlyAccess' => zinesh_early_access_public(),
]);
