import { getSessionToken, getSession } from './auth';
import { apiUrl } from './apiBase';

const API = apiUrl('/api/notifications.php');

export class NotificationsHttpError extends Error {
  status: number;
  code?: string;

  constructor(message: string, status: number, code?: string) {
    super(message);
    this.name = 'NotificationsHttpError';
    this.status = status;
    this.code = code;
  }
}

export function isNotificationsUnauthorized(err: unknown): boolean {
  return err instanceof NotificationsHttpError && err.status === 401;
}
const DEFAULT_DAYS = 90;

export type NotificationType =
  | 'KYC_APPROVED'
  | 'PASSWORD_CHANGED'
  | 'PASSWORD_RESET'
  | 'REFERRAL_JOINED'
  | 'REFERRAL_COMPLETED'
  | 'WITHDRAW_APPROVED'
  | 'WITHDRAW_REJECTED'
  | 'CAMPAIGN_REWARD'
  | 'RESEARCH_UNLOCK'
  | 'SYSTEM_MESSAGE';

export interface AppNotification {
  id: string;
  type: NotificationType;
  title: string;
  message: string;
  createdAt: string;
  read: boolean;
  meta?: Record<string, unknown>;
}

async function postNotificationsOnce(
  body: Record<string, string | number>,
  sessionToken: string,
): Promise<Record<string, unknown>> {
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), 20000);
  let res: Response;
  try {
    res = await fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...body, sessionToken }),
      signal: controller.signal,
    });
  } catch (err) {
    if (err instanceof Error && err.name === 'AbortError') {
      throw new Error('Bildirim sunucusu yanıt vermedi (zaman aşımı).');
    }
    throw new Error('Bildirim sunucusuna bağlanılamadı.');
  } finally {
    window.clearTimeout(timer);
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const msg =
      (data.message as string) ||
      (res.status === 429
        ? 'Çok fazla istek. Kısa süre bekleyip tekrar deneyin.'
        : res.status >= 500
          ? 'Sunucu bildirimlere yanıt veremedi.'
          : `Bildirim işlemi başarısız (HTTP ${res.status}).`);
    throw new NotificationsHttpError(
      msg,
      res.status,
      typeof data.code === 'string' ? data.code : undefined,
    );
  }
  if (data.ok === false && !Array.isArray(data.notifications) && typeof data.unreadCount !== 'number') {
    throw new NotificationsHttpError(
      (data.message as string) || 'Bildirim işlemi başarısız.',
      res.status,
    );
  }
  return data;
}

async function postNotifications(body: Record<string, string | number>): Promise<Record<string, unknown>> {
  const session = getSession();
  const sessionToken = getSessionToken();
  if (!sessionToken && !session?.email) {
    throw new Error('Oturum bulunamadı.');
  }
  return postNotificationsOnce(
    {
      ...body,
      uid: session?.uid ?? '',
      email: session?.email ?? '',
    },
    sessionToken ?? '',
  );
}

export async function fetchNotifications(
  days = DEFAULT_DAYS,
  limit = 50,
): Promise<{
  notifications: AppNotification[];
  unreadCount: number;
  total?: number;
}> {
  const data = await postNotifications({ action: 'list', days, limit });
  return {
    notifications: (data.notifications as AppNotification[]) ?? [],
    unreadCount: Number(data.unreadCount ?? 0),
    total: typeof data.total === 'number' ? data.total : undefined,
  };
}

export async function fetchUnreadCount(days = DEFAULT_DAYS): Promise<number> {
  const data = await postNotifications({ action: 'unread_count', days });
  return Number(data.unreadCount ?? 0);
}

export async function markNotificationRead(id: string, days = DEFAULT_DAYS): Promise<number> {
  const data = await postNotifications({ action: 'mark_read', id, days });
  return Number(data.unreadCount ?? 0);
}

export async function markAllNotificationsRead(days = DEFAULT_DAYS): Promise<number> {
  const data = await postNotifications({ action: 'mark_all_read', days });
  return Number(data.unreadCount ?? 0);
}

export async function deleteNotification(id: string, days = DEFAULT_DAYS): Promise<number> {
  const data = await postNotifications({ action: 'delete', id, days });
  return Number(data.unreadCount ?? 0);
}

export async function clearAllNotifications(): Promise<number> {
  const data = await postNotifications({ action: 'clear_all' });
  return Number(data.unreadCount ?? 0);
}

export function formatNotificationDate(iso: string): string {
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
