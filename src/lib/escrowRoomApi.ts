import { getSession, getSessionToken } from './auth';
import { bumpSessionActivity } from './sessionIdle';
import { apiUrl } from './apiBase';
import { normalizeMemberTicket } from './memberTicket';
import type { WalletState } from './walletApi';

const API = apiUrl('/api/escrow_room.php');

export type EscrowRoomStatus =
  | 'negotiating'
  | 'terms_pending'
  | 'locking'
  | 'locked'
  | 'completion_pending'
  | 'completed'
  | 'cancelled'
  | 'disputed'
  | 'resolved';

export type EscrowRoomRole = 'employer' | 'worker';

export interface EscrowRoomDispute {
  filedByUid: string;
  reason: string;
  evidence?: string;
  filedAt: string;
  status: string;
  fault?: string;
  adminNote?: string;
  resolvedAt?: string;
  totalFeesTry?: number;
  workerPayoutTry?: number;
  employerRefundTry?: number;
}

export interface EscrowRoom {
  id: string;
  status: EscrowRoomStatus;
  title: string;
  description: string;
  agreedAmountTry: number;
  employerUid: string;
  workerUid: string;
  employerName: string;
  workerName: string;
  employerTicket: string;
  workerTicket: string;
  employerRequestsCollateral: boolean;
  workerRequestsCollateral: boolean;
  collateralActive: boolean;
  collateralAmountTry: number;
  employerLockedTry: number;
  workerLockedTry: number;
  employerConfirmedComplete: boolean;
  workerConfirmedComplete: boolean;
  disputeFeeTry: number;
  myRole: EscrowRoomRole | null;
  createdAt: string;
  lockedAt: string;
  completedAt: string;
  dispute: EscrowRoomDispute | null;
  employerCancelRequested?: boolean;
  workerCancelRequested?: boolean;
}

export interface EscrowRoomMessage {
  id: string;
  roomId: string;
  uid: string;
  name: string;
  body: string;
  type: string;
  meta?: Record<string, unknown>;
  createdAt: string;
}

function token(): string {
  const t = getSessionToken();
  if (!t) throw new Error('Oturum bulunamadı. Lütfen tekrar giriş yapın.');
  return t;
}

async function postRoom(body: Record<string, unknown>): Promise<Record<string, unknown>> {
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
  const raw = await res.text();
  let data: Record<string, unknown> = {};
  try {
    data = raw ? (JSON.parse(raw) as Record<string, unknown>) : {};
  } catch {
    if (!res.ok) {
      throw new Error(
        res.status === 401
          ? 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.'
          : `Sunucu yanıtı okunamadı (HTTP ${res.status}). Sayfayı yenileyip tekrar deneyin.`,
      );
    }
  }

  const serverMessage =
    typeof data.message === 'string' && data.message.trim() ? data.message.trim() : '';

  if (!res.ok) {
    const msg =
      serverMessage ||
      (res.status === 401
        ? 'Oturum süresi dolmuş. Lütfen tekrar giriş yapın.'
        : res.status === 403
          ? 'Bu işlem şu an kullanılamıyor.'
          : res.status === 502
            ? 'Sunucu geçici olarak yanıt veremedi. Sayfayı yenileyip tekrar dene.'
            : `İşlem başarısız (HTTP ${res.status}).`);
    throw new Error(msg);
  }

  if (data.ok === false) {
    throw new Error(serverMessage || 'İşlem başarısız.');
  }

  bumpSessionActivity();
  return data;
}

export async function fetchEscrowRooms(): Promise<EscrowRoom[]> {
  const data = await postRoom({ action: 'list' });
  return (data.rooms as EscrowRoom[]) ?? [];
}

export async function connectEscrowRoom(
  peerTicket: string,
  myRole: EscrowRoomRole,
): Promise<EscrowRoom> {
  const data = await postRoom({
    action: 'connect',
    peerTicket: normalizeMemberTicket(peerTicket),
    myRole,
  });
  if (!data.room) throw new Error('Oda oluşturulamadı.');
  return data.room as EscrowRoom;
}

export async function fetchEscrowRoomDetail(
  roomId: string,
): Promise<{ room: EscrowRoom; messages: EscrowRoomMessage[] }> {
  const data = await postRoom({ action: 'detail', roomId });
  if (!data.room) throw new Error('Oda bulunamadı.');
  return {
    room: data.room as EscrowRoom,
    messages: (data.messages as EscrowRoomMessage[]) ?? [],
  };
}

export async function sendEscrowRoomMessage(
  roomId: string,
  body: string,
): Promise<{ room: EscrowRoom; messages: EscrowRoomMessage[] }> {
  const data = await postRoom({ action: 'send_message', roomId, body });
  return {
    room: data.room as EscrowRoom,
    messages: (data.messages as EscrowRoomMessage[]) ?? [],
  };
}

export async function proposeEscrowTerms(
  roomId: string,
  amountTry: number,
  title: string,
  description: string,
  requestCollateral: boolean,
): Promise<EscrowRoom> {
  const data = await postRoom({
    action: 'propose_terms',
    roomId,
    amountTry,
    title,
    description,
    requestCollateral,
  });
  if (!data.room) throw new Error('Teklif kaydedilemedi.');
  return data.room as EscrowRoom;
}

export async function acceptEscrowTerms(
  roomId: string,
  workerRequestsCollateral: boolean,
): Promise<{ room: EscrowRoom; wallet?: WalletState }> {
  const data = await postRoom({
    action: 'accept_terms',
    roomId,
    workerRequestsCollateral,
  });
  if (!data.room) throw new Error('Anlaşma kilitlenemedi.');
  return {
    room: data.room as EscrowRoom,
    wallet: data.wallet as WalletState | undefined,
  };
}

export async function confirmEscrowComplete(
  roomId: string,
): Promise<{ room: EscrowRoom; wallet?: WalletState; message?: string }> {
  const data = await postRoom({ action: 'confirm_complete', roomId });
  if (!data.room) throw new Error('Onay alınamadı.');
  return {
    room: data.room as EscrowRoom,
    wallet: data.wallet as WalletState | undefined,
    message: data.message as string | undefined,
  };
}

export async function fileEscrowDispute(
  roomId: string,
  reason: string,
  evidence = '',
): Promise<EscrowRoom> {
  const data = await postRoom({ action: 'file_dispute', roomId, reason, evidence });
  if (!data.room) throw new Error('Şikayet açılamadı.');
  return data.room as EscrowRoom;
}

export async function requestEscrowRoomCancel(roomId: string): Promise<{ room: EscrowRoom; wallet?: WalletState }> {
  const data = await postRoom({ action: 'request_cancel', roomId });
  if (!data.room) throw new Error('İptal talebi alınamadı.');
  return {
    room: data.room as EscrowRoom,
    wallet: data.wallet as WalletState | undefined,
  };
}

export function escrowRoomStatusLabel(status: EscrowRoomStatus, role?: EscrowRoomRole | null): string {
  if (status === 'terms_pending') {
    if (role === 'worker') return 'Onayınız bekleniyor';
    if (role === 'employer') return 'İş alan onayı bekleniyor';
    return 'Teklif aşamasında';
  }
  const map: Record<EscrowRoomStatus, string> = {
    negotiating: 'Görüşmede',
    terms_pending: 'Teklif aşamasında',
    locking: 'Kilitleniyor…',
    locked: 'Kilitli — iş devam ediyor',
    completion_pending: 'Tamamlama onayı',
    completed: 'Tamamlandı',
    cancelled: 'İptal edildi',
    disputed: 'Şikayet açık',
    resolved: 'Karara bağlandı',
  };
  return map[status] ?? status;
}

/** Kullanıcının yapması gereken bir sonraki adım */
export function escrowRoomNextAction(room: EscrowRoom): {
  hint: string;
  cta?: string;
  urgent: boolean;
} | null {
  const role = room.myRole;
  if (!role) return null;

  if (room.status === 'negotiating' && role === 'employer') {
    return { hint: 'Tutar ve iş detayını girip teklif gönderin.', cta: 'Teklif ver', urgent: true };
  }
  if (room.status === 'negotiating' && role === 'worker') {
    return { hint: 'İş veren teklif gönderecek.', urgent: false };
  }
  if (room.status === 'terms_pending' && role === 'worker' && room.agreedAmountTry > 0) {
    return { hint: `${room.agreedAmountTry} TL teklif geldi — onaylayınca iş başlar.`, cta: 'Teklifi onayla', urgent: true };
  }
  if (room.status === 'terms_pending' && role === 'employer') {
    return { hint: 'İş alanın teklifi onaylaması bekleniyor.', urgent: false };
  }
  if (['locked', 'completion_pending'].includes(room.status)) {
    const needConfirm =
      (role === 'employer' && !room.employerConfirmedComplete) ||
      (role === 'worker' && !room.workerConfirmedComplete);
    if (needConfirm) {
      return { hint: 'İş bittiğinde her iki taraf da onaylamalı.', cta: 'İş tamamlandı', urgent: true };
    }
    const myCancel =
      (role === 'employer' && room.employerCancelRequested) ||
      (role === 'worker' && room.workerCancelRequested);
    const peerCancel =
      (role === 'employer' && room.workerCancelRequested) ||
      (role === 'worker' && room.employerCancelRequested);
    if (myCancel && !peerCancel) {
      return { hint: 'İptal talebiniz gönderildi — karşı taraf onaylıyor.', urgent: false };
    }
    return { hint: 'Karşı tarafın onayı bekleniyor.', urgent: false };
  }
  return null;
}

export function isEscrowRoomActive(status: EscrowRoomStatus): boolean {
  return ['negotiating', 'terms_pending', 'locked', 'completion_pending', 'disputed'].includes(status);
}
