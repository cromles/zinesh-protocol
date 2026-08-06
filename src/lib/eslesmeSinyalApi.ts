import { getSession, getSessionToken } from './auth';
import { bumpSessionActivity } from './sessionIdle';
import { apiUrl } from './apiBase';

const API = apiUrl('/api/eslesme_sinyal.php');

export type EslesmeSinyalTip =
  | 'para_kilitlendi'
  | 'is_baslatildi'
  | 'is_tamamlandi'
  | 'onay_verildi'
  | 'iptal_istegi'
  | 'hakem_cagirildi';

export type EslesmeSinyalDurum = 'okunmadi' | 'okundu' | 'eylem_bekleniyor';

export type SinyalIconKind = 'lock' | 'approve' | 'cancel' | 'arbitrator';

export type SinyalActionLabel = 'Onayla' | 'İncele' | 'Reddet';

export interface EslesmeSinyal {
  tip: EslesmeSinyalTip;
  gonderen_id: string;
  alici_id: string;
  eslesme_id: string;
  timestamp: string;
  mesaj: string;
  durum: EslesmeSinyalDurum;
}

const EYLEM_TIPLERI: EslesmeSinyalTip[] = ['is_tamamlandi', 'iptal_istegi'];

function token(): string {
  const t = getSessionToken();
  if (!t) throw new Error('Oturum bulunamadı. Lütfen tekrar giriş yapın.');
  return t;
}

async function postSinyal(body: Record<string, unknown>): Promise<Record<string, unknown>> {
  const session = getSession();
  const payload = {
    ...body,
    sessionToken: token(),
    uid: session?.uid ?? '',
    email: session?.email ?? '',
  };
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
  } catch {
    throw new Error('Sunucuya bağlanılamadı.');
  }
  const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  if (!res.ok) {
    throw new Error(
      (typeof data.message === 'string' && data.message) || 'İşlem başarısız.',
    );
  }
  bumpSessionActivity();
  return data;
}

export async function fetchEslesmeSinyaller(
  eslesmeId?: string,
  limit = 10,
  offset = 0,
): Promise<{
  sinyaller: EslesmeSinyal[];
  okunmamis: number;
  total: number;
}> {
  const data = await postSinyal({
    action: 'list',
    limit,
    offset,
    ...(eslesmeId ? { eslesme_id: eslesmeId } : {}),
  });
  return {
    sinyaller: (data.sinyaller as EslesmeSinyal[]) ?? [],
    okunmamis: Number(data.okunmamis ?? 0),
    total: Number(data.total ?? ((data.sinyaller as EslesmeSinyal[]) ?? []).length),
  };
}

export async function fetchEslesmeSinyalOkunmamis(eslesmeId?: string): Promise<number> {
  const data = await postSinyal({
    action: 'okunmamis_sayisi',
    ...(eslesmeId ? { eslesme_id: eslesmeId } : {}),
  });
  return Number(data.okunmamis ?? 0);
}

export async function markEslesmeSinyalOkundu(sinyal: EslesmeSinyal): Promise<number> {
  const data = await postSinyal({
    action: 'okundu',
    tip: sinyal.tip,
    eslesme_id: sinyal.eslesme_id,
    timestamp: sinyal.timestamp,
  });
  return Number(data.okunmamis ?? 0);
}

export async function markAllEslesmeSinyalOkundu(): Promise<number> {
  const data = await postSinyal({ action: 'tumunu_okundu' });
  return Number(data.okunmamis ?? 0);
}

/** Arşiv: tamamlanmış eylemler + onay_verildi (okundu). */
export function isEslesmeSinyalArsiv(s: EslesmeSinyal): boolean {
  if (s.tip === 'onay_verildi' && s.durum === 'okundu') return true;
  if (EYLEM_TIPLERI.includes(s.tip) && s.durum === 'okundu') return true;
  return false;
}

export function eslesmeSinyalKey(s: EslesmeSinyal): string {
  return `${s.eslesme_id}|${s.tip}|${s.timestamp}|${s.alici_id}`;
}

export function formatEslesmeSinyalTarih(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleString('tr-TR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/** Göreceli tarih: "2 dakika önce", "1 saat önce" */
export function formatEslesmeSinyalRelative(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  const diffSec = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
  if (diffSec < 45) return 'az önce';
  const mins = Math.floor(diffSec / 60);
  if (mins < 60) return mins === 1 ? '1 dakika önce' : `${mins} dakika önce`;
  const hours = Math.floor(mins / 60);
  if (hours < 24) return hours === 1 ? '1 saat önce' : `${hours} saat önce`;
  const days = Math.floor(hours / 24);
  if (days < 30) return days === 1 ? '1 gün önce' : `${days} gün önce`;
  return formatEslesmeSinyalTarih(iso);
}

export function eslesmeSinyalIconKind(tip: EslesmeSinyalTip): SinyalIconKind {
  if (tip === 'para_kilitlendi' || tip === 'is_baslatildi') return 'lock';
  if (tip === 'iptal_istegi') return 'cancel';
  if (tip === 'hakem_cagirildi') return 'arbitrator';
  return 'approve';
}

export function eslesmeSinyalEylemLabel(tip: EslesmeSinyalTip): string | null {
  if (tip === 'is_tamamlandi') return 'Onayla';
  if (tip === 'iptal_istegi') return 'İptali onayla';
  return null;
}

export function eslesmeSinyalActions(s: EslesmeSinyal): SinyalActionLabel[] {
  if (s.durum === 'eylem_bekleniyor' && s.tip === 'is_tamamlandi') {
    return ['Onayla', 'İncele'];
  }
  if (s.durum === 'eylem_bekleniyor' && s.tip === 'iptal_istegi') {
    return ['Onayla', 'Reddet', 'İncele'];
  }
  if (s.eslesme_id) {
    return ['İncele'];
  }
  return [];
}
