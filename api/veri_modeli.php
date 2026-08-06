<?php
declare(strict_types=1);

/**
 * Zinesh bütüncül veri modeli.
 *
 * Temel kural: her veri parçası bir diğerine bağlıdır — yalnız tablo yok.
 * Bu dosya tek kaynak: varlıklar, durumlar, ilişkiler. Yeni tablo/alan/ilişki yok.
 */

/** Eslesme.durum — başka durum yok. */
const ZINESH_ESLESME_DURUMLARI = [
    'beklemede',
    'para_kilitli',
    'is_devam_ediyor',
    'tamamlandi',
    'iptal_edildi',
    'hakemde',
];

/** Islem.durum — başka durum yok. */
const ZINESH_ISLEM_DURUMLARI = [
    'beklemede',
    'basarili',
    'basarisiz',
    'iade_edildi',
];

/** Sinyal.tip — başka tür yok. */
const ZINESH_SINYAL_TIPLERI = [
    'para_kilitlendi',
    'is_baslatildi',
    'is_tamamlandi',
    'onay_verildi',
    'iptal_istegi',
    'hakem_cagirildi',
];

/** Sinyal.durum — başka durum yok. */
const ZINESH_SINYAL_DURUMLARI = [
    'okunmadi',
    'okundu',
    'eylem_bekleniyor',
];

/**
 * İlişki grafiği (yalnız bu bağlar):
 *
 *   Kullanici 1──1 Profil
 *   Kullanici 1──1 Hesap
 *   Kullanici 1──N Eslesme  (hizmet_alan | hizmet_saglayici)
 *   Eslesme   1──N Islem
 *   Eslesme   1──N Sinyal
 *   Eslesme   1──0..1 HakemKarari
 */
function zinesh_veri_iliski_kurallari(): array
{
    return [
        'Kullanici->Profil' => '1-1',
        'Kullanici->Hesap' => '1-1',
        'Kullanici->Eslesme' => '1-N',
        'Eslesme->Islem' => '1-N',
        'Eslesme->Sinyal' => '1-N',
        'Eslesme->HakemKarari' => '1-0..1',
    ];
}

function zinesh_kullanici_olustur(
    string $id,
    string $email,
    string $telefon,
    string $dogrulama_durumu,
    string $olusturulma_tarihi
): array {
    return [
        'id' => $id,
        'email' => $email,
        'telefon' => $telefon,
        'dogrulama_durumu' => $dogrulama_durumu,
        'olusturulma_tarihi' => $olusturulma_tarihi,
    ];
}

function zinesh_profil_olustur(
    string $kullanici_id,
    string $gercek_adi,
    string $sektor,
    float $puan,
    int $referans_sayisi
): array {
    return [
        'kullanici_id' => $kullanici_id,
        'gercek_adi' => $gercek_adi,
        'sektor' => $sektor,
        'puan' => $puan,
        'referans_sayisi' => $referans_sayisi,
    ];
}

function zinesh_hesap_olustur(
    string $kullanici_id,
    float $bakiye_tl,
    float $bloke_tl
): array {
    return [
        'kullanici_id' => $kullanici_id,
        'bakiye_tl' => $bakiye_tl,
        'bloke_tl' => $bloke_tl,
        'para_birimi_sadece_tl' => true,
    ];
}

function zinesh_eslesme_olustur(
    string $id,
    string $hizmet_alan_id,
    string $hizmet_saglayici_id,
    float $tutar_tl,
    string $aciklama,
    string $durum,
    string $olusturma_tarihi
): array {
    if (!in_array($durum, ZINESH_ESLESME_DURUMLARI, true)) {
        throw new InvalidArgumentException('Geçersiz Eslesme.durum: ' . $durum);
    }
    return [
        'id' => $id,
        'hizmet_alan_id' => $hizmet_alan_id,
        'hizmet_saglayici_id' => $hizmet_saglayici_id,
        'tutar_tl' => $tutar_tl,
        'aciklama' => $aciklama,
        'durum' => $durum,
        'olusturma_tarihi' => $olusturma_tarihi,
    ];
}

function zinesh_islem_olustur(
    string $id,
    string $eslesme_id,
    string $tip,
    float $tutar,
    string $yon,
    string $durum,
    string $timestamp
): array {
    if (!in_array($durum, ZINESH_ISLEM_DURUMLARI, true)) {
        throw new InvalidArgumentException('Geçersiz Islem.durum: ' . $durum);
    }
    return [
        'id' => $id,
        'eslesme_id' => $eslesme_id,
        'tip' => $tip,
        'tutar' => $tutar,
        'yon' => $yon,
        'durum' => $durum,
        'timestamp' => $timestamp,
    ];
}

function zinesh_sinyal_olustur(
    string $tip,
    string $gonderen_id,
    string $alici_id,
    string $eslesme_id,
    string $timestamp,
    string $mesaj,
    string $durum
): array {
    if (!in_array($tip, ZINESH_SINYAL_TIPLERI, true)) {
        throw new InvalidArgumentException('Geçersiz Sinyal.tip: ' . $tip);
    }
    if (!in_array($durum, ZINESH_SINYAL_DURUMLARI, true)) {
        throw new InvalidArgumentException('Geçersiz Sinyal.durum: ' . $durum);
    }
    return [
        'tip' => $tip,
        'gonderen_id' => $gonderen_id,
        'alici_id' => $alici_id,
        'eslesme_id' => $eslesme_id,
        'timestamp' => $timestamp,
        'mesaj' => $mesaj,
        'durum' => $durum,
    ];
}

function zinesh_hakem_karari_olustur(
    string $id,
    string $eslesme_id,
    string $hakem_id,
    string $karar,
    string $gerekce,
    string $tarih
): array {
    return [
        'id' => $id,
        'eslesme_id' => $eslesme_id,
        'hakem_id' => $hakem_id,
        'karar' => $karar,
        'gerekce' => $gerekce,
        'tarih' => $tarih,
    ];
}

/**
 * Kullanici düğümü: Profil + Hesap zorunlu bağlı (1-1).
 * Eslesmeler dışarıdan bağlanır; yalnız id listesi tutulmaz — grafikte referans.
 */
function zinesh_kullanici_dugumu(array $kullanici, array $profil, array $hesap): array
{
    if (($profil['kullanici_id'] ?? null) !== ($kullanici['id'] ?? null)) {
        throw new InvalidArgumentException('Profil.kullanici_id Kullanici.id ile eşleşmeli');
    }
    if (($hesap['kullanici_id'] ?? null) !== ($kullanici['id'] ?? null)) {
        throw new InvalidArgumentException('Hesap.kullanici_id Kullanici.id ile eşleşmeli');
    }
    if (($hesap['para_birimi_sadece_tl'] ?? null) !== true) {
        throw new InvalidArgumentException('Hesap.para_birimi_sadece_tl yalnızca true olabilir');
    }

    return [
        'kullanici' => $kullanici,
        'profil' => $profil,
        'hesap' => $hesap,
    ];
}

/**
 * Eslesme düğümü: Islem[] + Sinyal[] + en fazla bir HakemKarari.
 * Tüm çocuklar eslesme_id ile bu eşleşmeye bağlıdır.
 */
function zinesh_eslesme_dugumu(
    array $eslesme,
    array $islemler = [],
    array $sinyaller = [],
    ?array $hakem_karari = null
): array {
    $eslesmeId = (string)($eslesme['id'] ?? '');
    if ($eslesmeId === '') {
        throw new InvalidArgumentException('Eslesme.id zorunlu');
    }
    if (!in_array($eslesme['durum'] ?? '', ZINESH_ESLESME_DURUMLARI, true)) {
        throw new InvalidArgumentException('Geçersiz Eslesme.durum');
    }

    foreach ($islemler as $i => $islem) {
        if (!is_array($islem) || ($islem['eslesme_id'] ?? null) !== $eslesmeId) {
            throw new InvalidArgumentException("Islem[$i].eslesme_id Eslesme.id ile eşleşmeli");
        }
        if (!in_array($islem['durum'] ?? '', ZINESH_ISLEM_DURUMLARI, true)) {
            throw new InvalidArgumentException("Islem[$i].durum geçersiz");
        }
    }

    foreach ($sinyaller as $i => $sinyal) {
        if (!is_array($sinyal) || ($sinyal['eslesme_id'] ?? null) !== $eslesmeId) {
            throw new InvalidArgumentException("Sinyal[$i].eslesme_id Eslesme.id ile eşleşmeli");
        }
        if (!in_array($sinyal['tip'] ?? '', ZINESH_SINYAL_TIPLERI, true)) {
            throw new InvalidArgumentException("Sinyal[$i].tip geçersiz");
        }
        if (!in_array($sinyal['durum'] ?? '', ZINESH_SINYAL_DURUMLARI, true)) {
            throw new InvalidArgumentException("Sinyal[$i].durum geçersiz");
        }
        $taraflar = [
            (string)($eslesme['hizmet_alan_id'] ?? ''),
            (string)($eslesme['hizmet_saglayici_id'] ?? ''),
        ];
        $alici = (string)($sinyal['alici_id'] ?? '');
        if ($alici !== '' && !in_array($alici, $taraflar, true)) {
            throw new InvalidArgumentException("Sinyal[$i].alici_id eşleşme taraflarından biri olmalı");
        }
    }

    if ($hakem_karari !== null) {
        if (($hakem_karari['eslesme_id'] ?? null) !== $eslesmeId) {
            throw new InvalidArgumentException('HakemKarari.eslesme_id Eslesme.id ile eşleşmeli');
        }
    }

    return [
        'eslesme' => $eslesme,
        'islemler' => array_values($islemler),
        'sinyaller' => array_values($sinyaller),
        'hakem_karari' => $hakem_karari,
    ];
}

/**
 * Bütüncül graf: kullanıcı düğümleri + eşleşme düğümleri, çapraz bağlar doğrulanır.
 *
 * @param array<int, array> $kullaniciDugumleri zinesh_kullanici_dugumu çıktıları
 * @param array<int, array> $eslesmeDugumleri   zinesh_eslesme_dugumu çıktıları
 */
function zinesh_veri_grafi(array $kullaniciDugumleri, array $eslesmeDugumleri): array
{
    $byId = [];
    foreach ($kullaniciDugumleri as $dugum) {
        $id = (string)($dugum['kullanici']['id'] ?? '');
        if ($id === '' || isset($byId[$id])) {
            throw new InvalidArgumentException('Her Kullanici benzersiz id ile grafikte olmalı');
        }
        $byId[$id] = $dugum;
    }

    $eslesmeler = [];
    foreach ($eslesmeDugumleri as $dugum) {
        $e = $dugum['eslesme'];
        $alan = (string)($e['hizmet_alan_id'] ?? '');
        $saglayici = (string)($e['hizmet_saglayici_id'] ?? '');
        if (!isset($byId[$alan]) || !isset($byId[$saglayici])) {
            throw new InvalidArgumentException('Eslesme tarafları grafikteki Kullanici düğümlerine bağlı olmalı');
        }
        if ($alan === $saglayici) {
            throw new InvalidArgumentException('hizmet_alan_id ve hizmet_saglayici_id farklı olmalı');
        }
        $eslesmeler[] = $dugum;
    }

    return [
        'kullanicilar' => array_values($byId),
        'eslesmeler' => $eslesmeler,
        'iliskiler' => zinesh_veri_iliski_kurallari(),
    ];
}

/**
 * Kullanici'nin taraf olduğu eşleşme düğümlerini döner (1-N navigasyon).
 */
function zinesh_kullanici_eslesmeleri(array $graf, string $kullaniciId): array
{
    $out = [];
    foreach ($graf['eslesmeler'] ?? [] as $dugum) {
        $e = $dugum['eslesme'] ?? [];
        if (($e['hizmet_alan_id'] ?? null) === $kullaniciId
            || ($e['hizmet_saglayici_id'] ?? null) === $kullaniciId) {
            $out[] = $dugum;
        }
    }
    return $out;
}
