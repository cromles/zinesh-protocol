<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/security_lib.php';
require_once __DIR__ . '/notifications_lib.php';
require_once __DIR__ . '/session_auth_lib.php';

zinesh_cors();
zinesh_security_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    zinesh_json_response(['message' => 'method_not_allowed'], 405);
}

@set_time_limit(90);
@ini_set('memory_limit', '256M');

try {
    $input = zinesh_input();
    $action = (string)($input['action'] ?? '');
    $limits = zinesh_config()['rate_limits'] ?? [];

    $mutating = ['mark_read', 'mark_all_read', 'delete', 'clear_all'];
    if (in_array($action, $mutating, true)) {
        zinesh_rate_limit('notifications_write', (int)($limits['notifications_write'] ?? 40));
    } elseif ($action !== '') {
        zinesh_rate_limit('notifications_read', (int)($limits['notifications_read'] ?? 120));
    }

    $user = zinesh_session_auth_resolve_user($input);
    if (!$user) {
        zinesh_json_response([
            'message' => 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.',
            'code' => 'session_expired',
        ], 401);
    }
    $uid = (string)$user['uid'];
    $days = max(1, min(90, (int)($input['days'] ?? ZINESH_NOTIFICATION_RETENTION_DAYS)));

    if ($action === 'list') {
        $limit = max(1, min(100, (int)($input['limit'] ?? 50)));
        $offset = max(0, (int)($input['offset'] ?? 0));
        $result = zinesh_notifications_query($uid, $days, $limit, $offset);
        zinesh_json_response([
            'ok' => true,
            'days' => $days,
            'limit' => $limit,
            'offset' => $offset,
            'total' => $result['total'],
            'unreadCount' => $result['unreadCount'],
            'notifications' => $result['notifications'],
        ]);
    }

    if ($action === 'unread_count') {
        $result = zinesh_notifications_query($uid, $days, 1);
        zinesh_json_response([
            'ok' => true,
            'days' => $days,
            'unreadCount' => $result['unreadCount'],
        ]);
    }

    if ($action === 'mark_read') {
        $id = trim((string)($input['id'] ?? ''));
        if ($id === '') {
            zinesh_json_response(['message' => 'Bildirim kimliği gerekli.'], 400);
        }
        if (!zinesh_notifications_mark_read($uid, $id)) {
            zinesh_json_response(['message' => 'Bildirim bulunamadı.'], 404);
        }
        $result = zinesh_notifications_query($uid, $days, 1);
        zinesh_json_response([
            'ok' => true,
            'unreadCount' => $result['unreadCount'],
        ]);
    }

    if ($action === 'mark_all_read') {
        zinesh_notifications_mark_all_read($uid, $days);
        zinesh_json_response([
            'ok' => true,
            'unreadCount' => 0,
        ]);
    }

    if ($action === 'delete') {
        $id = trim((string)($input['id'] ?? ''));
        if ($id === '') {
            zinesh_json_response(['message' => 'Bildirim kimliği gerekli.'], 400);
        }
        if (!zinesh_notifications_delete($uid, $id)) {
            zinesh_json_response(['message' => 'Bildirim bulunamadı.'], 404);
        }
        $result = zinesh_notifications_query($uid, $days, 1);
        zinesh_json_response([
            'ok' => true,
            'unreadCount' => $result['unreadCount'],
        ]);
    }

    if ($action === 'clear_all') {
        require_once __DIR__ . '/founder_lib.php';
        if (!zinesh_is_founder($user)) {
            zinesh_json_response(['message' => 'Yetkisiz.'], 403);
        }
        $removed = zinesh_notifications_clear_user($uid);
        zinesh_json_response([
            'ok' => true,
            'removed' => $removed,
            'unreadCount' => 0,
        ]);
    }

    zinesh_json_response(['message' => 'Geçersiz işlem.'], 400);
} catch (Throwable $e) {
    error_log('notifications.php: ' . $e->getMessage());
    zinesh_json_response([
        'message' => 'Bildirim servisi geçici olarak yanıt vermiyor. Bir dakika sonra tekrar deneyin.',
    ], 500);
}
