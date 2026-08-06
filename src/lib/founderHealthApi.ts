import { getSessionToken } from './auth';
import { apiUrl } from './apiBase';

export type FileHealthStatus = 'green' | 'yellow' | 'red';

export interface FounderFileHealth {
  file: string;
  status: FileHealthStatus;
  detail: string;
  label: string;
}

export interface FounderHealthCheck {
  id: string;
  name: string;
  status: FileHealthStatus;
  label: string;
}

export interface FounderOverallHealth {
  status: FileHealthStatus;
  statusLabel: string;
  passedChecks: number;
  totalChecks: number;
  checkedAt: string;
  checks: FounderHealthCheck[];
}

export interface FounderSystemHealth {
  updatedAt: string;
  checkedAt?: string;
  overall?: FounderOverallHealth;
  files: FounderFileHealth[];
  backup: {
    lastSuccessLabel?: string | null;
    display: string;
    source: string;
    unavailable: boolean;
  };
  disasterRecovery: {
    provider: string;
    connected: boolean;
    label: string;
  };
  server: {
    status: 'green';
    label: string;
    uptimeSeconds?: number | null;
    uptimeLabel?: string | null;
    diskUsedPct?: number | null;
    diskFreeGb?: number | null;
    diskDisplay?: string;
    diskSubDisplay?: string;
    memoryUsedPct?: number | null;
    memoryUsedMb?: number | null;
    memoryTotalMb?: number | null;
    memoryDisplay?: string;
    memorySubDisplay?: string;
  };
  error?: string;
}

const HEALTH_API = apiUrl('/api/founder_health.php');

export async function fetchFounderSystemHealth(
  sessionTokenOverride?: string | null,
): Promise<FounderSystemHealth> {
  const sessionToken = (sessionTokenOverride ?? getSessionToken())?.trim();
  const headers: Record<string, string> = {};
  if (sessionToken) {
    headers['Authorization'] = `Bearer ${sessionToken}`;
    headers['X-Session-Token'] = sessionToken;
  }

  const url = sessionToken
    ? `${HEALTH_API}?sessionToken=${encodeURIComponent(sessionToken)}`
    : HEALTH_API;

  const res = await fetch(url, {
    method: 'GET',
    credentials: 'include',
    headers,
  });
  const data = await res.json().catch(() => ({}));
  if (data.health) {
    return data.health as FounderSystemHealth;
  }
  throw new Error((data.message as string) || 'Sistem sağlığı alınamadı.');
}

export function fileHealthLabel(item: FounderFileHealth): string {
  if (item.label?.trim()) return item.label;
  const map: Record<string, string> = {
    ok: 'Sağlıklı',
    'readable only': 'Yazma izni yok',
    missing: 'Dosya bulunamadı',
    'invalid json': 'Geçersiz JSON',
    'permission denied': 'Okuma izni yok',
  };
  return map[item.detail] ?? item.detail ?? 'Bilinmiyor';
}
