/**
 * Zinesh bütüncül veri modeli (TypeScript aynası).
 *
 * Temel kural: her veri parçası bir diğerine bağlıdır — yalnız tablo yok.
 * Kaynak: api/veri_modeli.php — yeni tablo / alan / ilişki yok.
 */

export const ESLESME_DURUMLARI = [
  'beklemede',
  'para_kilitli',
  'is_devam_ediyor',
  'tamamlandi',
  'iptal_edildi',
  'hakemde',
] as const;

export type EslesmeDurum = (typeof ESLESME_DURUMLARI)[number];

export const ISLEM_DURUMLARI = [
  'beklemede',
  'basarili',
  'basarisiz',
  'iade_edildi',
] as const;

export type IslemDurum = (typeof ISLEM_DURUMLARI)[number];

export const SINYAL_TIPLERI = [
  'para_kilitlendi',
  'is_baslatildi',
  'is_tamamlandi',
  'onay_verildi',
  'iptal_istegi',
  'hakem_cagirildi',
] as const;

export type SinyalTip = (typeof SINYAL_TIPLERI)[number];

export const SINYAL_DURUMLARI = [
  'okunmadi',
  'okundu',
  'eylem_bekleniyor',
] as const;

export type SinyalDurum = (typeof SINYAL_DURUMLARI)[number];

/** 1. Kullanici */
export interface Kullanici {
  id: string;
  email: string;
  telefon: string;
  dogrulama_durumu: string;
  olusturulma_tarihi: string;
}

/** 2. Profil — Kullanici 1-1 */
export interface Profil {
  kullanici_id: string;
  gercek_adi: string;
  sektor: string;
  puan: number;
  referans_sayisi: number;
}

/** 3. Hesap — Kullanici 1-1 */
export interface Hesap {
  kullanici_id: string;
  bakiye_tl: number;
  bloke_tl: number;
  para_birimi_sadece_tl: true;
}

/** 4. Eslesme — Kullanici 1-N (alan + sağlayıcı) */
export interface Eslesme {
  id: string;
  hizmet_alan_id: string;
  hizmet_saglayici_id: string;
  tutar_tl: number;
  aciklama: string;
  durum: EslesmeDurum;
  olusturma_tarihi: string;
}

/** 5. Islem — Eslesme 1-N */
export interface Islem {
  id: string;
  eslesme_id: string;
  tip: string;
  tutar: number;
  yon: string;
  durum: IslemDurum;
  timestamp: string;
}

/** 6. Sinyal — Eslesme 1-N */
export interface Sinyal {
  tip: SinyalTip;
  gonderen_id: string;
  alici_id: string;
  eslesme_id: string;
  timestamp: string;
  mesaj: string;
  durum: SinyalDurum;
}

/** 7. HakemKarari — Eslesme 1-0..1 */
export interface HakemKarari {
  id: string;
  eslesme_id: string;
  hakem_id: string;
  karar: string;
  gerekce: string;
  tarih: string;
}

/** Kullanici düğümü: Profil + Hesap zorunlu bağlı */
export interface KullaniciDugumu {
  kullanici: Kullanici;
  profil: Profil;
  hesap: Hesap;
}

/** Eslesme düğümü: Islem[] + Sinyal[] + opsiyonel tek HakemKarari */
export interface EslesmeDugumu {
  eslesme: Eslesme;
  islemler: Islem[];
  sinyaller: Sinyal[];
  hakem_karari: HakemKarari | null;
}

export const VERI_ILISKI_KURALLARI = {
  'Kullanici->Profil': '1-1',
  'Kullanici->Hesap': '1-1',
  'Kullanici->Eslesme': '1-N',
  'Eslesme->Islem': '1-N',
  'Eslesme->Sinyal': '1-N',
  'Eslesme->HakemKarari': '1-0..1',
} as const;

/** Bütüncül graf — parçalar birbirine bağlı */
export interface VeriGrafi {
  kullanicilar: KullaniciDugumu[];
  eslesmeler: EslesmeDugumu[];
  iliskiler: typeof VERI_ILISKI_KURALLARI;
}

export function kullaniciDugumu(
  kullanici: Kullanici,
  profil: Profil,
  hesap: Hesap,
): KullaniciDugumu {
  if (profil.kullanici_id !== kullanici.id) {
    throw new Error('Profil.kullanici_id Kullanici.id ile eşleşmeli');
  }
  if (hesap.kullanici_id !== kullanici.id) {
    throw new Error('Hesap.kullanici_id Kullanici.id ile eşleşmeli');
  }
  if (hesap.para_birimi_sadece_tl !== true) {
    throw new Error('Hesap.para_birimi_sadece_tl yalnızca true olabilir');
  }
  return { kullanici, profil, hesap };
}

export function eslesmeDugumu(
  eslesme: Eslesme,
  islemler: Islem[] = [],
  sinyaller: Sinyal[] = [],
  hakem_karari: HakemKarari | null = null,
): EslesmeDugumu {
  if (!ESLESME_DURUMLARI.includes(eslesme.durum)) {
    throw new Error('Geçersiz Eslesme.durum');
  }
  for (const islem of islemler) {
    if (islem.eslesme_id !== eslesme.id) {
      throw new Error('Islem.eslesme_id Eslesme.id ile eşleşmeli');
    }
    if (!ISLEM_DURUMLARI.includes(islem.durum)) {
      throw new Error('Geçersiz Islem.durum');
    }
  }
  const taraflar = [eslesme.hizmet_alan_id, eslesme.hizmet_saglayici_id];
  for (const sinyal of sinyaller) {
    if (sinyal.eslesme_id !== eslesme.id) {
      throw new Error('Sinyal.eslesme_id Eslesme.id ile eşleşmeli');
    }
    if (!SINYAL_TIPLERI.includes(sinyal.tip)) {
      throw new Error('Geçersiz Sinyal.tip');
    }
    if (!SINYAL_DURUMLARI.includes(sinyal.durum)) {
      throw new Error('Geçersiz Sinyal.durum');
    }
    if (!taraflar.includes(sinyal.alici_id)) {
      throw new Error('Sinyal.alici_id eşleşme taraflarından biri olmalı');
    }
  }
  if (hakem_karari !== null && hakem_karari.eslesme_id !== eslesme.id) {
    throw new Error('HakemKarari.eslesme_id Eslesme.id ile eşleşmeli');
  }
  return {
    eslesme,
    islemler: [...islemler],
    sinyaller: [...sinyaller],
    hakem_karari,
  };
}

export function veriGrafi(
  kullaniciDugumleri: KullaniciDugumu[],
  eslesmeDugumleri: EslesmeDugumu[],
): VeriGrafi {
  const byId = new Map<string, KullaniciDugumu>();
  for (const dugum of kullaniciDugumleri) {
    const id = dugum.kullanici.id;
    if (!id || byId.has(id)) {
      throw new Error('Her Kullanici benzersiz id ile grafikte olmalı');
    }
    byId.set(id, dugum);
  }

  for (const dugum of eslesmeDugumleri) {
    const { hizmet_alan_id, hizmet_saglayici_id } = dugum.eslesme;
    if (!byId.has(hizmet_alan_id) || !byId.has(hizmet_saglayici_id)) {
      throw new Error('Eslesme tarafları grafikteki Kullanici düğümlerine bağlı olmalı');
    }
    if (hizmet_alan_id === hizmet_saglayici_id) {
      throw new Error('hizmet_alan_id ve hizmet_saglayici_id farklı olmalı');
    }
  }

  return {
    kullanicilar: [...byId.values()],
    eslesmeler: [...eslesmeDugumleri],
    iliskiler: VERI_ILISKI_KURALLARI,
  };
}

/** Kullanici → Eslesme 1-N navigasyon */
export function kullaniciEslesmeleri(
  graf: VeriGrafi,
  kullaniciId: string,
): EslesmeDugumu[] {
  return graf.eslesmeler.filter(
    (d) =>
      d.eslesme.hizmet_alan_id === kullaniciId ||
      d.eslesme.hizmet_saglayici_id === kullaniciId,
  );
}
