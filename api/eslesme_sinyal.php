<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/session_auth_lib.php';
require_once __DIR__ . '/eslesme_sinyal_lib.php';
require_once __DIR__ . '/escrow_room_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

$input = zinesh_input();
$action = (string)($input['action'] ?? '');

$user = zinesh_session_auth_resolve_user($input);
if (!$user) {
    zinesh_json_response([
        'message' => 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.',
        'code' => 'session_expired',
    ], 401);
}
$uid = (string)($user['uid'] ?? '');

if ($action === 'list') {
    $eslesmeId = trim((string)($input['eslesme_id'] ?? $input['roomId'] ?? ''));
    if ($eslesmeId !== '') {
        $room = zinesh_escrow_room_find($eslesmeId);
        if (!$room || !zinesh_escrow_room_is_participant($room, $uid)) {
            zinesh_json_response(['message' => 'Eşleşme bulunamadı.'], 404);
        }
    }
    $sinyaller = zinesh_eslesme_sinyaller_for_user($uid, $eslesmeId !== '' ? $eslesmeId : null);
    // Yalnızca kullanıcının katılımcı olduğu odaların sinyalleri
    $filtered = [];
    foreach ($sinyaller as $s) {
        $rid = (string)($s['eslesme_id'] ?? '');
        $room = zinesh_escrow_room_find($rid);
        if (!$room || !zinesh_escrow_room_is_participant($room, $uid)) {
            continue;
        }
        $filtered[] = $s;
    }
    $total = count($filtered);
    $limit = max(1, min(100, (int)($input['limit'] ?? 10)));
    $offset = max(0, (int)($input['offset'] ?? 0));
    $page = array_slice($filtered, $offset, $limit);
    zinesh_json_response([
        'ok' => true,
        'sinyaller' => $page,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'okunmamis' => zinesh_eslesme_sinyal_okunmamis_sayisi($uid, $eslesmeId !== '' ? $eslesmeId : null),
    ]);
}

if ($action === 'okunmamis_sayisi') {
    $eslesmeId = trim((string)($input['eslesme_id'] ?? $input['roomId'] ?? ''));
    zinesh_json_response([
        'ok' => true,
        'okunmamis' => zinesh_eslesme_sinyal_okunmamis_sayisi($uid, $eslesmeId !== '' ? $eslesmeId : null),
    ]);
}

if ($action === 'okundu') {
    $tip = trim((string)($input['tip'] ?? ''));
    $eslesmeId = trim((string)($input['eslesme_id'] ?? ''));
    $timestamp = trim((string)($input['timestamp'] ?? ''));
    if ($tip === '' || $eslesmeId === '' || $timestamp === '') {
        zinesh_json_response(['message' => 'tip, eslesme_id ve timestamp gerekli.'], 400);
    }
    $room = zinesh_escrow_room_find($eslesmeId);
    if (!$room || !zinesh_escrow_room_is_participant($room, $uid)) {
        zinesh_json_response(['message' => 'Eşleşme bulunamadı.'], 404);
    }
    if (!zinesh_eslesme_sinyal_okundu_isaretle($uid, $tip, $eslesmeId, $timestamp)) {
        zinesh_json_response(['message' => 'Sinyal bulunamadı.'], 404);
    }
    zinesh_json_response([
        'ok' => true,
        'okunmamis' => zinesh_eslesme_sinyal_okunmamis_sayisi($uid),
    ]);
}

if ($action === 'tumunu_okundu') {
    zinesh_eslesme_sinyal_tumunu_okundu($uid);
    zinesh_json_response([
        'ok' => true,
        'okunmamis' => zinesh_eslesme_sinyal_okunmamis_sayisi($uid),
    ]);
}

zinesh_json_response(['message' => 'Geçersiz action.'], 400);
