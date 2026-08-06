<?php
declare(strict_types=1);

require_once __DIR__ . '/events_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['ok' => false, 'message' => 'method_not_allowed'], 405);
}

$result = zinesh_events_handle(zinesh_input());
$code = !empty($result['ok']) ? 200 : 400;
zinesh_json_response($result, $code);
