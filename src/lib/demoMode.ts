import { apiUrl } from './apiBase';

export type DemoRole = 'employer' | 'worker';

export type DemoStatus = {
  demoEnabled: boolean;
  apiOnline: boolean;
  employerTicket?: string;
  workerTicket?: string;
  balanceTry?: number;
};

export type DemoHealthCheck = {
  ok: boolean;
  detail: string;
};

export type DemoHealthReport = {
  ok: boolean;
  demoEnabled: boolean;
  apiOnline: boolean;
  reason?: string;
  checks: {
    api: DemoHealthCheck;
    wallet: DemoHealthCheck;
    room: DemoHealthCheck;
    status: DemoHealthCheck;
    json: DemoHealthCheck;
    escrow: DemoHealthCheck;
  };
  lastError: string | null;
  dataDir?: string;
  at?: string;
};

function offlineHealthReport(reason = 'Demo API unavailable'): DemoHealthReport {
  const unknown = { ok: false, detail: 'UNKNOWN' };
  return {
    ok: false,
    demoEnabled: false,
    apiOnline: false,
    reason,
    checks: {
      api: { ok: false, detail: 'OFFLINE' },
      wallet: unknown,
      room: unknown,
      status: unknown,
      json: unknown,
      escrow: unknown,
    },
    lastError: reason,
  };
}

/** Production dışında demo araçları görünür. */
export function isDemoUiEnabled(): boolean {
  if (import.meta.env.VITE_DEMO_MODE === '1' || import.meta.env.VITE_DEMO_MODE === 'true') {
    return true;
  }
  if (import.meta.env.DEV) {
    return true;
  }
  if (typeof window !== 'undefined') {
    const host = window.location.hostname;
    if (
      host === 'localhost' ||
      host === '127.0.0.1' ||
      host.includes('staging') ||
      host.endsWith('.local')
    ) {
      return true;
    }
  }
  return false;
}

let cachedDemoEnabled: boolean | null = null;

export async function fetchDemoStatus(): Promise<DemoStatus> {
  if (!isDemoUiEnabled()) {
    return { demoEnabled: false, apiOnline: false };
  }
  try {
    const res = await fetch(apiUrl('/api/demo.php?action=status'), {
      method: 'GET',
      headers: { Accept: 'application/json' },
    });
    if (!res.ok) {
      cachedDemoEnabled = false;
      return { demoEnabled: false, apiOnline: false };
    }
    const data = (await res.json()) as Record<string, unknown>;
    const enabled = Boolean(data.demoEnabled);
    cachedDemoEnabled = enabled;
    return {
      demoEnabled: enabled,
      apiOnline: true,
      employerTicket: typeof data.employerTicket === 'string' ? data.employerTicket : undefined,
      workerTicket: typeof data.workerTicket === 'string' ? data.workerTicket : undefined,
      balanceTry: typeof data.balanceTry === 'number' ? data.balanceTry : undefined,
    };
  } catch {
    cachedDemoEnabled = false;
    return { demoEnabled: false, apiOnline: false };
  }
}

export function isDemoModeActive(): boolean {
  return cachedDemoEnabled === true;
}

export async function loginDemoRole(role: DemoRole): Promise<{
  user: Record<string, unknown>;
  wallet?: Record<string, unknown>;
}> {
  const res = await fetch(apiUrl('/api/demo.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action: 'login', role }),
  });
  const data = (await res.json()) as Record<string, unknown>;
  if (!res.ok || data.ok === false) {
    throw new Error(typeof data.message === 'string' ? data.message : 'Demo girişi başarısız.');
  }
  if (!data.user || typeof data.user !== 'object') {
    throw new Error('Demo kullanıcı yanıtı eksik.');
  }
  return {
    user: data.user as Record<string, unknown>,
    wallet: data.wallet as Record<string, unknown> | undefined,
  };
}

export async function resetDemoEscrow(): Promise<void> {
  const res = await fetch(apiUrl('/api/demo.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'reset' }),
  });
  const data = (await res.json()) as Record<string, unknown>;
  if (!res.ok || data.ok === false) {
    throw new Error(typeof data.message === 'string' ? data.message : 'Demo sıfırlama başarısız.');
  }
}

export async function fetchDemoHealth(): Promise<DemoHealthReport> {
  if (!isDemoUiEnabled()) {
    return offlineHealthReport('Demo UI disabled on this host');
  }
  try {
    const res = await fetch(apiUrl('/api/demo.php'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'health' }),
    });
    if (res.status === 404) {
      return offlineHealthReport('Demo mode disabled on server');
    }
    if (!res.ok) {
      return offlineHealthReport(`Demo API HTTP ${res.status}`);
    }
    const data = (await res.json()) as DemoHealthReport;
    return {
      ...data,
      apiOnline: true,
      checks: {
        api: { ok: true, detail: 'ONLINE' },
        wallet: data.checks?.wallet ?? { ok: false, detail: 'UNKNOWN' },
        room: data.checks?.room ?? { ok: false, detail: 'UNKNOWN' },
        status: data.checks?.status ?? { ok: false, detail: 'UNKNOWN' },
        json: data.checks?.json ?? { ok: false, detail: 'UNKNOWN' },
        escrow: data.checks?.escrow ?? { ok: false, detail: 'UNKNOWN' },
      },
    };
  } catch {
    return offlineHealthReport();
  }
}
