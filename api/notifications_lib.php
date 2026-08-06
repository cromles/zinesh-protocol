<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

const ZINESH_NOTIFICATION_TYPES = [
    'KYC_APPROVED',
    'PASSWORD_CHANGED',
    'PASSWORD_RESET',
    'REFERRAL_JOINED',
    'REFERRAL_COMPLETED',
    'WITHDRAW_APPROVED',
    'WITHDRAW_REJECTED',
    'CAMPAIGN_REWARD',
    'RESEARCH_UNLOCK',
    'SYSTEM_MESSAGE',
    'ESCROW_CONNECT',
    'ESCROW_MESSAGE',
    'ESCROW_LOCK',
    'ESCROW_APPROVE',
    'ESCROW_CANCEL',
    'ESCROW_ARBITRATOR',
];

const ZINESH_NOTIFICATIONS_FILE = 'notifications.json';
const ZINESH_NOTIFICATION_RETENTION_DAYS = 90;

function zinesh_notifications_user_file(string $uid): string
{
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $uid);
    return 'notifications/users/' . ($safe !== '' ? $safe : 'unknown') . '.json';
}

function zinesh_notifications_ensure_dir(): void
{
    $dir = zinesh_data_path('notifications/users');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

/** Eski tek dosyayı bir kez kullanıcı dosyalarına böler. */
function zinesh_notifications_migrate_legacy_once(): void
{
    zinesh_notifications_ensure_dir();
    $flag = zinesh_data_path('notifications/.legacy_migrated');
    if (is_readable($flag)) {
        return;
    }

    $legacyPath = zinesh_data_path(ZINESH_NOTIFICATIONS_FILE);
    if (!is_readable($legacyPath)) {
        file_put_contents($flag, date('c') . " empty\n");
        return;
    }

    $legacy = zinesh_json_read(ZINESH_NOTIFICATIONS_FILE);
    $items = is_array($legacy['items'] ?? null) ? $legacy['items'] : [];
    /** @var array<string, array<int, array<string,mixed>>> $byUid */
    $byUid = [];
    foreach ($items as $n) {
        if (!is_array($n)) {
            continue;
        }
        $uid = (string)($n['uid'] ?? '');
        if ($uid === '') {
            continue;
        }
        $byUid[$uid][] = $n;
    }

    foreach ($byUid as $uid => $list) {
        $userFile = zinesh_notifications_user_file($uid);
        if (file_exists(zinesh_data_path($userFile))) {
            continue;
        }
        zinesh_json_write($userFile, [
            'items' => $list,
            'updatedAt' => date('c'),
            'migratedFrom' => ZINESH_NOTIFICATIONS_FILE,
        ]);
    }

    file_put_contents($flag, date('c') . ' items=' . count($items) . ' users=' . count($byUid) . "\n");
}

function zinesh_notifications_migrate_legacy_for_user(string $uid): void
{
    if ($uid === '') {
        return;
    }
    zinesh_notifications_migrate_legacy_once();
}

/** @return array{items:array<int,array<string,mixed>>} */
function zinesh_notifications_read_user_store(string $uid): array
{
    zinesh_notifications_migrate_legacy_for_user($uid);
    $store = zinesh_json_read(zinesh_notifications_user_file($uid));
    $items = is_array($store['items'] ?? null) ? $store['items'] : [];
    return ['items' => $items];
}

function zinesh_notification_prune_old(array &$items, int $days = ZINESH_NOTIFICATION_RETENTION_DAYS): void {
    $cutoff = time() - ($days * 86400);
    $items = array_values(array_filter($items, static function ($n) use ($cutoff) {
        $at = strtotime((string)($n['createdAt'] ?? ''));
        return $at >= $cutoff;
    }));
}

/** 90 günden eski kayıtları dosyadan kalıcı olarak siler. */
function zinesh_notifications_prune_persist(int $days = ZINESH_NOTIFICATION_RETENTION_DAYS): void {
    zinesh_json_atomic(ZINESH_NOTIFICATIONS_FILE, static function (array &$store) use ($days) {
        if (!isset($store['items']) || !is_array($store['items'])) {
            $store['items'] = [];
            return false;
        }
        $before = count($store['items']);
        zinesh_notification_prune_old($store['items'], $days);
        if (count($store['items']) < $before) {
            $store['updatedAt'] = date('c');
            return true;
        }
        return false;
    });
}

/** @return array<string, array{title:string, message:string}> */
function zinesh_notification_templates(): array {
    return [
        'KYC_APPROVED' => [
            'title' => 'KYC onaylandı',
            'message' => 'Kimlik doğrulaman tamamlandı. Kampanya ödülün hesabına yazıldı.',
        ],
        'PASSWORD_CHANGED' => [
            'title' => 'Şifre değiştirildi',
            'message' => 'Hesap şifren güncellendi. Tüm oturumların kapatıldı.',
        ],
        'PASSWORD_RESET' => [
            'title' => 'Şifre sıfırlandı',
            'message' => 'Şifre sıfırlama işlemi tamamlandı. Yeniden giriş yapman gerekiyor.',
        ],
        'REFERRAL_JOINED' => [
            'title' => 'Yeni davet kaydı',
            'message' => 'Davet kodunla yeni bir üye katıldı.',
        ],
        'REFERRAL_COMPLETED' => [
            'title' => 'Davet hedefi tamamlandı',
            'message' => '3 doğrulanmış davet hedefine ulaştın. Ödülün hesabına yazıldı.',
        ],
        'WITHDRAW_APPROVED' => [
            'title' => 'Çekim onaylandı',
            'message' => 'USDT çekim talebin işlendi ve gönderildi.',
        ],
        'WITHDRAW_REJECTED' => [
            'title' => 'Çekim iade edildi',
            'message' => 'Çekim talebin iptal edildi; bakiye hesabına iade edildi.',
        ],
        'CAMPAIGN_REWARD' => [
            'title' => 'Kurucu görev ödülü',
            'message' => 'Platform FIZI kazandın. Biriktirerek ileride rozet ve avantajlar açabilirsin.',
        ],
        'RESEARCH_UNLOCK' => [
            'title' => 'AR-GE rezervi açıldı',
            'message' => 'Aylık AR-GE rezervi kilidi açıldı.',
        ],
        'SYSTEM_MESSAGE' => [
            'title' => 'Sistem bildirimi',
            'message' => 'Zinesh protokolünden bir güncelleme.',
        ],
        'ESCROW_CONNECT' => [
            'title' => 'Yeni görüşme talebi',
            'message' => 'Bir üye sizinle üye numaranız üzerinden bağlantı kurdu.',
        ],
        'ESCROW_MESSAGE' => [
            'title' => 'Yeni emanet mesajı',
            'message' => 'Görüşme odanızda yeni bir mesaj var.',
        ],
        'ESCROW_LOCK' => [
            'title' => 'Emanet kilitlendi',
            'message' => 'Anlaşma onaylandı ve tutar kasada kilitlendi.',
        ],
        'ESCROW_APPROVE' => [
            'title' => 'Tamamlanma onayı',
            'message' => 'Karşı taraf işin tamamlandığını onayladı. Senin onayın bekleniyor.',
        ],
        'ESCROW_CANCEL' => [
            'title' => 'İptal talebi',
            'message' => 'Karşı taraf emanet iptali istedi. Onayın bekleniyor.',
        ],
        'ESCROW_ARBITRATOR' => [
            'title' => 'Hakem incelemesi',
            'message' => 'Emanet için şikayet açıldı. Hakem incelemesi bekleniyor.',
        ],
    ];
}

/**
 * Uygulama akışı sırasında tek bildirim oluşturur.
 *
 * @param array<string,mixed> $meta
 */
function zinesh_notify_user(
    string $uid,
    string $type,
    ?string $title = null,
    ?string $message = null,
    array $meta = []
): ?string {
    if ($uid === '' || !in_array($type, ZINESH_NOTIFICATION_TYPES, true)) {
        return null;
    }

    $templates = zinesh_notification_templates();
    $tpl = $templates[$type] ?? $templates['SYSTEM_MESSAGE'];
    $title = trim((string)($title ?? $tpl['title']));
    $message = trim((string)($message ?? $tpl['message']));

    if ($title === '' || $message === '') {
        return null;
    }

    $id = 'ntf_' . bin2hex(random_bytes(12));
    $entry = [
        'id' => $id,
        'uid' => $uid,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'createdAt' => date('c'),
        'read' => false,
        'meta' => $meta,
    ];

    zinesh_notifications_ensure_dir();
    zinesh_json_atomic(zinesh_notifications_user_file($uid), static function (array &$store) use ($entry) {
        if (!isset($store['items']) || !is_array($store['items'])) {
            $store['items'] = [];
        }
        zinesh_notification_prune_old($store['items']);
        $store['items'][] = $entry;
        $store['updatedAt'] = date('c');
        return true;
    });

    return $id;
}

/** @return array{notifications:array,unreadCount:int,total:int} */
function zinesh_notifications_query(
    string $uid,
    int $days = ZINESH_NOTIFICATION_RETENTION_DAYS,
    int $limit = 50,
    int $offset = 0
): array {
    $items = zinesh_notifications_read_user_store($uid)['items'];
    $cutoff = time() - ($days * 86400);
    $out = [];
    $unread = 0;

    for ($i = count($items) - 1; $i >= 0; $i--) {
        $n = $items[$i];
        if (!is_array($n)) {
            continue;
        }
        $at = strtotime((string)($n['createdAt'] ?? ''));
        if ($at < $cutoff) {
            continue;
        }
        $row = [
            'id' => (string)($n['id'] ?? ''),
            'type' => (string)($n['type'] ?? ''),
            'title' => (string)($n['title'] ?? ''),
            'message' => (string)($n['message'] ?? ''),
            'createdAt' => (string)($n['createdAt'] ?? ''),
            'read' => !empty($n['read']),
            'meta' => is_array($n['meta'] ?? null) ? $n['meta'] : [],
        ];
        if (!$row['read']) {
            $unread++;
        }
        $out[] = $row;
    }

    $total = count($out);
    $offset = max(0, $offset);
    $limit = max(1, min(100, $limit));

    return [
        'notifications' => array_slice($out, $offset, $limit),
        'unreadCount' => $unread,
        'total' => $total,
    ];
}

/** @return array<int, array<string,mixed>> */
function zinesh_notifications_for_user(string $uid, int $days = ZINESH_NOTIFICATION_RETENTION_DAYS): array
{
    return zinesh_notifications_query($uid, $days, 100)['notifications'];
}

function zinesh_notifications_unread_count(string $uid, int $days = ZINESH_NOTIFICATION_RETENTION_DAYS): int
{
    return zinesh_notifications_query($uid, $days, 1)['unreadCount'];
}

function zinesh_notifications_mark_read(string $uid, string $id): bool {
    if ($uid === '' || $id === '') {
        return false;
    }
    $ok = false;
    zinesh_notifications_migrate_legacy_for_user($uid);
    zinesh_json_atomic(zinesh_notifications_user_file($uid), static function (array &$store) use ($id, &$ok) {
        if (!isset($store['items']) || !is_array($store['items'])) {
            return false;
        }
        foreach ($store['items'] as &$n) {
            if (($n['id'] ?? '') === $id) {
                $n['read'] = true;
                $n['readAt'] = date('c');
                $ok = true;
                break;
            }
        }
        if ($ok) {
            $store['updatedAt'] = date('c');
        }
        return $ok;
    });
    return $ok;
}

function zinesh_notifications_mark_all_read(string $uid, int $days = ZINESH_NOTIFICATION_RETENTION_DAYS): int {
    if ($uid === '') {
        return 0;
    }
    $cutoff = time() - ($days * 86400);
    $marked = 0;
    zinesh_notifications_migrate_legacy_for_user($uid);
    zinesh_json_atomic(zinesh_notifications_user_file($uid), static function (array &$store) use ($cutoff, &$marked) {
        if (!isset($store['items']) || !is_array($store['items'])) {
            return false;
        }
        foreach ($store['items'] as &$n) {
            if (!empty($n['read'])) {
                continue;
            }
            $at = strtotime((string)($n['createdAt'] ?? ''));
            if ($at < $cutoff) {
                continue;
            }
            $n['read'] = true;
            $n['readAt'] = date('c');
            $marked++;
        }
        if ($marked > 0) {
            $store['updatedAt'] = date('c');
        }
        return true;
    });
    return $marked;
}

function zinesh_notifications_delete(string $uid, string $id): bool {
    if ($uid === '' || $id === '') {
        return false;
    }
    $ok = false;
    zinesh_notifications_migrate_legacy_for_user($uid);
    zinesh_json_atomic(zinesh_notifications_user_file($uid), static function (array &$store) use ($id, &$ok) {
        if (!isset($store['items']) || !is_array($store['items'])) {
            return false;
        }
        $before = count($store['items']);
        $store['items'] = array_values(array_filter(
            $store['items'],
            static fn($n) => ($n['id'] ?? '') !== $id
        ));
        $ok = count($store['items']) < $before;
        if ($ok) {
            $store['updatedAt'] = date('c');
        }
        return $ok;
    });
    return $ok;
}

/** Kullanıcının tüm bildirimlerini siler (kurucu temizliği). */
function zinesh_notifications_clear_user(string $uid): int
{
    if ($uid === '') {
        return 0;
    }
    zinesh_notifications_migrate_legacy_for_user($uid);
    $userFile = zinesh_notifications_user_file($uid);
    $store = zinesh_json_read($userFile);
    $removed = count(is_array($store['items'] ?? null) ? $store['items'] : []);
    if ($removed > 0) {
        zinesh_json_write($userFile, ['items' => [], 'updatedAt' => date('c')]);
    }
    return $removed;
}

function zinesh_notify_founders(string $type, ?string $title = null, ?string $message = null, array $meta = []): void {
    $cfg = zinesh_config();
    $founderUids = $cfg['founder_uids'] ?? [];
    if (is_string($founderUids)) {
        $founderUids = array_filter(array_map('trim', explode(',', $founderUids)));
    }
    if (!is_array($founderUids)) {
        return;
    }
    foreach ($founderUids as $uid) {
        $uid = trim((string)$uid);
        if ($uid !== '') {
            zinesh_notify_user($uid, $type, $title, $message, $meta);
        }
    }
}
