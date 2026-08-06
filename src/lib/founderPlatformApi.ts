import { getSessionToken } from './auth';
import { apiUrl } from './apiBase';

const FOUNDER_PLATFORM_API = apiUrl('/api/founder_platform.php');

export interface FounderPlatformStats {
  updatedAt: string;
  tlMode: boolean;
  users: {
    total: number;
    activeSessions: number;
    emailVerified: number;
    kycApproved: number;
    foundingMembers: number;
  };
  balances: {
    totalDeposits: number;
    totalEscrowLocked: number;
    userLiabilities: number;
    availableReserve: number;
  };
  campaign: {
    remaining: number;
    total: number;
  };
}

function authHeaders(sessionTokenOverride?: string | null): Record<string, string> {
  const sessionToken = (sessionTokenOverride ?? getSessionToken())?.trim();
  const headers: Record<string, string> = {};
  if (sessionToken) {
    headers.Authorization = `Bearer ${sessionToken}`;
    headers['X-Session-Token'] = sessionToken;
  }
  return headers;
}

export async function fetchFounderPlatformStats(
  sessionTokenOverride?: string | null,
): Promise<FounderPlatformStats> {
  const sessionToken = (sessionTokenOverride ?? getSessionToken())?.trim();
  const headers = authHeaders(sessionToken);

  const url = sessionToken
    ? `${FOUNDER_PLATFORM_API}?sessionToken=${encodeURIComponent(sessionToken)}`
    : FOUNDER_PLATFORM_API;

  const res = await fetch(url, {
    method: 'GET',
    credentials: 'include',
    headers,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.stats) {
    throw new Error((data.message as string) || 'Platform verisi alınamadı.');
  }
  return data.stats as FounderPlatformStats;
}

export async function founderSetUserBalance(opts: {
  lookup: string;
  amount: number;
  mode: 'add' | 'set';
  note?: string;
  sessionToken?: string | null;
}): Promise<{ message: string; stats?: FounderPlatformStats }> {
  const sessionToken = (opts.sessionToken ?? getSessionToken())?.trim();
  const headers: Record<string, string> = {
    ...authHeaders(sessionToken),
    'Content-Type': 'application/json',
  };

  const res = await fetch(FOUNDER_PLATFORM_API, {
    method: 'POST',
    credentials: 'include',
    headers,
    body: JSON.stringify({
      action: 'set_balance',
      sessionToken,
      lookup: opts.lookup.trim(),
      amount: opts.amount,
      mode: opts.mode,
      note: opts.note?.trim() ?? '',
    }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.success) {
    throw new Error((data.message as string) || 'Bakiye güncellenemedi.');
  }
  return {
    message: (data.message as string) || 'Bakiye güncellendi.',
    stats: data.stats as FounderPlatformStats | undefined,
  };
}
