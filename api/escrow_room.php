<?php
declare(strict_types=1);

require_once __DIR__ . '/wallet_lib.php';
require_once __DIR__ . '/tl_mode_lib.php';
require_once __DIR__ . '/escrow_room_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

if (!zinesh_tl_mode_enabled()) {
    zinesh_json_response(['message' => 'Emanet odası yalnızca TL modunda kullanılabilir.', 'tlMode' => false], 403);
}

$input = zinesh_input();
$action = (string)($input['action'] ?? '');
$user = zinesh_require_auth($input);
$uid = (string)($user['uid'] ?? '');

if ($action === 'list') {
    zinesh_json_response([
        'ok' => true,
        'rooms' => zinesh_escrow_rooms_for_user($uid),
    ]);
}

if ($action === 'connect') {
    $peerTicket = zinesh_normalize_member_ticket((string)($input['peerTicket'] ?? $input['ticketNumber'] ?? ''));
    $myRole = trim((string)($input['myRole'] ?? $input['role'] ?? ''));
    $result = zinesh_escrow_room_connect($user, $peerTicket, $myRole);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Bağlantı kurulamadı.'], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'room' => $result['room'],
        'message' => $result['message'] ?? null,
    ]);
}

if ($action === 'detail') {
    $roomId = trim((string)($input['roomId'] ?? ''));
    $room = zinesh_escrow_room_find($roomId);
    if (!$room || !zinesh_escrow_room_is_participant($room, $uid)) {
        zinesh_json_response(['message' => 'Oda bulunamadı.'], 404);
    }
    zinesh_json_response([
        'ok' => true,
        'room' => zinesh_escrow_room_public($room, $uid),
        'messages' => zinesh_escrow_room_messages($roomId),
    ]);
}

if ($action === 'send_message') {
    $roomId = trim((string)($input['roomId'] ?? ''));
    $body = trim((string)($input['body'] ?? $input['message'] ?? ''));
    $result = zinesh_escrow_room_send_message($user, $roomId, $body);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Mesaj gönderilemedi.'], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'room' => $result['room'],
        'messages' => $result['messages'] ?? zinesh_escrow_room_messages($roomId),
    ]);
}

if ($action === 'propose_terms') {
    $roomId = trim((string)($input['roomId'] ?? ''));
    $amountTry = (float)($input['amountTry'] ?? $input['amount'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $requestCollateral = !empty($input['requestCollateral']);
    $result = zinesh_escrow_room_propose_terms($user, $roomId, $amountTry, $title, $description, $requestCollateral);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Teklif kaydedilemedi.'], 400);
    }
    zinesh_json_response(['ok' => true, 'room' => $result['room']]);
}

if ($action === 'accept_terms') {
    $roomId = trim((string)($input['roomId'] ?? ''));
    $workerRequestsCollateral = !empty($input['workerRequestsCollateral']);
    $result = zinesh_escrow_room_accept_terms($user, $roomId, $workerRequestsCollateral);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Anlaşma kilitlenemedi.'], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'room' => $result['room'],
        'wallet' => $result['wallet'] ?? null,
        'message' => $result['message'] ?? null,
    ]);
}

if ($action === 'confirm_complete') {
    $roomId = trim((string)($input['roomId'] ?? ''));
    $result = zinesh_escrow_room_confirm_complete($user, $roomId);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Onay alınamadı.'], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'room' => $result['room'],
        'wallet' => $result['wallet'] ?? null,
        'message' => $result['message'] ?? null,
    ]);
}

if ($action === 'file_dispute') {
    $roomId = trim((string)($input['roomId'] ?? ''));
    $reason = trim((string)($input['reason'] ?? ''));
    $evidence = trim((string)($input['evidence'] ?? ''));
    if ($reason === '') {
        zinesh_json_response(['message' => 'Şikayet gerekçesi zorunlu.'], 400);
    }
    $result = zinesh_escrow_room_file_dispute($user, $roomId, $reason, $evidence);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'Şikayet açılamadı.'], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'room' => $result['room'],
        'message' => $result['message'] ?? null,
    ]);
}

if ($action === 'request_cancel') {
    $roomId = trim((string)($input['roomId'] ?? ''));
    $result = zinesh_escrow_room_request_cancel($user, $roomId);
    if (!$result['ok']) {
        zinesh_json_response(['message' => $result['message'] ?? 'İptal talebi alınamadı.'], 400);
    }
    zinesh_json_response([
        'ok' => true,
        'room' => $result['room'],
        'wallet' => $result['wallet'] ?? null,
        'message' => $result['message'] ?? null,
    ]);
}

zinesh_json_response(['message' => 'unknown_action'], 400);
