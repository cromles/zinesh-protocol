<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/founder_profile_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

$input = [
    'sessionToken' => trim((string)($_GET['sessionToken'] ?? $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '')),
];
if ($input['sessionToken'] === '' && !empty($_COOKIE['zinesh_session'])) {
    $input['sessionToken'] = trim((string)$_COOKIE['zinesh_session']);
}

$user = zinesh_resolve_user_for_session_refresh($input);
if (!$user) {
    zinesh_json_response(['success' => false, 'message' => 'Oturum gerekli.'], 401);
}
if (!zinesh_is_founder($user)) {
    zinesh_json_response(['success' => false, 'message' => 'Yalnızca kurucu hesabı.'], 403);
}

$profile = zinesh_founder_profile($user);
zinesh_json_response(array_merge(['success' => true], $profile));
