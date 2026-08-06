<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/password_reset_lib.php';
require_once __DIR__ . '/escrow_jobs_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = (string)($_GET['action'] ?? 'public');
    if ($action === 'public') {
        $uid = trim((string)($_GET['uid'] ?? ''));
        $referralCode = strtoupper(trim((string)($_GET['referralCode'] ?? $_GET['ref'] ?? '')));
        if ($uid === '' && $referralCode !== '') {
            foreach (zinesh_load_users() as $u) {
                if (strtoupper((string)($u['referralCode'] ?? '')) === $referralCode) {
                    $uid = (string)($u['uid'] ?? '');
                    break;
                }
            }
        }
        if ($uid === '') {
            zinesh_json_response(['message' => 'Profil bulunamadı.'], 404);
        }
        $profile = zinesh_public_trust_profile($uid);
        if ($profile === null) {
            zinesh_json_response(['message' => 'Profil bulunamadı.'], 404);
        }
        zinesh_json_response(['ok' => true, 'profile' => $profile]);
    }
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

$input = zinesh_input();
$action = (string)($input['action'] ?? '');

if ($action === 'mine') {
    $user = zinesh_require_auth($input);
    $uid = (string)$user['uid'];
    $profile = zinesh_public_trust_profile($uid, $uid);
    if ($profile === null) {
        zinesh_json_response(['message' => 'Profil bulunamadı.'], 404);
    }
    zinesh_json_response(['ok' => true, 'profile' => $profile]);
}

if ($action === 'set_job_history_visibility') {
    $user = zinesh_require_auth($input);
    $uid = (string)$user['uid'];
    if (!array_key_exists('public', $input) && !array_key_exists('jobHistoryPublic', $input)) {
        zinesh_json_response(['message' => 'Görünürlük tercihi gerekli.'], 400);
    }
    $isPublic = !empty($input['public']) || !empty($input['jobHistoryPublic']);
    $user = zinesh_update_user($uid, static function (array &$u) use ($isPublic) {
        zinesh_ensure_profile_fields($u);
        $u['jobHistoryPublic'] = $isPublic;
    });
    zinesh_json_response([
        'ok' => true,
        'jobHistoryPublic' => !empty($user['jobHistoryPublic']),
        'user' => zinesh_public_user($user),
        'message' => $isPublic
            ? 'İş geçmişin herkese açık. Güven puanın her zaman görünür kalır.'
            : 'İş geçmişin gizlendi. Yalnızca güven puanın görünür.',
    ]);
}

zinesh_json_response(['message' => 'unknown_action'], 400);
