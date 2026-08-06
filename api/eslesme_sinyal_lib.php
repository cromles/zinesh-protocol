<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

const ZINESH_ESLESME_SINYAL_FILE = 'eslesme_sinyaller.json';

const ZINESH_ESLESME_SINYAL_TIPLERI = [
    'para_kilitlendi',
    'is_baslatildi',
    'is_tamamlandi',
    'onay_verildi',
    'iptal_istegi',
    'hakem_cagirildi',
];

const ZINESH_ESLESME_SINYAL_DURUMLARI = [
    'okunmadi',
    'okundu',
    'eylem_bekleniyor',
];

/** Önceden tanımlı mesaj şablonları — kullanıcı yazmaz. */
const ZINESH_ESLESME_SINYAL_MESAJLARI = [
    'para_kilitlendi' => 'Para kilitlendi. İşe başlanabilir.',
    'is_baslatildi' => 'İş başlatıldı.',
    'is_tamamlandi' => 'İş tamamlandı. Onayınız bekleniyor.',
    'onay_verildi' => 'Onay verildi. Para serbest bırakıldı.',
    'iptal_istegi' => 'Karşı taraf iptal istedi. Onayınız bekleniyor.',
    'hakem_cagirildi' => 'Hakem çağrıldı.',
];

/** Eylem bekleyen sinyal türleri. */
const ZINESH_ESLESME_SINYAL_EYLEM_TIPLERI = [
    'is_tamamlandi',
    'iptal_istegi',
];

/** @return list<array<string,mixed>> */
function zinesh_eslesme_sinyaller_load(): array
{
    $rows = zinesh_json_read(ZINESH_ESLESME_SINYAL_FILE);
    return is_array($rows) ? array_values($rows) : [];
}

/** @param list<array<string,mixed>> $rows */
function zinesh_eslesme_sinyaller_save(array $rows): void
{
    zinesh_json_write(ZINESH_ESLESME_SINYAL_FILE, array_values($rows));
}

function zinesh_eslesme_sinyal_mesaj(string $tip): string
{
    return ZINESH_ESLESME_SINYAL_MESAJLARI[$tip] ?? '';
}

/**
 * Sinyal kaydı — yalnızca zorunlu alanlar.
 *
 * @return array{tip:string,gonderen_id:string,alici_id:string,eslesme_id:string,timestamp:string,mesaj:string,durum:string}|null
 */
function zinesh_eslesme_sinyal_olustur(
    string $tip,
    string $gonderenId,
    string $aliciId,
    string $eslesmeId,
    ?string $durum = null
): ?array {
    if (!in_array($tip, ZINESH_ESLESME_SINYAL_TIPLERI, true)) {
        return null;
    }
    $gonderenId = trim($gonderenId);
    $aliciId = trim($aliciId);
    $eslesmeId = trim($eslesmeId);
    if ($aliciId === '' || $eslesmeId === '') {
        return null;
    }

    if ($durum === null) {
        $durum = in_array($tip, ZINESH_ESLESME_SINYAL_EYLEM_TIPLERI, true)
            ? 'eylem_bekleniyor'
            : 'okunmadi';
    }
    if (!in_array($durum, ZINESH_ESLESME_SINYAL_DURUMLARI, true)) {
        return null;
    }

    $sinyal = [
        'tip' => $tip,
        'gonderen_id' => $gonderenId !== '' ? $gonderenId : 'system',
        'alici_id' => $aliciId,
        'eslesme_id' => $eslesmeId,
        'timestamp' => (new DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP'),
        'mesaj' => zinesh_eslesme_sinyal_mesaj($tip),
        'durum' => $durum,
    ];

    zinesh_json_atomic(ZINESH_ESLESME_SINYAL_FILE, static function (array &$rows) use ($sinyal) {
        if (!is_array($rows)) {
            $rows = [];
        }
        $rows[] = $sinyal;
        if (count($rows) > 5000) {
            $rows = array_slice($rows, -5000);
        }
        return true;
    });

    return $sinyal;
}

/**
 * Odadaki iki tarafa sinyal (yalnızca oda katılımcıları).
 *
 * @param array<string,mixed> $room
 * @return list<array<string,mixed>>
 */
function zinesh_eslesme_sinyal_oda_taraflarina(array $room, string $tip, string $gonderenId): array
{
    $eslesmeId = (string)($room['id'] ?? '');
    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    $out = [];
    foreach ([$employerUid, $workerUid] as $aliciId) {
        if ($aliciId === '') {
            continue;
        }
        $s = zinesh_eslesme_sinyal_olustur($tip, $gonderenId, $aliciId, $eslesmeId);
        if ($s !== null) {
            $out[] = $s;
        }
    }
    return $out;
}

/**
 * Tek alıcıya sinyal — alıcı odanın katılımcısı olmalı.
 *
 * @param array<string,mixed> $room
 */
function zinesh_eslesme_sinyal_aliciya(array $room, string $tip, string $gonderenId, string $aliciId): ?array
{
    $eslesmeId = (string)($room['id'] ?? '');
    $employerUid = (string)($room['employerUid'] ?? '');
    $workerUid = (string)($room['workerUid'] ?? '');
    if ($aliciId !== $employerUid && $aliciId !== $workerUid) {
        return null;
    }
    return zinesh_eslesme_sinyal_olustur($tip, $gonderenId, $aliciId, $eslesmeId);
}

/**
 * Kullanıcının aldığı sinyaller (yalnızca alici_id eşleşenler).
 *
 * @return list<array{tip:string,gonderen_id:string,alici_id:string,eslesme_id:string,timestamp:string,mesaj:string,durum:string}>
 */
function zinesh_eslesme_sinyaller_for_user(string $uid, ?string $eslesmeId = null): array
{
    $uid = trim($uid);
    if ($uid === '') {
        return [];
    }
    $eslesmeId = $eslesmeId !== null ? trim($eslesmeId) : null;
    $out = [];
    foreach (zinesh_eslesme_sinyaller_load() as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string)($row['alici_id'] ?? '') !== $uid) {
            continue;
        }
        if ($eslesmeId !== null && $eslesmeId !== '' && (string)($row['eslesme_id'] ?? '') !== $eslesmeId) {
            continue;
        }
        $tip = (string)($row['tip'] ?? '');
        if (!in_array($tip, ZINESH_ESLESME_SINYAL_TIPLERI, true)) {
            continue;
        }
        $durum = (string)($row['durum'] ?? '');
        if (!in_array($durum, ZINESH_ESLESME_SINYAL_DURUMLARI, true)) {
            continue;
        }
        $out[] = [
            'tip' => $tip,
            'gonderen_id' => (string)($row['gonderen_id'] ?? 'system'),
            'alici_id' => (string)($row['alici_id'] ?? ''),
            'eslesme_id' => (string)($row['eslesme_id'] ?? ''),
            'timestamp' => (string)($row['timestamp'] ?? ''),
            'mesaj' => (string)($row['mesaj'] ?? zinesh_eslesme_sinyal_mesaj($tip)),
            'durum' => $durum,
        ];
    }
    usort($out, static function (array $a, array $b): int {
        return strcmp((string)$b['timestamp'], (string)$a['timestamp']);
    });
    return $out;
}

function zinesh_eslesme_sinyal_okunmamis_sayisi(string $uid, ?string $eslesmeId = null): int
{
    $n = 0;
    foreach (zinesh_eslesme_sinyaller_for_user($uid, $eslesmeId) as $s) {
        if ($s['durum'] === 'okunmadi' || $s['durum'] === 'eylem_bekleniyor') {
            $n++;
        }
    }
    return $n;
}

/**
 * Kullanıcının tüm okunmadi sinyallerini okundu yapar (eylem_bekleniyor dokunulmaz).
 */
function zinesh_eslesme_sinyal_tumunu_okundu(string $uid): int
{
    $uid = trim($uid);
    if ($uid === '') {
        return 0;
    }
    $count = 0;
    zinesh_json_atomic(ZINESH_ESLESME_SINYAL_FILE, static function (array &$rows) use ($uid, &$count) {
        if (!is_array($rows)) {
            $rows = [];
            return false;
        }
        $changed = false;
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['alici_id'] ?? '') !== $uid) {
                continue;
            }
            if ((string)($row['durum'] ?? '') !== 'okunmadi') {
                continue;
            }
            $rows[$i]['durum'] = 'okundu';
            $count++;
            $changed = true;
        }
        return $changed;
    });
    return $count;
}

/**
 * okunmadi → okundu. eylem_bekleniyor değişmez.
 */
function zinesh_eslesme_sinyal_okundu_isaretle(
    string $uid,
    string $tip,
    string $eslesmeId,
    string $timestamp
): bool {
    $uid = trim($uid);
    $tip = trim($tip);
    $eslesmeId = trim($eslesmeId);
    $timestamp = trim($timestamp);
    if ($uid === '' || $tip === '' || $eslesmeId === '' || $timestamp === '') {
        return false;
    }

    $found = false;
    zinesh_json_atomic(ZINESH_ESLESME_SINYAL_FILE, static function (array &$rows) use (
        $uid,
        $tip,
        $eslesmeId,
        $timestamp,
        &$found
    ) {
        if (!is_array($rows)) {
            $rows = [];
            return false;
        }
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['alici_id'] ?? '') !== $uid) {
                continue;
            }
            if ((string)($row['tip'] ?? '') !== $tip) {
                continue;
            }
            if ((string)($row['eslesme_id'] ?? '') !== $eslesmeId) {
                continue;
            }
            if ((string)($row['timestamp'] ?? '') !== $timestamp) {
                continue;
            }
            if ((string)($row['durum'] ?? '') === 'okunmadi') {
                $rows[$i]['durum'] = 'okundu';
                $found = true;
            } elseif ((string)($row['durum'] ?? '') === 'okundu') {
                $found = true;
            }
            return $found;
        }
        return false;
    });

    return $found;
}

/**
 * Eylem bekleyen sinyalleri tamamla → okundu (arşiv).
 */
function zinesh_eslesme_sinyal_eylem_tamamla(string $eslesmeId, string $tip, ?string $aliciId = null): int
{
    $eslesmeId = trim($eslesmeId);
    $tip = trim($tip);
    if ($eslesmeId === '' || $tip === '') {
        return 0;
    }
    $count = 0;
    zinesh_json_atomic(ZINESH_ESLESME_SINYAL_FILE, static function (array &$rows) use (
        $eslesmeId,
        $tip,
        $aliciId,
        &$count
    ) {
        if (!is_array($rows)) {
            $rows = [];
            return false;
        }
        $changed = false;
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string)($row['eslesme_id'] ?? '') !== $eslesmeId) {
                continue;
            }
            if ((string)($row['tip'] ?? '') !== $tip) {
                continue;
            }
            if ((string)($row['durum'] ?? '') !== 'eylem_bekleniyor') {
                continue;
            }
            if ($aliciId !== null && $aliciId !== '' && (string)($row['alici_id'] ?? '') !== $aliciId) {
                continue;
            }
            $rows[$i]['durum'] = 'okundu';
            $count++;
            $changed = true;
        }
        return $changed;
    });
    return $count;
}
